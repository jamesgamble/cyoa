<?php
/**
 * PublicationService — drafts, preview, and publishing (v0.17.0).
 *
 * Responsibilities:
 *
 *   • authorisation — every read and write is gated on a role derived
 *     server-side from the adventure author, the collaborator roster,
 *     and the administrator flag. A role supplied by the client is
 *     never trusted;
 *   • visibility — drafts (and every unpublished scene) are readable
 *     only through the manage/preview payloads, which require a role.
 *     The public read layer (PublicRepository) is untouched;
 *   • publication rules — publishing requires a valid opening scene;
 *     archived adventures are read-only; unpublishing only changes
 *     state and never deletes content;
 *   • activity — every accepted status change writes an
 *     adventure_activity row inside the same transaction as the
 *     state update, so the log can never drift from the state.
 */

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class PublicationService
{
    public const OK           = 'ok';
    public const FORBIDDEN    = 'forbidden';
    public const NOT_FOUND    = 'not_found';
    public const INVALID      = 'invalid';
    public const READ_ONLY    = 'read_only';
    public const NO_OPENING   = 'no_opening_scene';

    public const ROLE_OWNER  = 'owner';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_ADMIN  = 'administrator';

    /** action => resulting state. */
    public const ACTIONS = [
        'publish'         => 'published',
        'unpublish'       => 'draft',
        'set_in_progress' => 'published',
        'set_complete'    => 'complete',
        'set_on_hold'     => 'on-hold',
        'archive'         => 'archived',
    ];

    /** Actions the interface must confirm before sending. */
    public const CONFIRM_ACTIONS = ['publish', 'unpublish', 'archive'];

    /** Allowed source states per action. */
    private const ALLOWED_FROM = [
        'publish'         => ['draft', 'on-hold', 'complete'],
        'unpublish'       => ['published', 'on-hold', 'complete'],
        'set_in_progress' => ['published', 'on-hold', 'complete'],
        'set_complete'    => ['published', 'on-hold'],
        'set_on_hold'     => ['published', 'complete'],
        'archive'         => ['draft', 'published', 'on-hold', 'complete'],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ───────────────────────── Authorisation ───────────────────── */

    /** @return array<string,mixed>|null */
    public function adventureBySlug(string $slug): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Effective role of $userId on $adventureId, or null when the user
     * is not authorised to see drafts or previews at all.
     */
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
        $crow = $c->fetch(PDO::FETCH_ASSOC);
        if ($crow === false) return null;
        return (string) $crow['role'];
    }

    public function canPreview(?string $role): bool
    {
        return $role !== null;
    }

    /** Owners, editors, and administrators may act; nobody else. */
    public function canManage(?string $role): bool
    {
        return $role !== null;
    }

    /* ───────────────────────── Rules ───────────────────────────── */

    /** A publishable opening scene: marked start, with body text. */
    public function hasValidOpeningScene(int $adventureId): bool
    {
        $s = $this->pdo->prepare(
            'SELECT title, body, body_plain FROM scenes
              WHERE adventure_id = :a AND is_start = 1
              ORDER BY scene_number LIMIT 1'
        );
        $s->execute([':a' => $adventureId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return false;
        if (trim((string) $row['title']) === '') return false;
        $plain = (string) ($row['body_plain'] ?? '');
        if (trim($plain) === '') {
            $plain = HtmlSanitizer::toPlainText((string) $row['body']);
        }
        return trim($plain) !== '';
    }

    public function isReadOnly(string $state): bool
    {
        return $state === 'archived' || $state === 'suspended';
    }

    /**
     * Actions currently available to $role on an adventure in $state.
     *
     * @return list<string>
     */
    public function availableActions(string $state, ?string $role, int $adventureId): array
    {
        if ($role === null) return [];
        if ($this->isReadOnly($state)) return [];
        $out = [];
        foreach (self::ACTIONS as $action => $_to) {
            if (!in_array($state, self::ALLOWED_FROM[$action], true)) continue;
            if ($action === 'set_in_progress' && $state === 'published') continue;
            if ($action === 'publish' && !$this->hasValidOpeningScene($adventureId)) continue;
            $out[] = $action;
        }
        return $out;
    }

    /* ───────────────────────── Drafts ──────────────────────────── */

    /**
     * Save draft edits. Content only — never state, never ownership.
     *
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,string>}
     */
    public function saveDraft(int $adventureId, ?string $role, array $input): array
    {
        if (!$this->canManage($role)) return [self::FORBIDDEN, []];
        $adv = $this->adventureById($adventureId);
        if ($adv === null) return [self::NOT_FOUND, []];
        if ($this->isReadOnly((string) $adv['state'])) return [self::READ_ONLY, []];

        $errors = [];
        $title = trim((string) ($input['title'] ?? $adv['title']));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120) $errors['title'] = 'invalid';

        $description = trim((string) ($input['description'] ?? (string) $adv['description']));
        if ($description === '' || mb_strlen($description) > 2000) $errors['description'] = 'invalid';

        $guidelinesHtml = HtmlSanitizer::sanitize(
            (string) ($input['writing_guidelines'] ?? (string) $adv['writing_guidelines'])
        );
        $guidelinesPlain = HtmlSanitizer::toPlainText($guidelinesHtml);
        if (mb_strlen($guidelinesPlain) > 4000) $errors['writing_guidelines'] = 'too_long';

        $hasOpening = array_key_exists('opening_body', $input);
        $openingHtml = $hasOpening ? HtmlSanitizer::sanitize((string) $input['opening_body']) : '';
        $openingPlain = $hasOpening ? HtmlSanitizer::toPlainText($openingHtml) : '';
        if ($hasOpening && mb_strlen($openingPlain) > 20000) $errors['opening_body'] = 'invalid';

        if ($errors !== []) return [self::INVALID, $errors];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE adventures
                    SET title = :t, description = :d, synopsis = :syn,
                        writing_guidelines = :g, writing_guidelines_plain = :gp,
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :a"
            )->execute([
                ':t' => $title, ':d' => $description,
                ':syn' => mb_substr($description, 0, 240),
                ':g' => $guidelinesHtml, ':gp' => $guidelinesPlain, ':a' => $adventureId,
            ]);

            if ($hasOpening) {
                $this->pdo->prepare(
                    "UPDATE scenes
                        SET body = :b, body_plain = :bp,
                            updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                      WHERE adventure_id = :a AND is_start = 1"
                )->execute([':b' => $openingHtml, ':bp' => $openingPlain, ':a' => $adventureId]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return [self::OK, []];
    }

    /* ───────────────────────── Status changes ──────────────────── */

    /**
     * Apply a status change, writing the activity record in the same
     * transaction as the state update.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function changeStatus(int $adventureId, ?int $userId, ?string $role, string $action): array
    {
        if (!$this->canManage($role)) return [self::FORBIDDEN, null];
        if (!isset(self::ACTIONS[$action])) return [self::INVALID, null];

        $adv = $this->adventureById($adventureId);
        if ($adv === null) return [self::NOT_FOUND, null];
        $from = (string) $adv['state'];

        if ($this->isReadOnly($from)) return [self::READ_ONLY, null];
        if (!in_array($from, self::ALLOWED_FROM[$action], true)) return [self::INVALID, null];

        $to = self::ACTIONS[$action];
        if (($action === 'publish' || $action === 'set_in_progress')
            && !$this->hasValidOpeningScene($adventureId)) {
            return [self::NO_OPENING, null];
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE adventures
                    SET state = :s, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :a"
            )->execute([':s' => $to, ':a' => $adventureId]);

            // Publishing makes the opening scene readable. Unpublishing
            // only changes adventure state — scenes and choices remain.
            if ($action === 'publish') {
                $this->pdo->prepare(
                    "UPDATE scenes SET state = 'published'
                      WHERE adventure_id = :a AND is_start = 1 AND state = 'draft'"
                )->execute([':a' => $adventureId]);
            }

            $this->pdo->prepare(
                'INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state)
                 VALUES (:a, :u, :act, :from, :to)'
            )->execute([
                ':a' => $adventureId, ':u' => $userId, ':act' => $action,
                ':from' => $from, ':to' => $to,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return [self::OK, [
            'state'   => $to,
            'from'    => $from,
            'action'  => $action,
            'actions' => $this->availableActions($to, $role, $adventureId),
        ]];
    }

    /* ───────────────────────── Payloads ────────────────────────── */

    /** @return list<array<string,mixed>> */
    public function activity(int $adventureId, int $limit = 50): array
    {
        $s = $this->pdo->prepare(
            'SELECT a.id, a.action, a.from_state, a.to_state, a.created_at,
                    u.display_name AS actor
               FROM adventure_activity a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE a.adventure_id = :a
           ORDER BY a.id DESC LIMIT :l'
        );
        $s->bindValue(':a', $adventureId, PDO::PARAM_INT);
        $s->bindValue(':l', $limit, PDO::PARAM_INT);
        $s->execute();
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id'         => (int) $r['id'],
                'action'     => (string) $r['action'],
                'from_state' => $r['from_state'],
                'to_state'   => $r['to_state'],
                'actor'      => $r['actor'],
                'created_at' => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Manage payload: adventure state, role, available actions, and
     * the activity log. Requires a role.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function managePayload(string $slug, ?int $userId, bool $isAdmin = false): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $role = $this->roleFor((int) $adv['id'], $userId, $isAdmin);
        if (!$this->canManage($role)) return [self::FORBIDDEN, null];

        $id    = (int) $adv['id'];
        $state = (string) $adv['state'];
        return [self::OK, [
            'adventure' => [
                'id'          => $id,
                'slug'        => (string) $adv['slug'],
                'title'       => (string) $adv['title'],
                'description' => (string) ($adv['description'] ?? ''),
                'state'       => $state,
                'visibility'  => (string) $adv['visibility'],
                'updated_at'  => (string) $adv['updated_at'],
                'writing_guidelines' => (string) ($adv['writing_guidelines'] ?? ''),
            ],
            'role'                  => $role,
            'read_only'             => $this->isReadOnly($state),
            'has_valid_opening'     => $this->hasValidOpeningScene($id),
            'available_actions'     => $this->availableActions($state, $role, $id),
            'confirm_actions'       => self::CONFIRM_ACTIONS,
            'activity'              => $this->activity($id),
        ]];
    }

    /**
     * Preview payload: every scene, including drafts. Requires a role
     * — an anonymous or unrelated reader gets `forbidden`, never the
     * draft content.
     *
     * @return array{0:string,1:array<string,mixed>|null}
     */
    public function previewPayload(string $slug, ?int $userId, bool $isAdmin = false): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        $role = $this->roleFor((int) $adv['id'], $userId, $isAdmin);
        if (!$this->canPreview($role)) return [self::FORBIDDEN, null];

        $id = (int) $adv['id'];
        $s = $this->pdo->prepare(
            'SELECT id, slug, scene_number, chapter, title, body, scene_type,
                    state, is_start
               FROM scenes WHERE adventure_id = :a ORDER BY scene_number'
        );
        $s->execute([':a' => $id]);
        $scenes = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $scenes[] = [
                'id'           => (int) $r['id'],
                'slug'         => (string) $r['slug'],
                'sceneNumber'  => (int) $r['scene_number'],
                'chapter'      => $r['chapter'],
                'title'        => (string) $r['title'],
                'body'         => HtmlSanitizer::sanitize((string) $r['body']),
                'isEnding'     => (string) $r['scene_type'] === 'ending',
                'state'        => (string) $r['state'],
                'isStart'      => (int) $r['is_start'] === 1,
            ];
        }

        return [self::OK, [
            'adventure' => [
                'slug'  => (string) $adv['slug'],
                'title' => (string) $adv['title'],
                'state' => (string) $adv['state'],
            ],
            'role'     => $role,
            'noindex'  => true,
            'scenes'   => $scenes,
        ]];
    }

    /* ───────────────────────── Helpers ─────────────────────────── */

    /** @return array<string,mixed>|null */
    private function adventureById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE id = :a LIMIT 1');
        $s->execute([':a' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Grant an editor. Owners and administrators only. */
    public function addCollaborator(int $adventureId, ?string $role, int $userId, string $newRole = 'editor'): bool
    {
        if ($role !== self::ROLE_OWNER && $role !== self::ROLE_ADMIN) return false;
        if (!in_array($newRole, ['owner', 'editor'], true)) return false;
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO adventure_collaborators (adventure_id, user_id, role)
             VALUES (:a, :u, :r)'
        )->execute([':a' => $adventureId, ':u' => $userId, ':r' => $newRole]);
        return true;
    }
}
