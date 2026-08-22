<?php
/**
 * AdventureService — adventure creation (v0.16.0).
 *
 * The five-step wizard (Basics, Opening scene, Contributions, Writing
 * guidelines, Review) posts one payload. This service is the single
 * authority for it:
 *
 *   • authorisation — only an *active*, registered user may create;
 *     the owner is always the authenticated caller, never a value
 *     supplied in the request body;
 *   • limits — a rolling per-hour rate limit and a configurable
 *     maximum number of adventures per user;
 *   • sanitisation — rich text (opening body, writing guidelines)
 *     passes through App\HtmlSanitizer; the derived plain text is
 *     stored alongside it for character limits, search, snippets,
 *     duplicate detection, and plain-text exports;
 *   • atomicity — the adventure, its opening scene, and its content
 *     warnings are written in ONE transaction. Any failure rolls the
 *     whole thing back, so a half-created adventure can never exist.
 *
 * Templates configure settings only: they pre-fill contribution mode,
 * anonymity, visibility, and branch limits. They never bypass
 * validation and are re-applied server-side before validation runs.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class AdventureService
{
    public const OK            = 'ok';
    public const INVALID       = 'invalid';
    public const FORBIDDEN     = 'forbidden';
    public const RATE_LIMITED  = 'rate_limited';
    public const LIMIT_REACHED = 'limit_reached';

    public const GENRES = [
        'fantasy', 'science-fiction', 'mystery', 'horror',
        'romance', 'historical', 'contemporary', 'folklore',
    ];
    public const RATINGS            = ['everyone', 'teen', 'mature'];
    public const VISIBILITIES       = ['public', 'unlisted'];
    public const CONTRIBUTION_MODES = ['immediate', 'approval', 'closed'];
    /** Adventure statuses selectable at creation time. */
    public const STATUSES = ['draft', 'published'];

    public const MAX_BRANCHES_MIN = 2;
    public const MAX_BRANCHES_MAX = 10;

    /**
     * Templates configure settings only.
     *
     * @var array<string, array<string,mixed>>
     */
    public const TEMPLATES = [
        'solo' => [
            'label'                   => 'Solo story',
            'visibility'              => 'public',
            'contribution_mode'       => 'closed',
            'anonymous_contributions' => false,
            'max_branches_per_scene'  => 4,
            'requires_passcode'       => false,
        ],
        'open-community' => [
            'label'                   => 'Open community story',
            'visibility'              => 'public',
            'contribution_mode'       => 'immediate',
            'anonymous_contributions' => true,
            'max_branches_per_scene'  => 6,
            'requires_passcode'       => false,
        ],
        'moderated-community' => [
            'label'                   => 'Moderated community story',
            'visibility'              => 'public',
            'contribution_mode'       => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene'  => 4,
            'requires_passcode'       => false,
        ],
        'private-group' => [
            'label'                   => 'Private group story',
            'visibility'              => 'unlisted',
            'contribution_mode'       => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene'  => 3,
            'requires_passcode'       => true,
        ],
    ];

    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->settings = new SettingsRepository($pdo);
    }

    /* ───────────────────────── Templates ───────────────────────── */

    /** @return list<array<string,mixed>> */
    public static function templates(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $key => $t) {
            $out[] = ['key' => $key] + $t;
        }
        return $out;
    }

    /**
     * Apply a template's settings to a payload. Explicit user choices
     * win; the template only supplies values the payload omits.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function applyTemplate(array $input): array
    {
        $key = isset($input['template']) ? (string) $input['template'] : '';
        if (!isset(self::TEMPLATES[$key])) {
            return $input;
        }
        $t = self::TEMPLATES[$key];
        foreach (['visibility', 'contribution_mode', 'anonymous_contributions', 'max_branches_per_scene'] as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === '' || $input[$field] === null) {
                $input[$field] = $t[$field];
            }
        }
        return $input;
    }

    /* ───────────────────────── Limits ──────────────────────────── */

    /** @return array<string,mixed> */
    public function creationLimits(int $userId): array
    {
        $max     = max(1, $this->settings->getInt('max_adventures_per_user', 20));
        $perHour = max(1, $this->settings->getInt('adventures_per_user_per_hour', 5));
        return [
            'max_adventures_per_user'      => $max,
            'adventures_per_user_per_hour' => $perHour,
            'owned'                        => $this->countOwned($userId),
            'recent'                       => $this->countRecent($userId),
        ];
    }

    private function countOwned(int $userId): int
    {
        $s = $this->pdo->prepare('SELECT COUNT(*) AS c FROM adventures WHERE author_id = :u');
        $s->execute([':u' => $userId]);
        return (int) ($s->fetch()['c'] ?? 0);
    }

    private function countRecent(int $userId): int
    {
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM adventure_creation_attempts
              WHERE user_id = :u
                AND created_at > strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-1 hour')"
        );
        $s->execute([':u' => $userId]);
        return (int) ($s->fetch()['c'] ?? 0);
    }

    /* ───────────────────────── Validation ──────────────────────── */

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,string>,1:array<string,mixed>} errors, normalised
     */
    public function validate(array $input): array
    {
        $input = self::applyTemplate($input);
        $e = [];

        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) $e['title'] = 'invalid';

        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 2000) $e['description'] = 'invalid';

        $genre = (string) ($input['genre'] ?? '');
        if (!in_array($genre, self::GENRES, true)) $e['genre'] = 'invalid';

        $rating = (string) ($input['content_rating'] ?? '');
        if (!in_array($rating, self::RATINGS, true)) $e['content_rating'] = 'invalid';

        $warnings = [];
        $rawWarnings = $input['content_warnings'] ?? [];
        if (is_array($rawWarnings)) {
            foreach ($rawWarnings as $w) {
                $w = trim((string) $w);
                if ($w === '') continue;
                if (mb_strlen($w) > 60) { $e['content_warnings'] = 'too_long'; break; }
                $warnings[] = $w;
            }
            if (count($warnings) > 10) $e['content_warnings'] = 'too_many';
        } else {
            $e['content_warnings'] = 'invalid';
        }

        $visibility = (string) ($input['visibility'] ?? 'public');
        if (!in_array($visibility, self::VISIBILITIES, true)) $e['visibility'] = 'invalid';

        $sceneTitle = trim((string) ($input['opening_title'] ?? ''));
        if ($sceneTitle === '' || mb_strlen($sceneTitle) > 120) $e['opening_title'] = 'invalid';

        $bodyHtml  = HtmlSanitizer::sanitize((string) ($input['opening_body'] ?? ''));
        $bodyPlain = HtmlSanitizer::toPlainText($bodyHtml);
        $bodyLen   = mb_strlen($bodyPlain);
        if ($bodyLen < 1 || $bodyLen > 20000) $e['opening_body'] = 'invalid';

        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) $e['status'] = 'invalid';

        $mode = (string) ($input['contribution_mode'] ?? '');
        if (!in_array($mode, self::CONTRIBUTION_MODES, true)) $e['contribution_mode'] = 'invalid';

        $anon = !empty($input['anonymous_contributions']);

        $branches = (int) ($input['max_branches_per_scene'] ?? 0);
        if ($branches < self::MAX_BRANCHES_MIN || $branches > self::MAX_BRANCHES_MAX) {
            $e['max_branches_per_scene'] = 'invalid';
        }

        $passcode = (string) ($input['contribution_passcode'] ?? '');
        if ($passcode !== '' && (mb_strlen($passcode) < 6 || mb_strlen($passcode) > 100)) {
            $e['contribution_passcode'] = 'invalid';
        }

        $guidelinesHtml  = HtmlSanitizer::sanitize((string) ($input['writing_guidelines'] ?? ''));
        $guidelinesPlain = HtmlSanitizer::toPlainText($guidelinesHtml);
        if (mb_strlen($guidelinesPlain) > 4000) $e['writing_guidelines'] = 'too_long';

        $templateKey = (string) ($input['template'] ?? '');
        if ($templateKey !== '' && !isset(self::TEMPLATES[$templateKey])) $e['template'] = 'invalid';

        return [$e, [
            'title'                   => $title,
            'description'             => $description,
            'genre'                   => $genre,
            'content_rating'          => $rating,
            'content_warnings'        => $warnings,
            'visibility'              => $visibility,
            'opening_title'           => $sceneTitle,
            'opening_body'            => $bodyHtml,
            'opening_body_plain'      => $bodyPlain,
            'status'                  => $status,
            'contribution_mode'       => $mode,
            'anonymous_contributions' => $anon,
            'max_branches_per_scene'  => $branches,
            'contribution_passcode'   => $passcode,
            'writing_guidelines'      => $guidelinesHtml,
            'writing_guidelines_plain'=> $guidelinesPlain,
            'template'                => $templateKey !== '' ? $templateKey : null,
        ]];
    }

    /* ───────────────────────── Creation ────────────────────────── */

    /**
     * Create an adventure and its opening scene in one transaction.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,string>,2:array<string,mixed>|null}
     */
    public function create(int $userId, array $input, string $ip = ''): array
    {
        if (!$this->isActiveUser($userId)) {
            return [self::FORBIDDEN, ['account' => 'not_active'], null];
        }

        $limits = $this->creationLimits($userId);
        if ($limits['owned'] >= $limits['max_adventures_per_user']) {
            return [self::LIMIT_REACHED, ['account' => 'limit_reached'], null];
        }
        if ($limits['recent'] >= $limits['adventures_per_user_per_hour']) {
            return [self::RATE_LIMITED, ['account' => 'rate_limited'], null];
        }

        [$errors, $v] = $this->validate($input);
        if ($errors !== []) {
            return [self::INVALID, $errors, null];
        }

        $slug = $this->uniqueSlug($v['title']);
        $sceneSlug = $this->uniqueSceneSlug($v['opening_title']);
        $passHash = $v['contribution_passcode'] !== ''
            ? PasswordHasher::hash($v['contribution_passcode'])['hash']
            : null;
        $sceneState = $v['status'] === 'published' ? 'published' : 'draft';

        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare(
                'INSERT INTO adventures
                    (slug, title, author_id, synopsis, description, genre,
                     content_rating, state, visibility, contribution_state,
                     writing_guidelines, anonymous_contributions,
                     max_branches_per_scene, contribution_passcode_hash,
                     writing_guidelines_plain, template_key)
                 VALUES
                    (:slug, :title, :author, :syn, :desc, :genre,
                     :rating, :state, :vis, :contrib,
                     :guide, :anon, :branches, :pass, :guide_plain, :tpl)'
            );
            $ins->execute([
                ':slug'   => $slug,
                ':title'  => $v['title'],
                ':author' => $userId,
                ':syn'    => mb_substr($v['description'], 0, 240),
                ':desc'   => $v['description'],
                ':genre'  => $v['genre'],
                ':rating' => $v['content_rating'],
                ':state'  => $v['status'],
                ':vis'    => $v['visibility'],
                ':contrib'=> $v['contribution_mode'],
                ':guide'  => $v['writing_guidelines'],
                ':anon'   => $v['anonymous_contributions'] ? 1 : 0,
                ':branches' => $v['max_branches_per_scene'],
                ':pass'   => $passHash,
                ':guide_plain' => $v['writing_guidelines_plain'],
                ':tpl'    => $v['template'],
            ]);
            $adventureId = (int) $this->pdo->lastInsertId();

            $scene = $this->pdo->prepare(
                'INSERT INTO scenes
                    (adventure_id, slug, scene_number, title, body, body_plain,
                     scene_type, state, is_start)
                 VALUES (:a, :s, 1, :t, :b, :bp, \'story\', :st, 1)'
            );
            $scene->execute([
                ':a'  => $adventureId,
                ':s'  => $sceneSlug,
                ':t'  => $v['opening_title'],
                ':b'  => $v['opening_body'],
                ':bp' => $v['opening_body_plain'],
                ':st' => $sceneState,
            ]);
            $sceneId = (int) $this->pdo->lastInsertId();

            if ($v['content_warnings'] !== []) {
                $w = $this->pdo->prepare(
                    'INSERT INTO content_warnings (adventure_id, label, position)
                     VALUES (:a, :l, :p)'
                );
                foreach ($v['content_warnings'] as $i => $label) {
                    $w->execute([':a' => $adventureId, ':l' => $label, ':p' => $i]);
                }
            }

            $this->pdo->prepare(
                'INSERT INTO adventure_creation_attempts (user_id, ip) VALUES (:u, :ip)'
            )->execute([':u' => $userId, ':ip' => $ip]);

            $this->pdo->commit();
        } catch (Throwable $ex) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $ex;
        }

        return [self::OK, [], [
            'id'          => $adventureId,
            'slug'        => $slug,
            'title'       => $v['title'],
            'state'       => $v['status'],
            'visibility'  => $v['visibility'],
            'opening_scene' => ['id' => $sceneId, 'slug' => $sceneSlug, 'title' => $v['opening_title']],
        ]];
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    private function isActiveUser(int $userId): bool
    {
        $s = $this->pdo->prepare('SELECT status FROM users WHERE id = :u LIMIT 1');
        $s->execute([':u' => $userId]);
        $row = $s->fetch();
        return $row !== false && (string) $row['status'] === 'active';
    }

    public static function slugify(string $text): string
    {
        $s = mb_strtolower(trim($text));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') $s = 'adventure';
        return mb_substr($s, 0, 60);
    }

    private function uniqueSlug(string $title): string
    {
        $base = self::slugify($title);
        $slug = $base;
        $n = 1;
        $stmt = $this->pdo->prepare('SELECT 1 FROM adventures WHERE slug = :s LIMIT 1');
        while (true) {
            $stmt->execute([':s' => $slug]);
            if ($stmt->fetch() === false) return $slug;
            $n++;
            $slug = $base . '-' . $n;
        }
    }

    /** Opening scenes are unique within their (brand new) adventure. */
    private function uniqueSceneSlug(string $title): string
    {
        return self::slugify($title);
    }
}
