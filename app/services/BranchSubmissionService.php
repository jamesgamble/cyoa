<?php
/**
 * BranchSubmissionService — branch submissions (v0.18.0).
 *
 * A branch is one choice attached to a published source scene plus the
 * scene that choice leads to. This service is the single authority for
 * accepting one:
 *
 *   • eligibility — the adventure must be readable, contributions must
 *     be enabled, the source scene must be published and unlocked, the
 *     scene must have a free branch slot, and the contributor must not
 *     be blocked;
 *   • identity — the contributor is the authenticated caller or, when
 *     the adventure allows it, an anonymous visitor. A user id in the
 *     request body is never trusted;
 *   • attribution — `username`, `display_name`, and `anonymous` are
 *     PUBLIC display preferences. The user id and the submitting IP are
 *     always recorded, so anonymous public attribution never removes
 *     internal attribution;
 *   • abuse control — honeypot, per-user and per-IP rolling rate
 *     limits, an optional contribution passcode, and duplicate
 *     detection on the normalised choice text;
 *   • sanitisation — the scene body passes through App\HtmlSanitizer
 *     and the derived plain text is stored beside it;
 *   • atomicity — in immediate mode the scene, the choice, the
 *     submission record, the activity record, and the rate-limit row
 *     are written in ONE transaction. Any failure rolls all of it
 *     back, so a choice can never point at a scene that does not
 *     exist.
 *
 * Modes: `immediate` publishes at once, `approval` records a pending
 * submission and publishes nothing, `closed` rejects.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class BranchSubmissionService
{
    public const OK                    = 'ok';
    public const INVALID               = 'invalid';
    public const NOT_FOUND             = 'not_found';
    public const UNAVAILABLE           = 'adventure_unavailable';
    public const CLOSED                = 'contributions_closed';
    public const SOURCE_UNAVAILABLE    = 'source_scene_unavailable';
    public const SCENE_LOCKED          = 'scene_locked';
    public const BRANCH_LIMIT          = 'branch_limit_reached';
    public const BLOCKED               = 'contributor_blocked';
    public const PASSCODE_REQUIRED     = 'passcode_required';
    public const PASSCODE_INVALID      = 'passcode_invalid';
    public const RATE_LIMITED          = 'rate_limited';
    public const HONEYPOT              = 'honeypot';
    public const DUPLICATE             = 'duplicate';
    public const UNAUTHENTICATED       = 'unauthenticated';

    /** States a submission can be in. */
    public const STATE_APPROVED  = 'approved';
    public const STATE_PENDING   = 'pending';
    /** Retained name for the immediately-published outcome. */
    public const STATE_PUBLISHED = self::STATE_APPROVED;
    public const PAUSED          = 'contributions_paused';

    public const ATTRIBUTIONS = ['username', 'display_name', 'anonymous'];
    public const SCENE_TYPES  = ['story', 'ending'];

    /** Adventure states that still accept contributions. */
    private const READABLE_STATES = ['published', 'on-hold', 'complete'];
    /** …and of those, the ones that accept *new* branches. */
    private const CONTRIBUTABLE_STATES = ['published', 'on-hold'];

    public const CHOICE_MIN = 3;
    public const CHOICE_MAX = 120;
    public const TITLE_MIN  = 3;
    public const TITLE_MAX  = 120;
    public const BODY_MIN   = 1;
    public const BODY_MAX   = 20000;
    public const NOTE_MAX   = 1000;

    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->settings = new SettingsRepository($pdo);
    }

    /* ───────────────────────── Lookups ─────────────────────────── */

    /** @return array<string,mixed>|null */
    public function adventureBySlug(string $slug): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * Resolve a scene by its slug or numeric id inside one adventure.
     *
     * @return array<string,mixed>|null
     */
    public function sceneRef(int $adventureId, string $ref): ?array
    {
        $sql = 'SELECT * FROM scenes WHERE adventure_id = :a AND ';
        $sql .= ctype_digit($ref) ? 'id = :r' : 'slug = :r';
        $s = $this->pdo->prepare($sql . ' LIMIT 1');
        $s->execute([':a' => $adventureId, ':r' => $ref]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /* ───────────────────────── Eligibility ─────────────────────── */

    public function contributionsEnabled(array $adv): bool
    {
        $mode = (string) $adv['contribution_state'];
        if ((int) ($adv['allow_branching'] ?? 1) !== 1) return false;
        if ((int) ($adv['contributions_paused'] ?? 0) === 1) return false;
        return ($mode === 'immediate' || $mode === 'approval')
            && in_array((string) $adv['state'], self::CONTRIBUTABLE_STATES, true);
    }

    /**
     * A contributor's standing on one adventure:
     * `trusted`, `approval_required`, `blocked`, or null.
     */
    public function permissionLevel(int $adventureId, ?int $userId): ?string
    {
        if ($userId === null) return null;
        $s = $this->pdo->prepare(
            'SELECT level FROM adventure_permissions
              WHERE adventure_id = :a AND user_id = :u LIMIT 1'
        );
        $s->execute([':a' => $adventureId, ':u' => $userId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : (string) $r['level'];
    }

    /**
     * The state a new submission lands in: the adventure's mode, then
     * adjusted by the contributor's per-adventure standing.
     */
    public function resolvedState(string $mode, ?string $permission): string
    {
        if ($permission === 'approval_required') return self::STATE_PENDING;
        if ($permission === 'trusted') return self::STATE_APPROVED;
        return $mode === 'immediate' ? self::STATE_APPROVED : self::STATE_PENDING;
    }

    public function isBlocked(int $adventureId, ?int $userId, string $ip): bool
    {
        $s = $this->pdo->prepare(
            'SELECT 1 FROM contribution_blocks
              WHERE adventure_id = :a
                AND ((:u IS NOT NULL AND user_id = :u)
                  OR (ip IS NOT NULL AND ip <> \'\' AND ip = :ip))
              LIMIT 1'
        );
        $s->execute([':a' => $adventureId, ':u' => $userId, ':ip' => $ip]);
        return $s->fetch() !== false;
    }

    /** Existing published choices plus queued submissions for a scene. */
    public function branchCount(int $sceneId): int
    {
        $c = $this->pdo->prepare('SELECT COUNT(*) AS c FROM choices WHERE scene_id = :s');
        $c->execute([':s' => $sceneId]);
        $used = (int) ($c->fetch()['c'] ?? 0);

        $p = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM branch_submissions
              WHERE source_scene_id = :s AND state IN ('pending','changes_requested')"
        );
        $p->execute([':s' => $sceneId]);
        return $used + (int) ($p->fetch()['c'] ?? 0);
    }

    /** @return array{limit:int,used:int,remaining:int} */
    public function branchLimit(array $adv, int $sceneId): array
    {
        $limit = max(1, (int) $adv['max_branches_per_scene']);
        $used  = $this->branchCount($sceneId);
        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
    }

    /** @return array{user:int,ip:int,user_limit:int,ip_limit:int} */
    public function rateUsage(?int $userId, string $ip): array
    {
        $userLimit = max(1, $this->settings->getInt('contributions_per_user_per_hour', 10));
        $ipLimit   = max(1, $this->settings->getInt('contributions_per_ip_per_hour', 5));

        $u = 0;
        if ($userId !== null) {
            $s = $this->pdo->prepare(
                "SELECT COUNT(*) AS c FROM contribution_attempts
                  WHERE user_id = :u
                    AND created_at > strftime('%Y-%m-%dT%H:%M:%fZ','now','-1 hour')"
            );
            $s->execute([':u' => $userId]);
            $u = (int) ($s->fetch()['c'] ?? 0);
        }
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM contribution_attempts
              WHERE ip = :ip AND ip <> ''
                AND created_at > strftime('%Y-%m-%dT%H:%M:%fZ','now','-1 hour')"
        );
        $s->execute([':ip' => $ip]);
        $i = (int) ($s->fetch()['c'] ?? 0);

        return ['user' => $u, 'ip' => $i, 'user_limit' => $userLimit, 'ip_limit' => $ipLimit];
    }

    public function isRateLimited(?int $userId, string $ip): bool
    {
        $r = $this->rateUsage($userId, $ip);
        if ($userId !== null && $r['user'] >= $r['user_limit']) return true;
        return $ip !== '' && $r['ip'] >= $r['ip_limit'];
    }

    /* ───────────────────────── Duplicates ──────────────────────── */

    /** Normalised comparison key for choice text. */
    public static function choiceKey(string $text): string
    {
        $k = mb_strtolower(trim($text));
        $k = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $k) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $k) ?? '');
    }

    /**
     * An obvious duplicate: the same normalised choice text already
     * leaves this scene, either as a live choice or as a queued
     * submission.
     */
    public function isDuplicate(int $sceneId, string $choiceText): bool
    {
        $key = self::choiceKey($choiceText);
        if ($key === '') return false;

        $s = $this->pdo->prepare('SELECT label FROM choices WHERE scene_id = :s');
        $s->execute([':s' => $sceneId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (self::choiceKey((string) $row['label']) === $key) return true;
        }

        $p = $this->pdo->prepare(
            "SELECT 1 FROM branch_submissions
              WHERE source_scene_id = :s AND choice_text_key = :k
                AND state IN ('pending','changes_requested','approved') LIMIT 1"
        );
        $p->execute([':s' => $sceneId, ':k' => $key]);
        return $p->fetch() !== false;
    }

    /* ───────────────────────── Context ─────────────────────────── */

    /**
     * Everything the submission form needs, decided server-side.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function context(string $slug, string $sceneRef, ?int $userId, string $ip): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        if (!in_array((string) $adv['state'], self::READABLE_STATES, true)) {
            return [self::UNAVAILABLE, null];
        }

        $scene = $this->sceneRef((int) $adv['id'], $sceneRef);
        if ($scene === null) return [self::NOT_FOUND, null];

        $mode        = (string) $adv['contribution_state'];
        $anonAllowed = (int) $adv['anonymous_contributions'] === 1;
        $limit       = $this->branchLimit($adv, (int) $scene['id']);

        return [self::OK, [
            'adventure' => [
                'slug'  => (string) $adv['slug'],
                'title' => (string) $adv['title'],
                'state' => (string) $adv['state'],
                'writing_guidelines' => (string) ($adv['writing_guidelines'] ?? ''),
            ],
            'scene' => [
                'id'        => (int) $scene['id'],
                'slug'      => (string) $scene['slug'],
                'title'     => (string) $scene['title'],
                'published' => (string) $scene['state'] === 'published',
                'locked'    => (int) ($scene['is_locked'] ?? 0) === 1,
            ],
            'contribution_mode'     => $mode,
            'contributions_paused'  => (int) ($adv['contributions_paused'] ?? 0) === 1,
            'allow_branching'       => (int) ($adv['allow_branching'] ?? 1) === 1,
            'permission'            => $this->permissionLevel((int) $adv['id'], $userId),
            'contributions_enabled' => $this->contributionsEnabled($adv),
            'requires_passcode'     => $adv['contribution_passcode_hash'] !== null
                                       && (string) $adv['contribution_passcode_hash'] !== '',
            'allows_anonymous'      => $anonAllowed,
            'signed_in'             => $userId !== null,
            'can_submit'            => $this->contributionsEnabled($adv)
                                       && ($userId !== null || $anonAllowed),
            'blocked'               => $this->isBlocked((int) $adv['id'], $userId, $ip),
            'rate_limited'          => $this->isRateLimited($userId, $ip),
            'branch_limit'          => $limit,
            'attribution_options'   => $userId !== null
                                       ? self::ATTRIBUTIONS
                                       : ['anonymous'],
            'limits' => [
                'choice_max' => self::CHOICE_MAX,
                'title_max'  => self::TITLE_MAX,
                'body_max'   => self::BODY_MAX,
                'note_max'   => self::NOTE_MAX,
            ],
        ]];
    }

    /* ───────────────────────── Validation ──────────────────────── */

    /**
     * Field-level validation and normalisation.
     *
     * @param array<string,mixed> $input
     * @return array{0:array<string,string>,1:array<string,mixed>}
     */
    public function validate(array $input, bool $signedIn): array
    {
        $e = [];

        // Choice text and scene titles are rendered as plain text, so
        // any markup a contributor pastes is flattened before storage.
        $choice = trim(HtmlSanitizer::toPlainText((string) ($input['choice_text'] ?? '')));
        if (mb_strlen($choice) < self::CHOICE_MIN || mb_strlen($choice) > self::CHOICE_MAX) {
            $e['choice_text'] = 'invalid';
        }

        $title = trim(HtmlSanitizer::toPlainText((string) ($input['scene_title'] ?? '')));
        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            $e['scene_title'] = 'invalid';
        }

        $bodyHtml  = HtmlSanitizer::sanitize((string) ($input['scene_body'] ?? ''));
        $bodyPlain = HtmlSanitizer::toPlainText($bodyHtml);
        $len       = mb_strlen($bodyPlain);
        if ($len < self::BODY_MIN || $len > self::BODY_MAX) $e['scene_body'] = 'invalid';

        $type = (string) ($input['scene_type'] ?? 'story');
        if (!in_array($type, self::SCENE_TYPES, true)) $e['scene_type'] = 'invalid';

        $note = trim((string) ($input['private_note'] ?? ''));
        if (mb_strlen($note) > self::NOTE_MAX) $e['private_note'] = 'too_long';

        $attribution = (string) ($input['attribution'] ?? ($signedIn ? 'username' : 'anonymous'));
        if (!in_array($attribution, self::ATTRIBUTIONS, true)) {
            $e['attribution'] = 'invalid';
        } elseif (!$signedIn && $attribution !== 'anonymous') {
            // An anonymous visitor has no name to attribute.
            $attribution = 'anonymous';
        }

        return [$e, [
            'choice_text'      => $choice,
            'choice_text_key'  => self::choiceKey($choice),
            'scene_title'      => $title,
            'scene_body'       => $bodyHtml,
            'scene_body_plain' => $bodyPlain,
            'scene_type'       => $type,
            'private_note'     => $note !== '' ? $note : null,
            'attribution'      => $attribution,
        ]];
    }

    /* ───────────────────────── Submission ──────────────────────── */

    /**
     * Validate and record one branch submission.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,string>,2:array<string,mixed>|null}
     */
    public function submit(
        string $slug,
        string $sceneRef,
        array $input,
        ?int $userId,
        string $ip = ''
    ): array {
        // Honeypot first: a filled decoy field is never a real person.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return [self::HONEYPOT, [], null];
        }

        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, [], null];
        if (!in_array((string) $adv['state'], self::READABLE_STATES, true)) {
            return [self::UNAVAILABLE, [], null];
        }

        $mode = (string) $adv['contribution_state'];
        if (!$this->contributionsEnabled($adv)) return [self::CLOSED, [], null];

        $adventureId = (int) $adv['id'];
        $anonAllowed = (int) $adv['anonymous_contributions'] === 1;
        if ($userId === null && !$anonAllowed) return [self::UNAUTHENTICATED, [], null];
        if ($userId !== null && !$this->isActiveUser($userId)) {
            return [self::UNAUTHENTICATED, [], null];
        }

        $scene = $this->sceneRef($adventureId, $sceneRef);
        if ($scene === null) return [self::NOT_FOUND, [], null];
        if ((string) $scene['state'] !== 'published') return [self::SOURCE_UNAVAILABLE, [], null];
        if ((int) ($scene['is_locked'] ?? 0) === 1) return [self::SCENE_LOCKED, [], null];

        $sceneId = (int) $scene['id'];
        if ($this->isBlocked($adventureId, $userId, $ip)) return [self::BLOCKED, [], null];
        if ($this->branchLimit($adv, $sceneId)['remaining'] < 1) {
            return [self::BRANCH_LIMIT, [], null];
        }

        // Passcode, when the adventure configures one.
        $hash = (string) ($adv['contribution_passcode_hash'] ?? '');
        if ($hash !== '') {
            $supplied = (string) ($input['passcode'] ?? '');
            if ($supplied === '') return [self::PASSCODE_REQUIRED, ['passcode' => 'required'], null];
            if (!PasswordHasher::verify($supplied, $hash)) {
                return [self::PASSCODE_INVALID, ['passcode' => 'invalid'], null];
            }
        }

        if ($this->isRateLimited($userId, $ip)) return [self::RATE_LIMITED, [], null];

        [$errors, $v] = $this->validate($input, $userId !== null);
        if ($errors !== []) return [self::INVALID, $errors, null];

        if ($this->isDuplicate($sceneId, $v['choice_text'])) {
            return [self::DUPLICATE, ['choice_text' => 'duplicate'], null];
        }

        $permission = $this->permissionLevel($adventureId, $userId);
        if ($permission === 'blocked') return [self::BLOCKED, [], null];
        $state = $this->resolvedState($mode, $permission);

        $this->pdo->beginTransaction();
        try {
            $newSceneId  = null;
            $newChoiceId = null;

            if ($state === self::STATE_APPROVED) {
                $newSceneId  = $this->insertScene($adventureId, $v);
                $newChoiceId = $this->insertChoice($sceneId, $newSceneId, $v['choice_text']);
                $this->pdo->prepare(
                    "UPDATE adventures
                        SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                      WHERE id = :a"
                )->execute([':a' => $adventureId]);
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO branch_submissions
                    (adventure_id, source_scene_id, user_id, submitted_ip, attribution,
                     choice_text, choice_text_key, scene_title, scene_body,
                     scene_body_plain, scene_type, private_note, state,
                     created_scene_id, created_choice_id)
                 VALUES
                    (:a, :src, :u, :ip, :attr, :ct, :ck, :st, :sb, :sp, :sty, :note,
                     :state, :scene, :choice)'
            );
            $ins->execute([
                ':a' => $adventureId, ':src' => $sceneId, ':u' => $userId, ':ip' => $ip,
                ':attr' => $v['attribution'], ':ct' => $v['choice_text'],
                ':ck' => $v['choice_text_key'], ':st' => $v['scene_title'],
                ':sb' => $v['scene_body'], ':sp' => $v['scene_body_plain'],
                ':sty' => $v['scene_type'], ':note' => $v['private_note'],
                ':state' => $state, ':scene' => $newSceneId, ':choice' => $newChoiceId,
            ]);
            $submissionId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state, note)
                 VALUES (:a, :u, :act, NULL, :to, :note)'
            )->execute([
                ':a' => $adventureId, ':u' => $userId,
                ':act' => $state === self::STATE_APPROVED ? 'branch_published' : 'branch_submitted',
                ':to' => $state, ':note' => $v['choice_text'],
            ]);

            $this->pdo->prepare(
                'INSERT INTO contribution_attempts (adventure_id, user_id, ip)
                 VALUES (:a, :u, :ip)'
            )->execute([':a' => $adventureId, ':u' => $userId, ':ip' => $ip]);

            $this->pdo->commit();
        } catch (Throwable $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $ex;
        }

        return [self::OK, [], [
            'submission_id' => $submissionId,
            'state'         => $state,
            'mode'          => $mode,
            'published'     => $state === self::STATE_APPROVED,
            'scene_id'      => $newSceneId,
            'choice_id'     => $newChoiceId,
            'attribution'   => $v['attribution'],
        ]];
    }

    /* ───────────────────────── History ─────────────────────────── */

    /**
     * A contributor's own submissions, newest first. Includes the
     * private note — this is the contributor's own record.
     *
     * @return list<array<string,mixed>>
     */
    public function historyForUser(int $userId, int $limit = 100): array
    {
        $s = $this->pdo->prepare(
            'SELECT b.id, b.state, b.attribution, b.choice_text, b.scene_title,
                    b.scene_type, b.private_note, b.created_at, b.moderator_note,
                    a.slug AS adventure_slug, a.title AS adventure_title,
                    s.slug AS source_scene_slug, s.title AS source_scene_title
               FROM branch_submissions b
               JOIN adventures a ON a.id = b.adventure_id
               JOIN scenes s     ON s.id = b.source_scene_id
              WHERE b.user_id = :u
           ORDER BY b.id DESC LIMIT :l'
        );
        $s->bindValue(':u', $userId, PDO::PARAM_INT);
        $s->bindValue(':l', $limit, PDO::PARAM_INT);
        $s->execute();
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id'                => (int) $r['id'],
                'state'             => (string) $r['state'],
                'attribution'       => (string) $r['attribution'],
                'choice_text'       => (string) $r['choice_text'],
                'scene_title'       => (string) $r['scene_title'],
                'scene_type'        => (string) $r['scene_type'],
                'private_note'      => $r['private_note'],
                'moderator_note'    => $r['moderator_note'],
                'created_at'        => (string) $r['created_at'],
                'adventure_slug'    => (string) $r['adventure_slug'],
                'adventure_title'   => (string) $r['adventure_title'],
                'source_scene_slug' => (string) $r['source_scene_slug'],
                'source_scene_title'=> (string) $r['source_scene_title'],
            ];
        }
        return $out;
    }

    /**
     * Pending submissions for one adventure — the approval queue.
     * Callers must check the manage role first.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingFor(int $adventureId): array
    {
        $s = $this->pdo->prepare(
            'SELECT b.*, u.username, u.display_name
               FROM branch_submissions b
          LEFT JOIN users u ON u.id = b.user_id
              WHERE b.adventure_id = :a AND b.state = \'pending\'
           ORDER BY b.id ASC'
        );
        $s->execute([':a' => $adventureId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'choice_text'  => (string) $r['choice_text'],
                'scene_title'  => (string) $r['scene_title'],
                'scene_type'   => (string) $r['scene_type'],
                'private_note' => $r['private_note'],
                'created_at'   => (string) $r['created_at'],
                'public_attribution'   => self::publicAttribution($r),
                // Internal attribution survives an anonymous public
                // preference, so moderators always know who wrote it.
                'internal_user_id'     => $r['user_id'] === null ? null : (int) $r['user_id'],
                'internal_username'    => $r['username'],
                'internal_ip'          => (string) $r['submitted_ip'],
            ];
        }
        return $out;
    }

    /**
     * The name readers see. `anonymous` hides the contributor's name
     * from the public payload only.
     *
     * @param array<string,mixed> $row
     */
    public static function publicAttribution(array $row): string
    {
        $mode = (string) ($row['attribution'] ?? 'anonymous');
        if ($mode === 'username' && !empty($row['username'])) {
            return (string) $row['username'];
        }
        if ($mode === 'display_name' && !empty($row['display_name'])) {
            return (string) $row['display_name'];
        }
        return 'Anonymous';
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    private function isActiveUser(int $userId): bool
    {
        $s = $this->pdo->prepare('SELECT status FROM users WHERE id = :u LIMIT 1');
        $s->execute([':u' => $userId]);
        $r = $s->fetch();
        return $r !== false && (string) $r['status'] === 'active';
    }

    /** @param array<string,mixed> $v */
    private function insertScene(int $adventureId, array $v): int
    {
        $n = $this->pdo->prepare(
            'SELECT COALESCE(MAX(scene_number), 0) AS n FROM scenes WHERE adventure_id = :a'
        );
        $n->execute([':a' => $adventureId]);
        $number = ((int) ($n->fetch()['n'] ?? 0)) + 1;

        $slug = $this->uniqueSceneSlug($adventureId, (string) $v['scene_title']);
        $s = $this->pdo->prepare(
            'INSERT INTO scenes
                (adventure_id, slug, scene_number, title, body, body_plain,
                 scene_type, state, is_start)
             VALUES (:a, :s, :n, :t, :b, :bp, :ty, \'published\', 0)'
        );
        $s->execute([
            ':a' => $adventureId, ':s' => $slug, ':n' => $number,
            ':t' => $v['scene_title'], ':b' => $v['scene_body'],
            ':bp' => $v['scene_body_plain'], ':ty' => $v['scene_type'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertChoice(int $sceneId, int $targetId, string $label): int
    {
        $p = $this->pdo->prepare('SELECT COUNT(*) AS c FROM choices WHERE scene_id = :s');
        $p->execute([':s' => $sceneId]);
        $position = (int) ($p->fetch()['c'] ?? 0);

        $c = $this->pdo->prepare(
            'INSERT INTO choices (scene_id, target_scene_id, label, position)
             VALUES (:s, :t, :l, :p)'
        );
        $c->execute([':s' => $sceneId, ':t' => $targetId, ':l' => $label, ':p' => $position]);
        return (int) $this->pdo->lastInsertId();
    }

    private function uniqueSceneSlug(int $adventureId, string $title): string
    {
        $base = AdventureService::slugify($title);
        $slug = $base;
        $i = 1;
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM scenes WHERE adventure_id = :a AND slug = :s LIMIT 1'
        );
        while (true) {
            $stmt->execute([':a' => $adventureId, ':s' => $slug]);
            if ($stmt->fetch() === false) return $slug;
            $i++;
            $slug = $base . '-' . $i;
        }
    }
}
