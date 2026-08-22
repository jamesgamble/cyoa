<?php
/**
 * ModerationService — moderation and owner controls (v0.19.0).
 *
 * The single authority for everything an adventure's team can do with
 * contributed content:
 *
 *   • roles — owner, editor, reviewer, administrator. The role is
 *     always derived server-side from the adventure author, the
 *     collaborator roster, and the administrator flag. A role in the
 *     request body is never read;
 *   • decisions — approve, reject with feedback, request changes, and
 *     edit-and-approve. Every decision runs in ONE transaction that
 *     re-reads the submission state, so a double approval, an approval
 *     after rejection or withdrawal, and a branch-limit bypass are all
 *     impossible even under concurrent requests;
 *   • reviewers — may add private notes and recommend approval or
 *     rejection. A recommendation never changes state;
 *   • contributors — may read feedback, edit a changes-requested
 *     submission, resubmit it, and withdraw it. They can never touch
 *     someone else's submission or an already-decided one;
 *   • story — owners and editors edit scenes and choices, lock and
 *     unlock scenes against new branches, hide and restore scenes, and
 *     add their own branches;
 *   • settings — contribution mode, anonymous contributions, branch
 *     limit, passcode, pause, branching switch, notifications;
 *   • permissions — per-user, per-adventure standing: trusted,
 *     approval required, or blocked.
 *
 * Every lookup is scoped by adventure id, so an id belonging to one
 * adventure can never be acted on through another adventure's slug.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class ModerationService
{
    public const OK        = 'ok';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const INVALID   = 'invalid';
    public const CONFLICT  = 'conflict';
    public const READ_ONLY = 'read_only';
    public const LIMIT     = 'branch_limit_reached';

    public const ROLE_OWNER    = 'owner';
    public const ROLE_EDITOR   = 'editor';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_ADMIN    = 'administrator';

    public const SECTIONS = ['overview', 'story', 'submissions', 'reports', 'collaborators', 'settings'];

    /** Submission states, in the order the tabs present them. */
    public const STATES = ['pending', 'changes_requested', 'approved', 'rejected', 'withdrawn'];

    /** Decisions an owner or editor can take. */
    public const DECISIONS = ['approve', 'reject', 'request_changes', 'edit_approve'];

    /** States a decision may act on. */
    private const DECIDABLE_FROM = ['pending', 'changes_requested'];

    public const REPORT_REASONS = ['rating', 'warning', 'spam', 'harassment', 'illegal', 'broken', 'other'];
    public const PERMISSION_LEVELS = ['trusted', 'approval_required', 'blocked'];

    public const FEEDBACK_MAX = 2000;
    public const NOTE_MAX     = 2000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ───────────────────────── Roles ───────────────────────────── */

    /** @return array<string,mixed>|null */
    public function adventureBySlug(string $slug): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function adventureById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE id = :a LIMIT 1');
        $s->execute([':a' => $id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public function roleFor(int $adventureId, ?int $userId, bool $isAdmin = false): ?string
    {
        if ($isAdmin) return self::ROLE_ADMIN;
        if ($userId === null) return null;

        $s = $this->pdo->prepare('SELECT author_id FROM adventures WHERE id = :a LIMIT 1');
        $s->execute([':a' => $adventureId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ((int) $row['author_id'] === $userId) return self::ROLE_OWNER;

        $c = $this->pdo->prepare(
            'SELECT role FROM adventure_collaborators
              WHERE adventure_id = :a AND user_id = :u LIMIT 1'
        );
        $c->execute([':a' => $adventureId, ':u' => $userId]);
        $r = $c->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : (string) $r['role'];
    }

    /** Any team member may open the management sections. */
    public function canView(?string $role): bool
    {
        return $role !== null;
    }

    /** Owners, editors, and administrators decide submissions and edit the story. */
    public function canDecide(?string $role): bool
    {
        return $role === self::ROLE_OWNER || $role === self::ROLE_EDITOR || $role === self::ROLE_ADMIN;
    }

    /** Owners and administrators own settings, permissions, and the roster. */
    public function canConfigure(?string $role): bool
    {
        return $role === self::ROLE_OWNER || $role === self::ROLE_ADMIN;
    }

    /** Everyone on the team, reviewers included, may leave private notes. */
    public function canReview(?string $role): bool
    {
        return $role !== null;
    }

    public function isReadOnly(string $state): bool
    {
        return $state === 'archived' || $state === 'suspended';
    }

    /** @return array<string,bool> */
    public function capabilities(?string $role, string $state): array
    {
        $writable = !$this->isReadOnly($state);
        return [
            'view'        => $this->canView($role),
            'decide'      => $writable && $this->canDecide($role),
            'edit_story'  => $writable && $this->canDecide($role),
            'review'      => $writable && $this->canReview($role),
            'configure'   => $writable && $this->canConfigure($role),
            'read_only'   => !$writable,
        ];
    }

    /* ───────────────────────── Overview ────────────────────────── */

    /**
     * The management payload for one section-agnostic overview.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function overview(string $slug, ?int $userId, bool $isAdmin = false): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $id   = (int) $adv['id'];
        $role = $this->roleFor($id, $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];

        return [self::OK, [
            'adventure'   => $this->adventureSummary($adv),
            'role'        => $role,
            'sections'    => self::SECTIONS,
            'capabilities'=> $this->capabilities($role, (string) $adv['state']),
            'counts'      => $this->counts($id),
            'settings'    => $this->settingsPayload($adv),
        ]];
    }

    /** @param array<string,mixed> $adv @return array<string,mixed> */
    private function adventureSummary(array $adv): array
    {
        return [
            'id'          => (int) $adv['id'],
            'slug'        => (string) $adv['slug'],
            'title'       => (string) $adv['title'],
            'description' => (string) ($adv['description'] ?? ''),
            'genre'       => (string) ($adv['genre'] ?? ''),
            'content_rating' => (string) $adv['content_rating'],
            'state'       => (string) $adv['state'],
            'writing_guidelines' => (string) ($adv['writing_guidelines'] ?? ''),
            'updated_at'  => (string) $adv['updated_at'],
        ];
    }

    /** @return array<string,int> */
    public function counts(int $adventureId): array
    {
        $out = [];
        foreach (self::STATES as $state) {
            $s = $this->pdo->prepare(
                'SELECT COUNT(*) AS c FROM branch_submissions
                  WHERE adventure_id = :a AND state = :s'
            );
            $s->execute([':a' => $adventureId, ':s' => $state]);
            $out[$state] = (int) ($s->fetch()['c'] ?? 0);
        }
        $r = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM content_reports
              WHERE adventure_id = :a AND state = 'open'"
        );
        $r->execute([':a' => $adventureId]);
        $out['open_reports'] = (int) ($r->fetch()['c'] ?? 0);

        $sc = $this->pdo->prepare('SELECT COUNT(*) AS c FROM scenes WHERE adventure_id = :a');
        $sc->execute([':a' => $adventureId]);
        $out['scenes'] = (int) ($sc->fetch()['c'] ?? 0);

        return $out;
    }

    /* ───────────────────────── Submissions ─────────────────────── */

    /**
     * One tab of the submission queue.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function submissions(string $slug, ?int $userId, bool $isAdmin, string $state): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $id   = (int) $adv['id'];
        $role = $this->roleFor($id, $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];
        if (!in_array($state, self::STATES, true)) return [self::INVALID, null];

        $s = $this->pdo->prepare(
            'SELECT b.*, u.username, u.display_name
               FROM branch_submissions b
          LEFT JOIN users u ON u.id = b.user_id
              WHERE b.adventure_id = :a AND b.state = :s
           ORDER BY b.id ASC'
        );
        $s->execute([':a' => $id, ':s' => $state]);

        $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $items[] = $this->submissionRow($r, $role);
        }

        return [self::OK, [
            'state'        => $state,
            'role'         => $role,
            'capabilities' => $this->capabilities($role, (string) $adv['state']),
            'counts'       => $this->counts($id),
            'submissions'  => $items,
        ]];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function submissionRow(array $r, ?string $role): array
    {
        $out = [
            'id'                 => (int) $r['id'],
            'state'              => (string) $r['state'],
            'choice_text'        => (string) $r['choice_text'],
            'scene_title'        => (string) $r['scene_title'],
            'scene_body'         => (string) $r['scene_body'],
            'scene_type'         => (string) $r['scene_type'],
            'attribution'        => (string) $r['attribution'],
            'public_attribution' => BranchSubmissionService::publicAttribution($r),
            'private_note'       => $r['private_note'],
            'feedback'           => $r['feedback'],
            'revision'           => (int) $r['revision'],
            'created_at'         => (string) $r['created_at'],
            'decided_at'         => $r['decided_at'],
            'source_scene_id'    => (int) $r['source_scene_id'],
            'created_scene_id'   => $r['created_scene_id'] === null ? null : (int) $r['created_scene_id'],
            // Internal attribution survives an anonymous public
            // preference: moderators always know who wrote a branch.
            'internal_user_id'   => $r['user_id'] === null ? null : (int) $r['user_id'],
            'internal_username'  => $r['username'],
            'internal_ip'        => (string) $r['submitted_ip'],
            'reviews'            => $this->reviews((int) $r['id']),
        ];
        if ($role === null) unset($out['internal_ip'], $out['internal_username']);
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function reviews(int $submissionId): array
    {
        $s = $this->pdo->prepare(
            'SELECT r.id, r.note, r.recommendation, r.created_at, u.display_name AS reviewer
               FROM submission_reviews r
          LEFT JOIN users u ON u.id = r.reviewer_id
              WHERE r.submission_id = :s ORDER BY r.id ASC'
        );
        $s->execute([':s' => $submissionId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id'             => (int) $r['id'],
                'note'           => (string) $r['note'],
                'recommendation' => $r['recommendation'],
                'reviewer'       => $r['reviewer'],
                'created_at'     => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Load a submission scoped to one adventure. A submission that
     * belongs to a different adventure is simply not found here.
     *
     * @return array<string,mixed>|null
     */
    public function submission(int $adventureId, int $submissionId): ?array
    {
        $s = $this->pdo->prepare(
            'SELECT * FROM branch_submissions WHERE id = :i AND adventure_id = :a LIMIT 1'
        );
        $s->execute([':i' => $submissionId, ':a' => $adventureId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /* ───────────────────────── Decisions ───────────────────────── */

    /**
     * Approve, reject, request changes, or edit-and-approve.
     *
     * The transaction re-reads the submission and only writes when it
     * is still decidable, so two concurrent approvals cannot both
     * publish, and no decision can follow a rejection or withdrawal.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function decide(
        string $slug,
        int $submissionId,
        ?int $userId,
        bool $isAdmin,
        string $action,
        array $input = []
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $userId, $isAdmin);

        if (!$this->canView($role)) return [self::FORBIDDEN, null];
        if (!$this->canDecide($role)) return [self::FORBIDDEN, null];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];
        if (!in_array($action, self::DECISIONS, true)) return [self::INVALID, null];

        $sub = $this->submission($adventureId, $submissionId);
        if ($sub === null) return [self::NOT_FOUND, null];
        if (!in_array((string) $sub['state'], self::DECIDABLE_FROM, true)) {
            return [self::CONFLICT, ['state' => (string) $sub['state']]];
        }

        $feedback = trim((string) ($input['feedback'] ?? ''));
        if (mb_strlen($feedback) > self::FEEDBACK_MAX) return [self::INVALID, null];
        if (($action === 'reject' || $action === 'request_changes') && $feedback === '') {
            return [self::INVALID, ['feedback' => 'required']];
        }

        $edits = null;
        if ($action === 'edit_approve') {
            [$errors, $edits] = $this->validateBranchContent($input);
            if ($errors !== []) return [self::INVALID, ['fields' => $errors]];
        }

        $publishing = $action === 'approve' || $action === 'edit_approve';

        $this->pdo->beginTransaction();
        try {
            // Re-read inside the transaction and claim the row by
            // state. A second concurrent decision sees zero rows.
            $target = $publishing ? 'approved' : ($action === 'reject' ? 'rejected' : 'changes_requested');

            $cur = $this->pdo->prepare(
                'SELECT * FROM branch_submissions
                  WHERE id = :i AND adventure_id = :a
                    AND state IN (\'pending\',\'changes_requested\') LIMIT 1'
            );
            $cur->execute([':i' => $submissionId, ':a' => $adventureId]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->pdo->rollBack();
                return [self::CONFLICT, null];
            }

            $newSceneId = null;
            $newChoiceId = null;

            if ($publishing) {
                $content = $edits ?? [
                    'choice_text'      => (string) $row['choice_text'],
                    'scene_title'      => (string) $row['scene_title'],
                    'scene_body'       => (string) $row['scene_body'],
                    'scene_body_plain' => (string) $row['scene_body_plain'],
                    'scene_type'       => (string) $row['scene_type'],
                ];

                // Branch limit, counted inside the transaction and
                // ignoring this submission's own reserved slot.
                if (!$this->hasFreeSlot($adv, (int) $row['source_scene_id'], $submissionId)) {
                    $this->pdo->rollBack();
                    return [self::LIMIT, null];
                }

                $newSceneId = $this->insertScene($adventureId, $content);
                $newChoiceId = $this->insertChoice(
                    (int) $row['source_scene_id'], $newSceneId, (string) $content['choice_text']
                );

                $this->pdo->prepare(
                    "UPDATE adventures SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                      WHERE id = :a"
                )->execute([':a' => $adventureId]);
            }

            $upd = $this->pdo->prepare(
                "UPDATE branch_submissions
                    SET state = :st,
                        choice_text = :ct, choice_text_key = :ck,
                        scene_title = :title, scene_body = :body,
                        scene_body_plain = :plain, scene_type = :type,
                        feedback = :fb, decided_by = :by,
                        edited_by = :eb,
                        decided_at = strftime('%Y-%m-%dT%H:%M:%fZ','now'),
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now'),
                        created_scene_id = :scene, created_choice_id = :choice
                  WHERE id = :i AND adventure_id = :a
                    AND state IN ('pending','changes_requested')"
            );
            $content = $edits ?? [
                'choice_text'      => (string) $row['choice_text'],
                'scene_title'      => (string) $row['scene_title'],
                'scene_body'       => (string) $row['scene_body'],
                'scene_body_plain' => (string) $row['scene_body_plain'],
                'scene_type'       => (string) $row['scene_type'],
            ];
            $upd->execute([
                ':st'    => $target,
                ':ct'    => $content['choice_text'],
                ':ck'    => BranchSubmissionService::choiceKey((string) $content['choice_text']),
                ':title' => $content['scene_title'],
                ':body'  => $content['scene_body'],
                ':plain' => $content['scene_body_plain'],
                ':type'  => $content['scene_type'],
                ':fb'    => $feedback !== '' ? $feedback : $row['feedback'],
                ':by'    => $userId,
                ':eb'    => $edits !== null ? $userId : $row['edited_by'],
                ':scene' => $newSceneId ?? $row['created_scene_id'],
                ':choice'=> $newChoiceId ?? $row['created_choice_id'],
                ':i'     => $submissionId,
                ':a'     => $adventureId,
            ]);
            if ($upd->rowCount() !== 1) {
                $this->pdo->rollBack();
                return [self::CONFLICT, null];
            }

            $this->logActivity($adventureId, $userId, 'submission_' . $action,
                (string) $row['state'], $target, (string) $content['choice_text']);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, [
            'submission_id' => $submissionId,
            'state'         => $target,
            'action'        => $action,
            'scene_id'      => $newSceneId,
            'choice_id'     => $newChoiceId,
        ]];
    }

    /**
     * A scene has room for one more branch. Pending submissions other
     * than $exceptSubmissionId keep their reserved slot.
     *
     * @param array<string,mixed> $adv
     */
    public function hasFreeSlot(array $adv, int $sceneId, ?int $exceptSubmissionId = null): int|bool
    {
        $limit = max(1, (int) $adv['max_branches_per_scene']);

        $c = $this->pdo->prepare('SELECT COUNT(*) AS c FROM choices WHERE scene_id = :s');
        $c->execute([':s' => $sceneId]);
        $used = (int) ($c->fetch()['c'] ?? 0);

        $p = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM branch_submissions
              WHERE source_scene_id = :s
                AND state IN ('pending','changes_requested')
                AND (:x IS NULL OR id <> :x)"
        );
        $p->execute([':s' => $sceneId, ':x' => $exceptSubmissionId]);
        $used += (int) ($p->fetch()['c'] ?? 0);

        return $used < $limit;
    }

    /* ───────────────────────── Reviewer notes ──────────────────── */

    /**
     * A private note, optionally carrying a recommendation. Advice
     * only — the submission state is untouched.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function addReview(
        string $slug,
        int $submissionId,
        ?int $userId,
        bool $isAdmin,
        string $note,
        ?string $recommendation
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->canReview($role)) return [self::FORBIDDEN, null];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];

        $note = trim($note);
        if ($note === '' && $recommendation === null) return [self::INVALID, null];
        if (mb_strlen($note) > self::NOTE_MAX) return [self::INVALID, null];
        if ($recommendation !== null && !in_array($recommendation, ['approve', 'reject'], true)) {
            return [self::INVALID, null];
        }

        $sub = $this->submission($adventureId, $submissionId);
        if ($sub === null) return [self::NOT_FOUND, null];

        $this->pdo->prepare(
            'INSERT INTO submission_reviews (submission_id, adventure_id, reviewer_id, note, recommendation)
             VALUES (:s, :a, :u, :n, :r)'
        )->execute([
            ':s' => $submissionId, ':a' => $adventureId, ':u' => $userId,
            ':n' => $note, ':r' => $recommendation,
        ]);

        return [self::OK, ['reviews' => $this->reviews($submissionId)]];
    }

    /* ───────────────────────── Contributor actions ─────────────── */

    /**
     * Edit a changes-requested submission and resubmit it. Only the
     * contributor who wrote it, and only while it is awaiting changes.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function contributorUpdate(int $submissionId, int $userId, array $input, bool $resubmit = true): array
    {
        $s = $this->pdo->prepare('SELECT * FROM branch_submissions WHERE id = :i LIMIT 1');
        $s->execute([':i' => $submissionId]);
        $sub = $s->fetch(PDO::FETCH_ASSOC);
        if ($sub === false) return [self::NOT_FOUND, null];
        if ($sub['user_id'] === null || (int) $sub['user_id'] !== $userId) return [self::FORBIDDEN, null];
        if ((string) $sub['state'] !== 'changes_requested') {
            return [self::CONFLICT, ['state' => (string) $sub['state']]];
        }

        [$errors, $v] = $this->validateBranchContent($input);
        if ($errors !== []) return [self::INVALID, ['fields' => $errors]];

        $state = $resubmit ? 'pending' : 'changes_requested';

        $this->pdo->beginTransaction();
        try {
            $u = $this->pdo->prepare(
                "UPDATE branch_submissions
                    SET choice_text = :ct, choice_text_key = :ck, scene_title = :t,
                        scene_body = :b, scene_body_plain = :p, scene_type = :ty,
                        state = :st, revision = revision + 1,
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :i AND user_id = :u AND state = 'changes_requested'"
            );
            $u->execute([
                ':ct' => $v['choice_text'],
                ':ck' => BranchSubmissionService::choiceKey((string) $v['choice_text']),
                ':t' => $v['scene_title'], ':b' => $v['scene_body'],
                ':p' => $v['scene_body_plain'], ':ty' => $v['scene_type'],
                ':st' => $state, ':i' => $submissionId, ':u' => $userId,
            ]);
            if ($u->rowCount() !== 1) {
                $this->pdo->rollBack();
                return [self::CONFLICT, null];
            }
            if ($resubmit) {
                $this->logActivity((int) $sub['adventure_id'], $userId, 'branch_resubmitted',
                    'changes_requested', 'pending', (string) $v['choice_text']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, ['submission_id' => $submissionId, 'state' => $state]];
    }

    /**
     * Withdraw a submission. Terminal: nothing can be approved after
     * it, and the reserved branch slot is released.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function withdraw(int $submissionId, int $userId): array
    {
        $s = $this->pdo->prepare('SELECT * FROM branch_submissions WHERE id = :i LIMIT 1');
        $s->execute([':i' => $submissionId]);
        $sub = $s->fetch(PDO::FETCH_ASSOC);
        if ($sub === false) return [self::NOT_FOUND, null];
        if ($sub['user_id'] === null || (int) $sub['user_id'] !== $userId) return [self::FORBIDDEN, null];

        $u = $this->pdo->prepare(
            "UPDATE branch_submissions
                SET state = 'withdrawn',
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :i AND user_id = :u AND state IN ('pending','changes_requested')"
        );
        $u->execute([':i' => $submissionId, ':u' => $userId]);
        if ($u->rowCount() !== 1) return [self::CONFLICT, ['state' => (string) $sub['state']]];

        $this->logActivity((int) $sub['adventure_id'], $userId, 'branch_withdrawn',
            (string) $sub['state'], 'withdrawn', (string) $sub['choice_text']);

        return [self::OK, ['submission_id' => $submissionId, 'state' => 'withdrawn']];
    }

    /* ───────────────────────── Story ───────────────────────────── */

    /**
     * Scenes with their choices, drafts and hidden scenes included.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function story(string $slug, ?int $userId, bool $isAdmin): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $id   = (int) $adv['id'];
        $role = $this->roleFor($id, $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];

        $s = $this->pdo->prepare(
            'SELECT * FROM scenes WHERE adventure_id = :a ORDER BY scene_number'
        );
        $s->execute([':a' => $id]);
        $scenes = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $sceneId = (int) $r['id'];
            $c = $this->pdo->prepare(
                'SELECT c.id, c.label, c.position, c.target_scene_id, t.title AS target_title
                   FROM choices c LEFT JOIN scenes t ON t.id = c.target_scene_id
                  WHERE c.scene_id = :s ORDER BY c.position, c.id'
            );
            $c->execute([':s' => $sceneId]);
            $choices = [];
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cr) {
                $choices[] = [
                    'id'            => (int) $cr['id'],
                    'label'         => (string) $cr['label'],
                    'position'      => (int) $cr['position'],
                    'target_scene_id' => (int) $cr['target_scene_id'],
                    'target_title'  => $cr['target_title'],
                ];
            }
            $scenes[] = [
                'id'        => $sceneId,
                'slug'      => (string) $r['slug'],
                'number'    => (int) $r['scene_number'],
                'title'     => (string) $r['title'],
                'body'      => (string) $r['body'],
                'scene_type'=> (string) $r['scene_type'],
                'state'     => (string) $r['state'],
                'locked'    => (int) ($r['is_locked'] ?? 0) === 1,
                'is_start'  => (int) $r['is_start'] === 1,
                'choices'   => $choices,
                'branch_slots' => [
                    'limit' => max(1, (int) $adv['max_branches_per_scene']),
                    'used'  => count($choices),
                ],
            ];
        }

        return [self::OK, [
            'role'         => $role,
            'capabilities' => $this->capabilities($role, (string) $adv['state']),
            'scenes'       => $scenes,
        ]];
    }

    /**
     * Edit a scene and, optionally, the labels and order of its
     * choices. Bodies are sanitised; a choice can only be repointed at
     * a scene inside the same adventure.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function updateScene(string $slug, int $sceneId, ?int $userId, bool $isAdmin, array $input): array
    {
        [$outcome, $ctx] = $this->storyGate($slug, $userId, $isAdmin);
        if ($outcome !== self::OK || $ctx === null) return [$outcome, null];
        $adv = $ctx['adventure'];
        $adventureId = (int) $adv['id'];

        $scene = $this->sceneIn($adventureId, $sceneId);
        if ($scene === null) return [self::NOT_FOUND, null];

        $errors = [];
        $title = trim(HtmlSanitizer::toPlainText((string) ($input['title'] ?? $scene['title'])));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) $errors['title'] = 'invalid';

        $bodyHtml  = HtmlSanitizer::sanitize((string) ($input['body'] ?? $scene['body']));
        $bodyPlain = HtmlSanitizer::toPlainText($bodyHtml);
        if ($bodyPlain === '' || mb_strlen($bodyPlain) > 20000) $errors['body'] = 'invalid';

        $type = (string) ($input['scene_type'] ?? $scene['scene_type']);
        if (!in_array($type, ['story', 'ending'], true)) $errors['scene_type'] = 'invalid';

        $choices = [];
        if (isset($input['choices']) && is_array($input['choices'])) {
            foreach ($input['choices'] as $i => $raw) {
                if (!is_array($raw)) continue;
                $label = trim(HtmlSanitizer::toPlainText((string) ($raw['label'] ?? '')));
                if ($label === '' || mb_strlen($label) > 120) {
                    $errors['choices'] = 'invalid';
                    continue;
                }
                $choices[] = [
                    'id'       => isset($raw['id']) ? (int) $raw['id'] : 0,
                    'label'    => $label,
                    'position' => (int) $i,
                ];
            }
        }
        if ($errors !== []) return [self::INVALID, ['fields' => $errors]];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE scenes
                    SET title = :t, body = :b, body_plain = :p, scene_type = :ty,
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :i AND adventure_id = :a"
            )->execute([
                ':t' => $title, ':b' => $bodyHtml, ':p' => $bodyPlain,
                ':ty' => $type, ':i' => $sceneId, ':a' => $adventureId,
            ]);

            foreach ($choices as $c) {
                if ($c['id'] <= 0) continue;
                // Scoped to this scene, so a choice from another
                // adventure can never be relabelled from here.
                $this->pdo->prepare(
                    'UPDATE choices SET label = :l, position = :p
                      WHERE id = :i AND scene_id = :s'
                )->execute([':l' => $c['label'], ':p' => $c['position'], ':i' => $c['id'], ':s' => $sceneId]);
            }

            $this->logActivity($adventureId, $userId, 'scene_edited', null, null, $title);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, ['scene_id' => $sceneId, 'title' => $title]];
    }

    /**
     * lock | unlock | hide | restore.
     *
     * Locking closes a scene to new branches without hiding it.
     * Hiding removes it from public reading; restoring publishes it
     * again. The opening scene can never be hidden.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function sceneAction(string $slug, int $sceneId, ?int $userId, bool $isAdmin, string $action): array
    {
        [$outcome, $ctx] = $this->storyGate($slug, $userId, $isAdmin);
        if ($outcome !== self::OK || $ctx === null) return [$outcome, null];
        $adventureId = (int) $ctx['adventure']['id'];

        $scene = $this->sceneIn($adventureId, $sceneId);
        if ($scene === null) return [self::NOT_FOUND, null];

        switch ($action) {
            case 'lock':
            case 'unlock':
                $this->pdo->prepare(
                    "UPDATE scenes SET is_locked = :v,
                            updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                      WHERE id = :i AND adventure_id = :a"
                )->execute([':v' => $action === 'lock' ? 1 : 0, ':i' => $sceneId, ':a' => $adventureId]);
                break;

            case 'hide':
                if ((int) $scene['is_start'] === 1) return [self::CONFLICT, ['reason' => 'opening_scene']];
                $this->pdo->prepare(
                    "UPDATE scenes SET state = 'hidden',
                            updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                      WHERE id = :i AND adventure_id = :a"
                )->execute([':i' => $sceneId, ':a' => $adventureId]);
                break;

            case 'restore':
                $this->pdo->prepare(
                    "UPDATE scenes SET state = 'published',
                            updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                      WHERE id = :i AND adventure_id = :a AND state = 'hidden'"
                )->execute([':i' => $sceneId, ':a' => $adventureId]);
                break;

            default:
                return [self::INVALID, null];
        }

        $this->logActivity($adventureId, $userId, 'scene_' . $action, null, null, (string) $scene['title']);
        $fresh = $this->sceneIn($adventureId, $sceneId);
        return [self::OK, [
            'scene_id' => $sceneId,
            'state'    => (string) ($fresh['state'] ?? ''),
            'locked'   => (int) ($fresh['is_locked'] ?? 0) === 1,
        ]];
    }

    /**
     * An owner-created branch: a new choice on a source scene plus the
     * scene it leads to, written in one transaction and published at
     * once. The branch limit still applies.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function createOwnerBranch(string $slug, int $sceneId, ?int $userId, bool $isAdmin, array $input): array
    {
        [$outcome, $ctx] = $this->storyGate($slug, $userId, $isAdmin);
        if ($outcome !== self::OK || $ctx === null) return [$outcome, null];
        $adv = $ctx['adventure'];
        $adventureId = (int) $adv['id'];

        $scene = $this->sceneIn($adventureId, $sceneId);
        if ($scene === null) return [self::NOT_FOUND, null];

        [$errors, $v] = $this->validateBranchContent($input);
        if ($errors !== []) return [self::INVALID, ['fields' => $errors]];

        $this->pdo->beginTransaction();
        try {
            if (!$this->hasFreeSlot($adv, $sceneId)) {
                $this->pdo->rollBack();
                return [self::LIMIT, null];
            }
            $newSceneId  = $this->insertScene($adventureId, $v);
            $newChoiceId = $this->insertChoice($sceneId, $newSceneId, (string) $v['choice_text']);
            $this->logActivity($adventureId, $userId, 'owner_branch_added', null, 'approved',
                (string) $v['choice_text']);
            $this->pdo->prepare(
                "UPDATE adventures SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = :a"
            )->execute([':a' => $adventureId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, ['scene_id' => $newSceneId, 'choice_id' => $newChoiceId]];
    }

    /* ───────────────────────── Settings ────────────────────────── */

    /**
     * @param array<string,mixed> $adv
     * @return array<string,mixed>
     */
    public function settingsPayload(array $adv): array
    {
        return [
            'contribution_mode'       => (string) $adv['contribution_state'],
            'anonymous_contributions' => (int) $adv['anonymous_contributions'] === 1,
            'max_branches_per_scene'  => (int) $adv['max_branches_per_scene'],
            'requires_passcode'       => ($adv['contribution_passcode_hash'] ?? '') !== ''
                                         && $adv['contribution_passcode_hash'] !== null,
            'contributions_paused'    => (int) ($adv['contributions_paused'] ?? 0) === 1,
            'allow_branching'         => (int) ($adv['allow_branching'] ?? 1) === 1,
            'notify_on_submission'    => (int) ($adv['notify_on_submission'] ?? 1) === 1,
            'notify_on_report'        => (int) ($adv['notify_on_report'] ?? 1) === 1,
        ];
    }

    /**
     * Owner-only contribution settings. The passcode is only replaced
     * when a new one is supplied, and only ever stored hashed.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function updateSettings(string $slug, ?int $userId, bool $isAdmin, array $input): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];
        if (!$this->canConfigure($role)) return [self::FORBIDDEN, null];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];

        $mode = (string) ($input['contribution_mode'] ?? $adv['contribution_state']);
        if (!in_array($mode, ['immediate', 'approval', 'closed'], true)) {
            return [self::INVALID, ['fields' => ['contribution_mode' => 'invalid']]];
        }

        $limit = (int) ($input['max_branches_per_scene'] ?? $adv['max_branches_per_scene']);
        if ($limit < 1 || $limit > 12) {
            return [self::INVALID, ['fields' => ['max_branches_per_scene' => 'invalid']]];
        }

        $flag = static function (array $in, string $key, int $current): int {
            if (!array_key_exists($key, $in)) return $current;
            return !empty($in[$key]) ? 1 : 0;
        };

        $anon   = $flag($input, 'anonymous_contributions', (int) $adv['anonymous_contributions']);
        $paused = $flag($input, 'contributions_paused', (int) ($adv['contributions_paused'] ?? 0));
        $branch = $flag($input, 'allow_branching', (int) ($adv['allow_branching'] ?? 1));
        $nSub   = $flag($input, 'notify_on_submission', (int) ($adv['notify_on_submission'] ?? 1));
        $nRep   = $flag($input, 'notify_on_report', (int) ($adv['notify_on_report'] ?? 1));

        $hash = $adv['contribution_passcode_hash'];
        if (array_key_exists('contribution_passcode', $input)) {
            $pass = (string) $input['contribution_passcode'];
            if ($pass === '') {
                $hash = null;                       // clearing the passcode
            } elseif (mb_strlen($pass) < 4 || mb_strlen($pass) > 128) {
                return [self::INVALID, ['fields' => ['contribution_passcode' => 'invalid']]];
            } else {
                $hash = PasswordHasher::hash($pass)['hash'];
            }
        }

        $this->pdo->prepare(
            "UPDATE adventures
                SET contribution_state = :m, anonymous_contributions = :an,
                    max_branches_per_scene = :lim, contribution_passcode_hash = :pc,
                    contributions_paused = :pa, allow_branching = :ab,
                    notify_on_submission = :ns, notify_on_report = :nr,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :a"
        )->execute([
            ':m' => $mode, ':an' => $anon, ':lim' => $limit, ':pc' => $hash,
            ':pa' => $paused, ':ab' => $branch, ':ns' => $nSub, ':nr' => $nRep,
            ':a' => $adventureId,
        ]);

        $this->logActivity($adventureId, $userId, 'settings_updated', null, $mode, null);

        $fresh = $this->adventureById($adventureId);
        return [self::OK, ['settings' => $this->settingsPayload($fresh ?? $adv)]];
    }

    /**
     * Adventure details and writing guidelines.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function updateDetails(string $slug, ?int $userId, bool $isAdmin, array $input): array
    {
        [$outcome, $ctx] = $this->storyGate($slug, $userId, $isAdmin);
        if ($outcome !== self::OK || $ctx === null) return [$outcome, null];
        $adv = $ctx['adventure'];
        $adventureId = (int) $adv['id'];

        $errors = [];
        $title = trim(HtmlSanitizer::toPlainText((string) ($input['title'] ?? $adv['title'])));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) $errors['title'] = 'invalid';

        $description = trim(HtmlSanitizer::toPlainText(
            (string) ($input['description'] ?? (string) $adv['description'])
        ));
        if ($description === '' || mb_strlen($description) > 2000) $errors['description'] = 'invalid';

        $rating = (string) ($input['content_rating'] ?? $adv['content_rating']);
        if (!in_array($rating, ['everyone', 'teen', 'mature'], true)) $errors['content_rating'] = 'invalid';

        $genre = trim(HtmlSanitizer::toPlainText((string) ($input['genre'] ?? (string) $adv['genre'])));
        if (mb_strlen($genre) > 60) $errors['genre'] = 'invalid';

        $guidelinesHtml  = HtmlSanitizer::sanitize(
            (string) ($input['writing_guidelines'] ?? (string) $adv['writing_guidelines'])
        );
        $guidelinesPlain = HtmlSanitizer::toPlainText($guidelinesHtml);
        if (mb_strlen($guidelinesPlain) > 4000) $errors['writing_guidelines'] = 'too_long';

        if ($errors !== []) return [self::INVALID, ['fields' => $errors]];

        $this->pdo->prepare(
            "UPDATE adventures
                SET title = :t, description = :d, synopsis = :syn, genre = :g,
                    content_rating = :cr, writing_guidelines = :wg,
                    writing_guidelines_plain = :wp,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :a"
        )->execute([
            ':t' => $title, ':d' => $description, ':syn' => mb_substr($description, 0, 240),
            ':g' => $genre, ':cr' => $rating, ':wg' => $guidelinesHtml,
            ':wp' => $guidelinesPlain, ':a' => $adventureId,
        ]);

        $this->logActivity($adventureId, $userId, 'details_updated', null, null, $title);

        $fresh = $this->adventureById($adventureId);
        return [self::OK, ['adventure' => $this->adventureSummary($fresh ?? $adv)]];
    }

    /* ───────────────────────── Permissions ─────────────────────── */

    /** @return array{0:string,1:array<string,mixed>|null} */
    public function permissions(string $slug, ?int $userId, bool $isAdmin): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];

        $s = $this->pdo->prepare(
            'SELECT p.id, p.user_id, p.level, p.note, p.created_at,
                    u.username, u.display_name
               FROM adventure_permissions p
          LEFT JOIN users u ON u.id = p.user_id
              WHERE p.adventure_id = :a ORDER BY p.id'
        );
        $s->execute([':a' => $adventureId]);
        $perms = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $perms[] = [
                'id'       => (int) $r['id'],
                'user_id'  => (int) $r['user_id'],
                'username' => $r['username'],
                'display_name' => $r['display_name'],
                'level'    => (string) $r['level'],
                'note'     => $r['note'],
                'created_at' => (string) $r['created_at'],
            ];
        }

        $c = $this->pdo->prepare(
            'SELECT c.id, c.user_id, c.role, u.username, u.display_name
               FROM adventure_collaborators c
          LEFT JOIN users u ON u.id = c.user_id
              WHERE c.adventure_id = :a ORDER BY c.id'
        );
        $c->execute([':a' => $adventureId]);
        $collabs = [];
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $collabs[] = [
                'id'       => (int) $r['id'],
                'user_id'  => (int) $r['user_id'],
                'role'     => (string) $r['role'],
                'username' => $r['username'],
                'display_name' => $r['display_name'],
            ];
        }

        return [self::OK, [
            'role'          => $role,
            'capabilities'  => $this->capabilities($role, (string) $adv['state']),
            'permissions'   => $perms,
            'collaborators' => $collabs,
            'levels'        => self::PERMISSION_LEVELS,
        ]];
    }

    /**
     * Set (or clear) one contributor's standing on this adventure.
     * `blocked` also writes the contribution block the submission path
     * checks, so the two can never disagree.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function setPermission(
        string $slug,
        ?int $actorId,
        bool $isAdmin,
        int $targetUserId,
        ?string $level,
        string $note = ''
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $actorId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];
        if (!$this->canConfigure($role)) return [self::FORBIDDEN, null];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];
        if ($level !== null && !in_array($level, self::PERMISSION_LEVELS, true)) {
            return [self::INVALID, null];
        }
        if ($targetUserId === (int) $adv['author_id']) return [self::INVALID, ['reason' => 'owner']];

        $u = $this->pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
        $u->execute([':u' => $targetUserId]);
        if ($u->fetch() === false) return [self::NOT_FOUND, null];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM adventure_permissions WHERE adventure_id = :a AND user_id = :u'
            )->execute([':a' => $adventureId, ':u' => $targetUserId]);
            $this->pdo->prepare(
                'DELETE FROM contribution_blocks WHERE adventure_id = :a AND user_id = :u'
            )->execute([':a' => $adventureId, ':u' => $targetUserId]);

            if ($level !== null) {
                $this->pdo->prepare(
                    'INSERT INTO adventure_permissions (adventure_id, user_id, level, note, set_by)
                     VALUES (:a, :u, :l, :n, :s)'
                )->execute([
                    ':a' => $adventureId, ':u' => $targetUserId, ':l' => $level,
                    ':n' => $note !== '' ? $note : null, ':s' => $actorId,
                ]);
                if ($level === 'blocked') {
                    $this->pdo->prepare(
                        'INSERT INTO contribution_blocks (adventure_id, user_id, reason)
                         VALUES (:a, :u, :r)'
                    )->execute([':a' => $adventureId, ':u' => $targetUserId, ':r' => $note !== '' ? $note : null]);
                }
            }
            $this->logActivity($adventureId, $actorId, 'permission_set', null, $level, null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return $this->permissions($slug, $actorId, $isAdmin);
    }

    /**
     * Grant or remove a collaborator role (owner, editor, reviewer).
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function setCollaborator(
        string $slug,
        ?int $actorId,
        bool $isAdmin,
        int $targetUserId,
        ?string $newRole
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $actorId, $isAdmin);
        if (!$this->canConfigure($role)) return [self::FORBIDDEN, null];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];
        if ($targetUserId === (int) $adv['author_id']) return [self::INVALID, ['reason' => 'owner']];
        if ($newRole !== null && !in_array($newRole, ['owner', 'editor', 'reviewer'], true)) {
            return [self::INVALID, null];
        }

        if ($newRole === null) {
            $this->pdo->prepare(
                'DELETE FROM adventure_collaborators WHERE adventure_id = :a AND user_id = :u'
            )->execute([':a' => $adventureId, ':u' => $targetUserId]);
        } else {
            $u = $this->pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
            $u->execute([':u' => $targetUserId]);
            if ($u->fetch() === false) return [self::NOT_FOUND, null];
            $this->pdo->prepare(
                'INSERT INTO adventure_collaborators (adventure_id, user_id, role)
                 VALUES (:a, :u, :r)
                 ON CONFLICT(adventure_id, user_id) DO UPDATE SET role = excluded.role'
            )->execute([':a' => $adventureId, ':u' => $targetUserId, ':r' => $newRole]);
        }

        $this->logActivity($adventureId, $actorId, 'collaborator_set', null, $newRole, null);
        return $this->permissions($slug, $actorId, $isAdmin);
    }

    /* ───────────────────────── Reports ─────────────────────────── */

    /**
     * A reader report. Open to signed-out readers; the reporter is
     * recorded from the session and the request IP only.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function createReport(
        string $slug,
        ?int $reporterId,
        string $ip,
        string $reason,
        string $details,
        ?int $sceneId = null
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        if (!in_array($reason, self::REPORT_REASONS, true)) return [self::INVALID, null];
        $details = trim(HtmlSanitizer::toPlainText($details));
        if (mb_strlen($details) > self::NOTE_MAX) return [self::INVALID, null];

        $adventureId = (int) $adv['id'];
        if ($sceneId !== null && $this->sceneIn($adventureId, $sceneId) === null) {
            return [self::NOT_FOUND, null];
        }

        $this->pdo->prepare(
            'INSERT INTO content_reports (adventure_id, scene_id, reporter_id, reporter_ip, reason, details)
             VALUES (:a, :s, :u, :ip, :r, :d)'
        )->execute([
            ':a' => $adventureId, ':s' => $sceneId, ':u' => $reporterId,
            ':ip' => $ip, ':r' => $reason, ':d' => $details !== '' ? $details : null,
        ]);

        return [self::OK, ['report_id' => (int) $this->pdo->lastInsertId()]];
    }

    /** @return array{0:string,1:array<string,mixed>|null} */
    public function reports(string $slug, ?int $userId, bool $isAdmin, string $state = 'open'): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];
        if (!in_array($state, ['open', 'resolved', 'dismissed'], true)) return [self::INVALID, null];

        $s = $this->pdo->prepare(
            'SELECT r.*, s.title AS scene_title, u.username AS reporter_username
               FROM content_reports r
          LEFT JOIN scenes s ON s.id = r.scene_id
          LEFT JOIN users u  ON u.id = r.reporter_id
              WHERE r.adventure_id = :a AND r.state = :st
           ORDER BY r.id DESC'
        );
        $s->execute([':a' => $adventureId, ':st' => $state]);
        $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $items[] = [
                'id'          => (int) $r['id'],
                'reason'      => (string) $r['reason'],
                'details'     => $r['details'],
                'state'       => (string) $r['state'],
                'scene_id'    => $r['scene_id'] === null ? null : (int) $r['scene_id'],
                'scene_title' => $r['scene_title'],
                'reporter'    => $r['reporter_username'],
                'created_at'  => (string) $r['created_at'],
                'resolution_note' => $r['resolution_note'],
            ];
        }

        return [self::OK, [
            'state'        => $state,
            'role'         => $role,
            'capabilities' => $this->capabilities($role, (string) $adv['state']),
            'reports'      => $items,
        ]];
    }

    /** @return array{0:string,1:array<string,mixed>|null} */
    public function resolveReport(
        string $slug,
        int $reportId,
        ?int $userId,
        bool $isAdmin,
        string $action,
        string $note = ''
    ): array {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $adventureId = (int) $adv['id'];
        $role = $this->roleFor($adventureId, $userId, $isAdmin);
        if (!$this->canDecide($role)) return [self::FORBIDDEN, null];
        if (!in_array($action, ['resolve', 'dismiss'], true)) return [self::INVALID, null];

        $state = $action === 'resolve' ? 'resolved' : 'dismissed';
        $u = $this->pdo->prepare(
            "UPDATE content_reports
                SET state = :st, resolved_by = :by, resolution_note = :n,
                    resolved_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :i AND adventure_id = :a AND state = 'open'"
        );
        $u->execute([
            ':st' => $state, ':by' => $userId, ':n' => $note !== '' ? $note : null,
            ':i' => $reportId, ':a' => $adventureId,
        ]);
        if ($u->rowCount() !== 1) return [self::CONFLICT, null];

        $this->logActivity($adventureId, $userId, 'report_' . $action, 'open', $state, null);
        return [self::OK, ['report_id' => $reportId, 'state' => $state]];
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    /**
     * Shared gate for story edits: adventure must exist, the caller
     * must be able to decide, and the adventure must be writable.
     *
     * @return array{0:string,1:array{adventure:array<string,mixed>,role:string}|null}
     */
    private function storyGate(string $slug, ?int $userId, bool $isAdmin): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $role = $this->roleFor((int) $adv['id'], $userId, $isAdmin);
        if (!$this->canView($role)) return [self::FORBIDDEN, null];
        if (!$this->canDecide($role)) return [self::FORBIDDEN, null];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, null];
        return [self::OK, ['adventure' => $adv, 'role' => (string) $role]];
    }

    /** @return array<string,mixed>|null */
    private function sceneIn(int $adventureId, int $sceneId): ?array
    {
        $s = $this->pdo->prepare(
            'SELECT * FROM scenes WHERE id = :i AND adventure_id = :a LIMIT 1'
        );
        $s->execute([':i' => $sceneId, ':a' => $adventureId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,string>,1:array<string,mixed>}
     */
    private function validateBranchContent(array $input): array
    {
        $e = [];
        $choice = trim(HtmlSanitizer::toPlainText((string) ($input['choice_text'] ?? '')));
        if (mb_strlen($choice) < BranchSubmissionService::CHOICE_MIN
            || mb_strlen($choice) > BranchSubmissionService::CHOICE_MAX) {
            $e['choice_text'] = 'invalid';
        }

        $title = trim(HtmlSanitizer::toPlainText((string) ($input['scene_title'] ?? '')));
        if (mb_strlen($title) < BranchSubmissionService::TITLE_MIN
            || mb_strlen($title) > BranchSubmissionService::TITLE_MAX) {
            $e['scene_title'] = 'invalid';
        }

        $bodyHtml  = HtmlSanitizer::sanitize((string) ($input['scene_body'] ?? ''));
        $bodyPlain = HtmlSanitizer::toPlainText($bodyHtml);
        $len = mb_strlen($bodyPlain);
        if ($len < BranchSubmissionService::BODY_MIN || $len > BranchSubmissionService::BODY_MAX) {
            $e['scene_body'] = 'invalid';
        }

        $type = (string) ($input['scene_type'] ?? 'story');
        if (!in_array($type, BranchSubmissionService::SCENE_TYPES, true)) $e['scene_type'] = 'invalid';

        return [$e, [
            'choice_text'      => $choice,
            'scene_title'      => $title,
            'scene_body'       => $bodyHtml,
            'scene_body_plain' => $bodyPlain,
            'scene_type'       => $type,
        ]];
    }

    /** @param array<string,mixed> $v */
    private function insertScene(int $adventureId, array $v): int
    {
        $n = $this->pdo->prepare(
            'SELECT COALESCE(MAX(scene_number), 0) AS n FROM scenes WHERE adventure_id = :a'
        );
        $n->execute([':a' => $adventureId]);
        $number = ((int) ($n->fetch()['n'] ?? 0)) + 1;

        $base = AdventureService::slugify((string) $v['scene_title']);
        $slug = $base;
        $i = 1;
        $check = $this->pdo->prepare('SELECT 1 FROM scenes WHERE adventure_id = :a AND slug = :s LIMIT 1');
        while (true) {
            $check->execute([':a' => $adventureId, ':s' => $slug]);
            if ($check->fetch() === false) break;
            $i++;
            $slug = $base . '-' . $i;
        }

        $this->pdo->prepare(
            "INSERT INTO scenes
                (adventure_id, slug, scene_number, title, body, body_plain,
                 scene_type, state, is_start)
             VALUES (:a, :s, :n, :t, :b, :bp, :ty, 'published', 0)"
        )->execute([
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

        $this->pdo->prepare(
            'INSERT INTO choices (scene_id, target_scene_id, label, position)
             VALUES (:s, :t, :l, :p)'
        )->execute([':s' => $sceneId, ':t' => $targetId, ':l' => $label, ':p' => $position]);
        return (int) $this->pdo->lastInsertId();
    }

    private function logActivity(
        int $adventureId,
        ?int $userId,
        string $action,
        ?string $from,
        ?string $to,
        ?string $note
    ): void {
        $this->pdo->prepare(
            'INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state, note)
             VALUES (:a, :u, :act, :f, :t, :n)'
        )->execute([
            ':a' => $adventureId, ':u' => $userId, ':act' => $action,
            ':f' => $from, ':t' => $to, ':n' => $note,
        ]);
    }
}
