<?php
/**
 * NotificationService — the single door every in-site notification
 * goes through (v0.22.0).
 *
 * Responsibilities:
 *   • emit a notification row for a recipient;
 *   • decide whether that notification also becomes email — either
 *     immediately, or as one row of an aggregated digest;
 *   • read, mark read, and delete the recipient's inbox;
 *   • store per-kind email preferences, with security and recovery
 *     mail permanently on;
 *   • manage adventure follows, which are an update subscription and
 *     deliberately distinct from bookmarks (a reading position).
 *
 * Follower counts are never returned by any method here. The product
 * does not expose them.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class NotificationService
{
    public const OK        = 'ok';
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * Every notification kind the product produces.
     *
     *   label   — shown in the preferences table;
     *   routine — the recipient may delete it from their inbox;
     *   email   — default email setting for a new account;
     *   locked  — the email setting cannot be turned off;
     *   digest  — email is aggregated instead of sent immediately.
     *
     * @var array<string, array{label:string, routine:bool, email:bool, locked:bool, digest:bool}>
     */
    public const KINDS = [
        'submission_received' => [
            'label' => 'A branch was submitted to your adventure',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => false,
        ],
        'submission_approved' => [
            'label' => 'Your submission was approved',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => false,
        ],
        'submission_rejected' => [
            'label' => 'Your submission was rejected',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => false,
        ],
        'changes_requested' => [
            'label' => 'Changes were requested on your submission',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => false,
        ],
        'submission_resubmitted' => [
            'label' => 'A contributor resubmitted a branch',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => false,
        ],
        'review_needed' => [
            'label' => 'A submission is waiting for your review',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => false,
        ],
        'collaborator_invitation' => [
            'label' => 'You were invited to collaborate',
            'routine' => false, 'email' => true,  'locked' => false, 'digest' => false,
        ],
        'ownership_transfer' => [
            'label' => 'Ownership of an adventure changed',
            'routine' => false, 'email' => true,  'locked' => false, 'digest' => false,
        ],
        'followed_adventure_updated' => [
            'label' => 'An adventure you follow was updated',
            'routine' => true,  'email' => true,  'locked' => false, 'digest' => true,
        ],
        'account_security' => [
            'label' => 'Security and account recovery',
            'routine' => false, 'email' => true,  'locked' => true,  'digest' => false,
        ],
    ];

    private PDO $pdo;
    private EmailQueueRepository $queue;
    private SettingsRepository $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->queue    = new EmailQueueRepository($pdo);
        $this->settings = new SettingsRepository($pdo);
    }

    /* ─────────────────────────── Emitting ─────────────────────────── */

    /**
     * Create one notification and route its email, if any.
     *
     * @param array<string,mixed> $opts  adventure_id, url, actor_id
     * @return int the notification id (0 when suppressed)
     */
    /**
     * Legacy kinds from v0.20.0 keep their own row label but inherit
     * the delivery rules — and the single email preference — of the
     * canonical kind they belong to.
     */
    public const ALIASES = [
        'invitation'           => 'collaborator_invitation',
        'invitation_accepted'  => 'collaborator_invitation',
        'invitation_declined'  => 'collaborator_invitation',
        'role_changed'         => 'collaborator_invitation',
        'role_removed'         => 'collaborator_invitation',
        'ownership_received'   => 'ownership_transfer',
        'ownership_transferred'=> 'ownership_transfer',
    ];

    /** The canonical kind whose settings govern $kind. */
    public static function canonicalKind(string $kind): string
    {
        return self::ALIASES[$kind] ?? $kind;
    }

    public function emit(
        int $userId,
        string $kind,
        string $title,
        string $body = '',
        ?string $url = null,
        ?int $adventureId = null,
        array $opts = []
    ): int {
        $canonical = self::canonicalKind($kind);
        if (!isset(self::KINDS[$canonical])) {
            throw new \InvalidArgumentException('unknown notification kind');
        }
        // Never notify a user about their own action.
        $actorId = isset($opts['actor_id']) ? (int) $opts['actor_id'] : null;
        if ($actorId !== null && $actorId === $userId) return 0;
        if (!$this->userExists($userId)) return 0;

        $meta = self::KINDS[$canonical];
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, kind, title, body, url, adventure_id, routine)
             VALUES (:u, :k, :t, :b, :url, :a, :r)'
        );
        $stmt->execute([
            ':u' => $userId, ':k' => $kind, ':t' => $title, ':b' => $body,
            ':url' => $url, ':a' => $adventureId, ':r' => $meta['routine'] ? 1 : 0,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $wantsEmail = ($opts['email'] ?? true) !== false;
        if ($wantsEmail && $this->emailEnabled($userId, $canonical)) {
            if ($meta['digest']) {
                $this->pdo->prepare(
                    'INSERT INTO notification_digests
                        (user_id, adventure_id, notification_id, summary)
                     VALUES (:u, :a, :n, :s)'
                )->execute([
                    ':u' => $userId, ':a' => $adventureId, ':n' => $id,
                    ':s' => $title . ($body !== '' ? ' — ' . $body : ''),
                ]);
            } else {
                $this->enqueueAlert($userId, $title, $body, $url);
            }
        }
        return $id;
    }

    /** Emit the same notification to several recipients. */
    public function emitMany(
        array $userIds,
        string $kind,
        string $title,
        string $body = '',
        ?string $url = null,
        ?int $adventureId = null,
        array $opts = []
    ): int {
        $sent = 0;
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid <= 0) continue;
            if ($this->emit($uid, $kind, $title, $body, $url, $adventureId, $opts) > 0) $sent++;
        }
        return $sent;
    }

    /* ─────────────────────────── Inbox ────────────────────────────── */

    /** @return array<int,array<string,mixed>> */
    public function inbox(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $s = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.title, n.body, n.url, n.adventure_id,
                    n.read_at, n.created_at, n.routine, a.slug AS adventure_slug
               FROM notifications n
          LEFT JOIN adventures a ON a.id = n.adventure_id
              WHERE n.user_id = :u
              ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $limit
        );
        $s->execute([':u' => $userId]);
        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['routine'] = (int) $r['routine'] === 1;
            $r['label']   = self::KINDS[self::canonicalKind((string) $r['kind'])]['label'] ?? 'Notification';
            $rows[] = $r;
        }
        return $rows;
    }

    public function unreadCount(int $userId): int
    {
        $s = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = :u AND read_at IS NULL'
        );
        $s->execute([':u' => $userId]);
        return (int) ($s->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    }

    /** Mark one notification read, or all of them when $id is null. */
    public function markRead(int $userId, ?int $id): void
    {
        if ($id === null) {
            $this->pdo->prepare(
                "UPDATE notifications SET read_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE user_id = :u AND read_at IS NULL"
            )->execute([':u' => $userId]);
            return;
        }
        $this->pdo->prepare(
            "UPDATE notifications SET read_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :i AND user_id = :u AND read_at IS NULL"
        )->execute([':i' => $id, ':u' => $userId]);
    }

    /**
     * Delete one routine notification. Security, invitation, and
     * ownership rows are a record of what happened and stay put.
     *
     * @return string OK | NOT_FOUND | FORBIDDEN
     */
    public function delete(int $userId, int $id): string
    {
        $s = $this->pdo->prepare('SELECT routine FROM notifications WHERE id = :i AND user_id = :u');
        $s->execute([':i' => $id, ':u' => $userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return self::NOT_FOUND;
        if ((int) $row['routine'] !== 1) return self::FORBIDDEN;
        $this->pdo->prepare('DELETE FROM notifications WHERE id = :i AND user_id = :u')
                  ->execute([':i' => $id, ':u' => $userId]);
        return self::OK;
    }

    /** Delete every routine notification the user has already read. */
    public function deleteRead(int $userId): int
    {
        $s = $this->pdo->prepare(
            'DELETE FROM notifications
              WHERE user_id = :u AND routine = 1 AND read_at IS NOT NULL'
        );
        $s->execute([':u' => $userId]);
        return $s->rowCount();
    }

    /* ────────────────────── Email preferences ─────────────────────── */

    /**
     * The full preference table for one user: every kind, its current
     * email setting, and whether that setting can be changed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function preferences(int $userId): array
    {
        $stored = [];
        $s = $this->pdo->prepare('SELECT kind, email_enabled FROM notification_preferences WHERE user_id = :u');
        $s->execute([':u' => $userId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stored[(string) $r['kind']] = (int) $r['email_enabled'] === 1;
        }
        $out = [];
        foreach (self::KINDS as $kind => $meta) {
            $out[] = [
                'kind'      => $kind,
                'label'     => $meta['label'],
                'email'     => $meta['locked'] ? true : ($stored[$kind] ?? $meta['email']),
                'locked'    => $meta['locked'],
                'aggregated'=> $meta['digest'],
            ];
        }
        return $out;
    }

    /**
     * Persist email preferences. Locked kinds are ignored — security
     * and recovery email cannot be switched off.
     *
     * @param array<string,mixed> $input  kind => bool
     */
    public function updatePreferences(int $userId, array $input): array
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO notification_preferences (user_id, kind, email_enabled, updated_at)
             VALUES (:u, :k, :e, strftime('%Y-%m-%dT%H:%M:%fZ','now'))
             ON CONFLICT(user_id, kind) DO UPDATE
                SET email_enabled = excluded.email_enabled,
                    updated_at    = excluded.updated_at"
        );
        foreach ($input as $kind => $value) {
            $kind = (string) $kind;
            if (!isset(self::KINDS[$kind]))    continue;
            if (self::KINDS[$kind]['locked'])  continue;
            $ins->execute([
                ':u' => $userId, ':k' => $kind,
                ':e' => $this->truthy($value) ? 1 : 0,
            ]);
        }
        return $this->preferences($userId);
    }

    public function emailEnabled(int $userId, string $kind): bool
    {
        $kind = self::canonicalKind($kind);
        $meta = self::KINDS[$kind] ?? null;
        if ($meta === null) return false;
        if ($meta['locked']) return true;
        $s = $this->pdo->prepare(
            'SELECT email_enabled FROM notification_preferences WHERE user_id = :u AND kind = :k'
        );
        $s->execute([':u' => $userId, ':k' => $kind]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return $meta['email'];
        return (int) $row['email_enabled'] === 1;
    }

    /* ──────────────────────────── Follows ─────────────────────────── */

    /** @return array{0:string,1:array<string,mixed>} */
    public function follow(string $slug, int $userId): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, []];
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO adventure_follows (user_id, adventure_id) VALUES (:u, :a)'
        )->execute([':u' => $userId, ':a' => (int) $adv['id']]);
        return [self::OK, ['following' => true, 'slug' => $slug]];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    public function unfollow(string $slug, int $userId): array
    {
        $adv = $this->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, []];
        $this->pdo->prepare(
            'DELETE FROM adventure_follows WHERE user_id = :u AND adventure_id = :a'
        )->execute([':u' => $userId, ':a' => (int) $adv['id']]);
        return [self::OK, ['following' => false, 'slug' => $slug]];
    }

    public function isFollowing(int $adventureId, ?int $userId): bool
    {
        if ($userId === null) return false;
        $s = $this->pdo->prepare(
            'SELECT 1 FROM adventure_follows WHERE user_id = :u AND adventure_id = :a LIMIT 1'
        );
        $s->execute([':u' => $userId, ':a' => $adventureId]);
        return $s->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * The adventures this user follows. No counts, on purpose.
     *
     * @return array<int,array<string,mixed>>
     */
    public function following(int $userId): array
    {
        $s = $this->pdo->prepare(
            'SELECT a.slug, a.title, f.created_at
               FROM adventure_follows f
               JOIN adventures a ON a.id = f.adventure_id
              WHERE f.user_id = :u
              ORDER BY f.created_at DESC'
        );
        $s->execute([':u' => $userId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tell followers an adventure changed. The actor is skipped, and
     * the email side of this kind is always aggregated.
     */
    public function announceUpdate(
        int $adventureId,
        string $title,
        string $body,
        ?string $url,
        ?int $actorId = null
    ): int {
        $s = $this->pdo->prepare('SELECT user_id FROM adventure_follows WHERE adventure_id = :a');
        $s->execute([':a' => $adventureId]);
        $ids = array_map(static fn ($r) => (int) $r['user_id'], $s->fetchAll(PDO::FETCH_ASSOC));
        return $this->emitMany(
            $ids, 'followed_adventure_updated', $title, $body, $url, $adventureId,
            ['actor_id' => $actorId]
        );
    }

    /* ─────────────────────────── Digests ──────────────────────────── */

    /**
     * Turn every pending digest row into one email per user.
     *
     * @return array{users:int, entries:int}
     */
    public function flushDigests(int $limitUsers = 200): array
    {
        $s = $this->pdo->prepare(
            'SELECT d.id, d.user_id, d.summary, u.email, u.display_name
               FROM notification_digests d
               JOIN users u ON u.id = d.user_id
              WHERE d.sent_at IS NULL
              ORDER BY d.user_id, d.created_at'
        );
        $s->execute();
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return ['users' => 0, 'entries' => 0];

        /** @var array<int, array{email:string,name:string,lines:list<string>,ids:list<int>}> $byUser */
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $byUser[$uid] ??= [
                'email' => (string) $r['email'],
                'name'  => (string) $r['display_name'],
                'lines' => [], 'ids' => [],
            ];
            $byUser[$uid]['lines'][] = '• ' . (string) $r['summary'];
            $byUser[$uid]['ids'][]   = (int) $r['id'];
        }

        $mark = $this->pdo->prepare(
            "UPDATE notification_digests
                SET sent_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :i"
        );

        $users = 0; $entries = 0;
        foreach ($byUser as $uid => $bundle) {
            if ($users >= $limitUsers) break;
            $this->queue->enqueue('followed_updates_digest', $bundle['email'], $bundle['name'], [
                'display_name' => $bundle['name'],
                'summary'      => implode("\n", $bundle['lines']),
                'site_url'     => $this->canonicalUrl(),
            ]);
            foreach ($bundle['ids'] as $id) { $mark->execute([':i' => $id]); $entries++; }
            $users++;
        }
        return ['users' => $users, 'entries' => $entries];
    }

    public function pendingDigestCount(int $userId): int
    {
        $s = $this->pdo->prepare(
            'SELECT COUNT(*) AS c FROM notification_digests WHERE user_id = :u AND sent_at IS NULL'
        );
        $s->execute([':u' => $userId]);
        return (int) ($s->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    }

    /* ─────────────────────────── Helpers ──────────────────────────── */

    /** Everyone who can act on a submission: owner, editors, admins' peers. */
    public function teamIds(int $adventureId, array $roles = ['owner','editor']): array
    {
        $in = implode(',', array_fill(0, count($roles), '?'));
        $s = $this->pdo->prepare(
            "SELECT user_id FROM adventure_collaborators
              WHERE adventure_id = ? AND role IN ($in)"
        );
        $s->execute(array_merge([$adventureId], $roles));
        $ids = array_map(static fn ($r) => (int) $r['user_id'], $s->fetchAll(PDO::FETCH_ASSOC));

        if (in_array('owner', $roles, true)) {
            $o = $this->pdo->prepare('SELECT author_id FROM adventures WHERE id = :a');
            $o->execute([':a' => $adventureId]);
            $row = $o->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && $row['author_id'] !== null) $ids[] = (int) $row['author_id'];
        }
        return array_values(array_unique($ids));
    }

    public function canonicalUrl(): string
    {
        return rtrim((string) $this->settings->get('canonical_url', bp_config()['app']['url']), '/');
    }

    /** @return array<string,mixed>|null */
    public function adventureBySlug(string $slug): ?array
    {
        $s = $this->pdo->prepare('SELECT id, slug, title, author_id FROM adventures WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function enqueueAlert(int $userId, string $title, string $body, ?string $url): void
    {
        $s = $this->pdo->prepare('SELECT email, display_name FROM users WHERE id = :u LIMIT 1');
        $s->execute([':u' => $userId]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        if ($u === false) return;
        $this->queue->enqueue('notification_alert', (string) $u['email'], (string) $u['display_name'], [
            'display_name' => (string) $u['display_name'],
            'subject'      => $title,
            'body'         => $body,
            'url'          => $url === null ? $this->canonicalUrl() : $this->canonicalUrl() . $url,
        ]);
    }

    private function userExists(int $userId): bool
    {
        $s = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :u LIMIT 1');
        $s->execute([':u' => $userId]);
        return $s->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /** @param mixed $v */
    private function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v))  return $v === 1;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1','true','yes','on'], true);
    }
}
