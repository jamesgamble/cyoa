<?php
/**
 * MasterService — platform administration (v0.25.0).
 *
 * Platform roles: user, moderator, admin. Roles live only in the
 * `user_roles` table and are always read server-side.
 *
 * Every public action returns [outcome, data]. The authorization
 * matrix lives in PERMISSIONS so the API, UI, and tests share a single
 * source of truth. Actions listed in REAUTH additionally require a
 * recent password re-entry on the caller's session.
 *
 * Nothing returned from this class ever contains password hashes,
 * session tokens, reset or verification tokens, SMTP passwords,
 * encryption keys, or database paths.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class MasterService
{
    public const OK        = 'ok';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const REAUTH    = 'reauthentication_required';
    public const INVALID   = 'invalid';
    public const CONFLICT  = 'conflict';

    public const ROLE_USER      = 'user';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_ADMIN     = 'admin';

    /** permission => roles allowed */
    public const PERMISSIONS = [
        'view_console'        => ['moderator', 'admin'],
        'view_users'          => ['moderator', 'admin'],
        'view_adventures'     => ['moderator', 'admin'],
        'view_submissions'    => ['moderator', 'admin'],
        'review_reports'      => ['moderator', 'admin'],
        'hide_content'        => ['moderator', 'admin'],
        'suspend_adventure'   => ['moderator', 'admin'],
        'escalate_account'    => ['moderator', 'admin'],
        'view_activity'       => ['moderator', 'admin'],
        'manage_roles'        => ['admin'],
        'suspend_user'        => ['admin'],
        'configure_registration' => ['admin'],
        'configure_anonymous' => ['admin'],
        'configure_limits'    => ['admin'],
        'configure_smtp'      => ['admin'],
        'manage_email_queue'  => ['admin'],
        'configure_maintenance' => ['admin'],
        'trigger_reset'       => ['admin'],
        'transfer_ownership'  => ['admin'],
        'view_security_activity' => ['admin'],
    ];

    /** Actions that need a recent reauthentication. */
    public const REAUTH = [
        'manage_roles', 'transfer_ownership', 'configure_smtp_credentials',
        'configure_maintenance', 'hide_content', 'suspend_adventure', 'suspend_user',
    ];

    public const SETTING_GROUPS = [
        'registration' => [
            'registration_enabled' => 'bool', 'require_email_verification' => 'bool',
            'require_admin_approval' => 'bool', 'minimum_password_length' => 'int:8:128',
            'registrations_per_ip_per_hour' => 'int:1:1000',
        ],
        'anonymous' => [
            'anonymous_reading_allowed' => 'bool', 'anonymous_reports_allowed' => 'bool',
            'anonymous_contributions_allowed' => 'bool',
        ],
        'limits' => [
            'max_adventures_per_user' => 'int:1:1000', 'adventures_per_user_per_hour' => 'int:1:1000',
            'contributions_per_user_per_hour' => 'int:1:10000', 'contributions_per_ip_per_hour' => 'int:1:10000',
        ],
        'maintenance' => [
            'maintenance_mode' => 'bool', 'maintenance_message' => 'text:280',
        ],
    ];
    private const GROUP_PERMISSION = [
        'registration' => 'configure_registration', 'anonymous' => 'configure_anonymous',
        'limits' => 'configure_limits', 'maintenance' => 'configure_maintenance',
    ];

    private PDO $pdo;
    private ?\Closure $reauthCheck;

    /**
     * @param \Closure|null $reauthCheck fn(?int $sessionId): bool — injectable for tests.
     */
    public function __construct(PDO $pdo, ?\Closure $reauthCheck = null)
    {
        $this->pdo = $pdo;
        $this->reauthCheck = $reauthCheck;
    }

    /* ───────────────────────── Roles ───────────────────────── */

    public function platformRole(?int $userId): string
    {
        if ($userId === null || $userId <= 0) return self::ROLE_USER;
        $s = $this->pdo->prepare(
            "SELECT r.role FROM user_roles r JOIN users u ON u.id = r.user_id
              WHERE r.user_id = :u AND u.status = 'active'"
        );
        $s->execute([':u' => $userId]);
        $roles = array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
        if (in_array('admin', $roles, true)) return self::ROLE_ADMIN;
        if (in_array('moderator', $roles, true)) return self::ROLE_MODERATOR;
        return self::ROLE_USER;
    }

    public static function can(string $role, string $permission): bool
    {
        return in_array($role, self::PERMISSIONS[$permission] ?? [], true);
    }

    /** @return array<string,bool> */
    public static function permissionsFor(string $role): array
    {
        $out = [];
        foreach (array_keys(self::PERMISSIONS) as $p) $out[$p] = self::can($role, $p);
        return $out;
    }

    private function reauthed(?int $sessionId): bool
    {
        if ($this->reauthCheck !== null) return (bool) ($this->reauthCheck)($sessionId);
        return (new CollaborationService($this->pdo))->reauthenticatedRecently($sessionId);
    }

    /** Common gate: returns null when allowed, else an outcome. */
    private function gate(?int $actor, string $permission, ?int $sessionId = null, bool $needsReauth = false): ?string
    {
        if (!self::can($this->platformRole($actor), $permission)) return self::FORBIDDEN;
        if ($needsReauth && !$this->reauthed($sessionId)) return self::REAUTH;
        return null;
    }

    public function me(?int $actor): array
    {
        $role = $this->platformRole($actor);
        if (!self::can($role, 'view_console')) return [self::FORBIDDEN, null];
        $name = '';
        $s = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :u');
        $s->execute([':u' => $actor]);
        $name = (string) ($s->fetchColumn() ?: '');
        return [self::OK, ['user_id' => $actor, 'display_name' => $name, 'role' => $role,
            'permissions' => self::permissionsFor($role), 'reauth_required_for' => self::REAUTH]];
    }

    /* ───────────────────────── Overview ───────────────────────── */

    public function overview(?int $actor): array
    {
        if ($g = $this->gate($actor, 'view_console')) return [$g, null];
        $c = fn (string $sql): int => (int) $this->pdo->query($sql)->fetchColumn();
        return [self::OK, [
            'users'        => $c("SELECT COUNT(*) FROM users WHERE status != 'deleted'"),
            'suspended_users' => $c("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
            'adventures'   => $c('SELECT COUNT(*) FROM adventures'),
            'suspended_adventures' => $c("SELECT COUNT(*) FROM adventures WHERE state = 'suspended'"),
            'pending_submissions' => $c("SELECT COUNT(*) FROM branch_submissions WHERE state = 'pending'"),
            'open_reports' => $c("SELECT COUNT(*) FROM content_reports WHERE state IN ('open','escalated')"),
            'open_escalations' => $c("SELECT COUNT(*) FROM platform_activity WHERE state = 'open'"),
            'maintenance_mode' => (new SettingsRepository($this->pdo))->getBool('maintenance_mode', false),
        ]];
    }

    /* ───────────────────────── Users ───────────────────────── */

    public function users(?int $actor, string $q = ''): array
    {
        if ($g = $this->gate($actor, 'view_users')) return [$g, null];
        $isAdmin = $this->platformRole($actor) === self::ROLE_ADMIN;
        $sql = "SELECT u.id, u.username, u.display_name, u.email, u.status, u.created_at, u.last_login_at,
                       (SELECT GROUP_CONCAT(role) FROM user_roles r WHERE r.user_id = u.id) AS roles
                  FROM users u WHERE u.status != 'deleted'";
        $p = [];
        if ($q !== '') {
            $sql .= ' AND (u.username LIKE :q OR u.display_name LIKE :q OR u.email LIKE :q)';
            $p[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY u.id DESC LIMIT 200';
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        $rows = [];
        foreach ($s->fetchAll() as $r) {
            $roles = array_filter(explode(',', (string) ($r['roles'] ?? '')));
            $rows[] = [
                'id' => (int) $r['id'], 'username' => (string) $r['username'],
                'display_name' => (string) $r['display_name'],
                // Moderators see accounts but not email addresses.
                'email' => $isAdmin ? (string) ($r['email'] ?? '') : null,
                'status' => (string) $r['status'],
                'role' => in_array('admin', $roles, true) ? 'admin' : (in_array('moderator', $roles, true) ? 'moderator' : 'user'),
                'created_at' => (string) $r['created_at'],
                'last_login_at' => $r['last_login_at'],
            ];
        }
        return [self::OK, ['users' => $rows]];
    }

    public function setRole(?int $actor, ?int $sessionId, int $targetId, string $role): array
    {
        if ($g = $this->gate($actor, 'manage_roles', $sessionId, true)) return [$g, null];
        if (!in_array($role, [self::ROLE_USER, self::ROLE_MODERATOR, self::ROLE_ADMIN], true)) return [self::INVALID, ['field' => 'role']];
        if (!$this->userExists($targetId)) return [self::NOT_FOUND, null];
        if ($targetId === $actor && $role !== self::ROLE_ADMIN) return [self::CONFLICT, ['reason' => 'cannot_demote_self']];
        $current = $this->platformRole($targetId);
        if ($current === self::ROLE_ADMIN && $role !== self::ROLE_ADMIN && $this->adminCount() <= 1) {
            return [self::CONFLICT, ['reason' => 'last_admin']];
        }
        $this->tx(function () use ($targetId, $role, $actor, $current): void {
            $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :u AND role IN ('admin','moderator')")->execute([':u' => $targetId]);
            if ($role !== self::ROLE_USER) {
                $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:u, :r)')->execute([':u' => $targetId, ':r' => $role]);
            }
            $this->log($actor, 'role_changed', 'user', $targetId, "$current → $role", true);
        });
        return [self::OK, ['user_id' => $targetId, 'role' => $role]];
    }

    public function setUserStatus(?int $actor, ?int $sessionId, int $targetId, bool $suspend, string $note = ''): array
    {
        if ($g = $this->gate($actor, 'suspend_user', $sessionId, $suspend)) return [$g, null];
        $s = $this->pdo->prepare('SELECT status FROM users WHERE id = :u');
        $s->execute([':u' => $targetId]);
        $status = $s->fetchColumn();
        if ($status === false) return [self::NOT_FOUND, null];
        if ($targetId === $actor) return [self::CONFLICT, ['reason' => 'cannot_suspend_self']];
        if ($suspend && $this->platformRole($targetId) === self::ROLE_ADMIN && $this->adminCount() <= 1) {
            return [self::CONFLICT, ['reason' => 'last_admin']];
        }
        $to = $suspend ? 'suspended' : 'active';
        if ($status === $to) return [self::OK, ['user_id' => $targetId, 'status' => $to]];
        if (!$suspend && $status !== 'suspended') return [self::CONFLICT, ['reason' => 'not_suspended']];
        $this->tx(function () use ($targetId, $to, $suspend, $actor, $note): void {
            $this->pdo->prepare("UPDATE users SET status = :s, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = :u")
                ->execute([':s' => $to, ':u' => $targetId]);
            if ($suspend) {
                $this->pdo->prepare("UPDATE sessions SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE user_id = :u AND revoked_at IS NULL")
                    ->execute([':u' => $targetId]);
            }
            $this->log($actor, $suspend ? 'user_suspended' : 'user_restored', 'user', $targetId, $this->clip($note), true);
        });
        return [self::OK, ['user_id' => $targetId, 'status' => $to]];
    }

    public function escalateAccount(?int $actor, int $targetId, string $note): array
    {
        if ($g = $this->gate($actor, 'escalate_account')) return [$g, null];
        if (!$this->userExists($targetId)) return [self::NOT_FOUND, null];
        $note = $this->clip($note);
        if ($note === '') return [self::INVALID, ['field' => 'note']];
        $id = $this->log($actor, 'account_escalated', 'user', $targetId, $note, true, 'open');
        return [self::OK, ['escalation_id' => $id]];
    }

    public function closeEscalation(?int $actor, int $id): array
    {
        if ($g = $this->gate($actor, 'suspend_user')) return [$g, null];
        $s = $this->pdo->prepare("UPDATE platform_activity SET state = 'closed' WHERE id = :i AND state = 'open'");
        $s->execute([':i' => $id]);
        if ($s->rowCount() === 0) return [self::NOT_FOUND, null];
        $this->log($actor, 'escalation_closed', 'activity', $id, null, true);
        return [self::OK, ['escalation_id' => $id]];
    }

    /** Queue a password-reset email. Never returns the token. */
    public function triggerReset(?int $actor, int $targetId): array
    {
        if ($g = $this->gate($actor, 'trigger_reset')) return [$g, null];
        $s = $this->pdo->prepare('SELECT email, status FROM users WHERE id = :u');
        $s->execute([':u' => $targetId]);
        $row = $s->fetch();
        if ($row === false) return [self::NOT_FOUND, null];
        if ($row['status'] !== 'active' || (string) $row['email'] === '') return [self::CONFLICT, ['reason' => 'not_active']];
        (new AuthService($this->pdo))->forgotPassword((string) $row['email']);
        $this->log($actor, 'reset_email_triggered', 'user', $targetId, null, true);
        return [self::OK, ['status' => 'queued']];
    }

    /* ───────────────────────── Adventures ───────────────────────── */

    public function adventures(?int $actor, string $q = ''): array
    {
        if ($g = $this->gate($actor, 'view_adventures')) return [$g, null];
        $sql = 'SELECT a.id, a.slug, a.title, a.state, a.visibility, a.created_at, a.author_id,
                       u.display_name AS owner_name,
                       (SELECT COUNT(*) FROM content_reports r WHERE r.adventure_id = a.id AND r.state IN (\'open\',\'escalated\')) AS open_reports
                  FROM adventures a JOIN users u ON u.id = a.author_id';
        $p = [];
        if ($q !== '') { $sql .= ' WHERE a.title LIKE :q OR a.slug LIKE :q'; $p[':q'] = '%' . $q . '%'; }
        $sql .= ' ORDER BY a.id DESC LIMIT 200';
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return [self::OK, ['adventures' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'title' => (string) $r['title'],
            'state' => (string) $r['state'], 'visibility' => (string) $r['visibility'],
            'owner_id' => (int) $r['author_id'], 'owner_name' => (string) $r['owner_name'],
            'open_reports' => (int) $r['open_reports'], 'created_at' => (string) $r['created_at'],
        ], $s->fetchAll())]];
    }

    public function setAdventureSuspended(?int $actor, ?int $sessionId, int $adventureId, bool $suspend, string $note = ''): array
    {
        if ($g = $this->gate($actor, 'suspend_adventure', $sessionId, $suspend)) return [$g, null];
        $s = $this->pdo->prepare('SELECT state, suspended_from_state FROM adventures WHERE id = :a');
        $s->execute([':a' => $adventureId]);
        $row = $s->fetch();
        if ($row === false) return [self::NOT_FOUND, null];
        $state = (string) $row['state'];
        if ($suspend && $state === 'suspended') return [self::OK, ['state' => 'suspended']];
        if (!$suspend && $state !== 'suspended') return [self::CONFLICT, ['reason' => 'not_suspended']];
        $to = $suspend ? 'suspended' : ((string) ($row['suspended_from_state'] ?? '') ?: 'draft');
        $this->tx(function () use ($adventureId, $suspend, $state, $to, $actor, $note): void {
            $this->pdo->prepare("UPDATE adventures SET state = :s, suspended_from_state = :f, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = :a")
                ->execute([':s' => $to, ':f' => $suspend ? $state : null, ':a' => $adventureId]);
            $this->pdo->prepare('INSERT INTO adventure_activity (adventure_id, user_id, action, from_state, to_state, note) VALUES (:a,:u,:ac,:f,:t,:n)')
                ->execute([':a' => $adventureId, ':u' => $actor, ':ac' => $suspend ? 'platform_suspended' : 'platform_restored',
                    ':f' => $state, ':t' => $to, ':n' => $this->clip($note) ?: null]);
            $this->log($actor, $suspend ? 'adventure_suspended' : 'adventure_restored', 'adventure', $adventureId, $this->clip($note), false);
        });
        return [self::OK, ['state' => $to]];
    }

    /** Admin-only ownership transfer. Transactional; exactly one owner remains. */
    public function transferOwnership(?int $actor, ?int $sessionId, int $adventureId, int $newOwnerId, bool $confirmed): array
    {
        if ($g = $this->gate($actor, 'transfer_ownership', $sessionId, true)) return [$g, null];
        if (!$confirmed) return [self::INVALID, ['reason' => 'confirmation_required']];
        $s = $this->pdo->prepare('SELECT author_id FROM adventures WHERE id = :a');
        $s->execute([':a' => $adventureId]);
        $old = $s->fetchColumn();
        if ($old === false) return [self::NOT_FOUND, null];
        $old = (int) $old;
        $u = $this->pdo->prepare("SELECT status FROM users WHERE id = :u");
        $u->execute([':u' => $newOwnerId]);
        $st = $u->fetchColumn();
        if ($st === false) return [self::NOT_FOUND, ['reason' => 'user']];
        if ($st !== 'active') return [self::CONFLICT, ['reason' => 'user_not_active']];
        if ($old === $newOwnerId) return [self::CONFLICT, ['reason' => 'already_owner']];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->tx(function () use ($adventureId, $old, $newOwnerId, $actor, $now): void {
            $this->pdo->prepare("UPDATE adventures SET author_id = :n, updated_at = :t WHERE id = :a")
                ->execute([':n' => $newOwnerId, ':t' => $now, ':a' => $adventureId]);
            $this->pdo->prepare('DELETE FROM adventure_collaborators WHERE adventure_id = :a AND user_id = :u')
                ->execute([':a' => $adventureId, ':u' => $newOwnerId]);
            $this->pdo->prepare("UPDATE adventure_collaborators SET role = 'editor' WHERE adventure_id = :a AND user_id = :u")
                ->execute([':a' => $adventureId, ':u' => $old]);
            $this->pdo->prepare("INSERT INTO adventure_collaborators (adventure_id, user_id, role, created_at) VALUES (:a,:u,'owner',:t)")
                ->execute([':a' => $adventureId, ':u' => $newOwnerId, ':t' => $now]);
            $this->pdo->prepare("INSERT INTO adventure_activity (adventure_id, user_id, action, note) VALUES (:a,:u,'ownership_transferred',:n)")
                ->execute([':a' => $adventureId, ':u' => $actor, ':n' => 'Transferred by platform administrator']);
            $this->log($actor, 'ownership_transferred', 'adventure', $adventureId, "user $old → user $newOwnerId", true);
        });
        return [self::OK, ['adventure_id' => $adventureId, 'owner_id' => $newOwnerId]];
    }

    /* ───────────────────────── Submissions & reports ───────────────────────── */

    public function submissions(?int $actor, string $state = 'pending'): array
    {
        if ($g = $this->gate($actor, 'view_submissions')) return [$g, null];
        $sql = 'SELECT s.id, s.state, s.created_at, s.choice_label, s.scene_title, a.slug, a.title AS adventure_title
                  FROM branch_submissions s JOIN adventures a ON a.id = s.adventure_id';
        $p = [];
        if ($state !== '' && $state !== 'all') { $sql .= ' WHERE s.state = :s'; $p[':s'] = $state; }
        $sql .= ' ORDER BY s.id DESC LIMIT 200';
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            $st = $this->pdo->prepare(str_replace(['s.choice_label, s.scene_title, '], [''], $sql));
            $st->execute($p);
            $rows = $st->fetchAll();
        }
        return [self::OK, ['submissions' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'state' => (string) $r['state'], 'created_at' => (string) $r['created_at'],
            'choice_label' => $r['choice_label'] ?? null, 'scene_title' => $r['scene_title'] ?? null,
            'adventure_slug' => (string) $r['slug'], 'adventure_title' => (string) $r['adventure_title'],
        ], $rows)]];
    }

    public function reports(?int $actor, string $state = 'open'): array
    {
        if ($g = $this->gate($actor, 'review_reports')) return [$g, null];
        $sql = "SELECT r.id, r.target_type, r.scene_id, r.choice_id, r.submission_id, r.reason, r.details,
                       r.state, r.action_taken, r.platform_private, r.created_at, a.id AS adventure_id,
                       a.slug, a.title, sc.title AS scene_title, sc.state AS scene_state
                  FROM content_reports r JOIN adventures a ON a.id = r.adventure_id
                  LEFT JOIN scenes sc ON sc.id = r.scene_id";
        $p = [];
        if ($state === 'open') { $sql .= " WHERE r.state IN ('open','escalated')"; }
        elseif ($state !== 'all') { $sql .= ' WHERE r.state = :s'; $p[':s'] = $state; }
        $sql .= ' ORDER BY r.id DESC LIMIT 200';
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        // Reporter identity (id, IP, fingerprint) is never projected.
        return [self::OK, ['reports' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'target_type' => (string) $r['target_type'],
            'scene_id' => $r['scene_id'] !== null ? (int) $r['scene_id'] : null,
            'scene_title' => $r['scene_title'], 'scene_state' => $r['scene_state'],
            'reason' => (string) $r['reason'], 'details' => $r['details'], 'state' => (string) $r['state'],
            'action_taken' => $r['action_taken'], 'platform_private' => (int) $r['platform_private'] === 1,
            'adventure_id' => (int) $r['adventure_id'], 'adventure_slug' => (string) $r['slug'],
            'adventure_title' => (string) $r['title'], 'created_at' => (string) $r['created_at'],
        ], $s->fetchAll())]];
    }

    /**
     * Act on a report: dismiss / resolve (no reauth) or hide / restore
     * the reported scene (hide is destructive → reauth).
     */
    public function actOnReport(?int $actor, ?int $sessionId, int $reportId, string $action, string $note = ''): array
    {
        if (!in_array($action, ['dismiss', 'resolve', 'hide', 'restore'], true)) return [self::INVALID, ['field' => 'action']];
        $perm = in_array($action, ['hide', 'restore'], true) ? 'hide_content' : 'review_reports';
        if ($g = $this->gate($actor, $perm, $sessionId, $action === 'hide')) return [$g, null];
        $s = $this->pdo->prepare('SELECT id, adventure_id, scene_id, state FROM content_reports WHERE id = :i');
        $s->execute([':i' => $reportId]);
        $r = $s->fetch();
        if ($r === false) return [self::NOT_FOUND, null];
        if (in_array($action, ['hide', 'restore'], true) && $r['scene_id'] === null) return [self::CONFLICT, ['reason' => 'no_scene_target']];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->tx(function () use ($r, $action, $actor, $note, $now): void {
            if ($action === 'hide' || $action === 'restore') {
                $this->pdo->prepare('UPDATE scenes SET state = :s WHERE id = :i')
                    ->execute([':s' => $action === 'hide' ? 'hidden' : 'published', ':i' => (int) $r['scene_id']]);
            }
            $state = $action === 'dismiss' ? 'dismissed' : ($action === 'restore' ? (string) $r['state'] : 'resolved');
            $this->pdo->prepare('UPDATE content_reports SET state = :s, action_taken = :a, resolved_by = :u, resolved_at = :t, resolution_note = :n WHERE id = :i')
                ->execute([':s' => $state, ':a' => 'platform_' . $action, ':u' => $actor, ':t' => $now,
                    ':n' => $this->clip($note) ?: null, ':i' => (int) $r['id']]);
            $this->log($actor, 'report_' . $action, 'report', (int) $r['id'], $this->clip($note), false);
        });
        return [self::OK, ['report_id' => $reportId, 'action' => $action]];
    }

    /* ───────────────────────── Settings ───────────────────────── */

    public function settings(?int $actor): array
    {
        if ($g = $this->gate($actor, 'configure_registration')) return [$g, null];
        $repo = new SettingsRepository($this->pdo);
        $out = [];
        foreach (self::SETTING_GROUPS as $group => $keys) {
            foreach ($keys as $k => $type) {
                $raw = $repo->get($k, '');
                $out[$group][$k] = $type === 'bool' ? $raw === '1' : (str_starts_with($type, 'int') ? (int) $raw : (string) $raw);
            }
        }
        return [self::OK, ['settings' => $out]];
    }

    public function saveSettings(?int $actor, ?int $sessionId, string $group, array $input): array
    {
        if (!isset(self::SETTING_GROUPS[$group])) return [self::NOT_FOUND, null];
        $perm = self::GROUP_PERMISSION[$group];
        if ($g = $this->gate($actor, $perm, $sessionId, $group === 'maintenance')) return [$g, null];
        $values = []; $errors = [];
        foreach (self::SETTING_GROUPS[$group] as $k => $type) {
            if (!array_key_exists($k, $input)) continue;
            $v = $input[$k];
            if ($type === 'bool') { $values[$k] = !empty($v) ? '1' : '0'; continue; }
            if (str_starts_with($type, 'int')) {
                [, $min, $max] = explode(':', $type);
                if (!is_numeric($v) || (int) $v < (int) $min || (int) $v > (int) $max) { $errors[$k] = 'invalid'; continue; }
                $values[$k] = (string) (int) $v; continue;
            }
            [, $max] = explode(':', $type);
            $v = trim(strip_tags((string) $v));
            if (mb_strlen($v) > (int) $max) { $errors[$k] = 'too_long'; continue; }
            $values[$k] = $v;
        }
        if ($errors) return [self::INVALID, ['fields' => $errors]];
        $repo = new SettingsRepository($this->pdo);
        $this->tx(function () use ($values, $repo, $actor, $group): void {
            foreach ($values as $k => $v) $repo->set($k, $v);
            $this->log($actor, 'settings_changed', 'settings', null, $group . ': ' . implode(', ', array_keys($values)), true);
        });
        return [self::OK, ['group' => $group]];
    }

    /** SMTP read (password redacted) — admin only. */
    public function smtp(?int $actor): array
    {
        if ($g = $this->gate($actor, 'configure_smtp')) return [$g, null];
        return [self::OK, ['settings' => (new SmtpSettingsRepository($this->pdo))->loadForApi()]];
    }

    /** SMTP save. Changing host/port/username/password needs reauth. */
    public function saveSmtp(?int $actor, ?int $sessionId, array $input): array
    {
        if ($g = $this->gate($actor, 'configure_smtp')) return [$g, null];
        $repo = new SmtpSettingsRepository($this->pdo);
        $cur = $repo->load();
        $pw = (string) ($input['password'] ?? SmtpSettingsRepository::PASSWORD_UNCHANGED_MARKER);
        $credChange = $pw !== SmtpSettingsRepository::PASSWORD_UNCHANGED_MARKER
            || trim((string) ($input['host'] ?? $cur['host'])) !== $cur['host']
            || (int) ($input['port'] ?? $cur['port']) !== $cur['port']
            || trim((string) ($input['username'] ?? $cur['username'])) !== $cur['username'];
        if ($credChange && !$this->reauthed($sessionId)) return [self::REAUTH, null];
        [$ok, $errors] = $repo->save($input + ['password' => SmtpSettingsRepository::PASSWORD_UNCHANGED_MARKER]);
        if (!$ok) return [self::INVALID, ['fields' => $errors]];
        $this->log($actor, $credChange ? 'smtp_credentials_changed' : 'smtp_settings_changed', 'settings', null, null, true);
        return [self::OK, ['status' => 'saved']];
    }

    public function emailQueue(?int $actor, ?string $status): array
    {
        if ($g = $this->gate($actor, 'manage_email_queue')) return [$g, null];
        $repo = new EmailQueueRepository($this->pdo);
        return [self::OK, ['counts' => $repo->counts(), 'messages' => $repo->recent(100, $status)]];
    }

    public function queueAction(?int $actor, int $id, string $action): array
    {
        if ($g = $this->gate($actor, 'manage_email_queue')) return [$g, null];
        if ($action === 'cancel') {
            $ok = (new EmailQueueRepository($this->pdo))->cancel($id);
        } elseif ($action === 'retry') {
            $s = $this->pdo->prepare("UPDATE email_queue SET status = 'pending', attempts = 0, last_error = NULL,
                    next_attempt_at = strftime('%Y-%m-%dT%H:%M:%SZ','now'), updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :i AND status IN ('failed','cancelled')");
            $s->execute([':i' => $id]);
            $ok = $s->rowCount() > 0;
        } else {
            return [self::INVALID, ['field' => 'action']];
        }
        if ($ok) $this->log($actor, 'email_' . $action, 'email', $id, null, false);
        return [self::OK, ['status' => $ok ? $action . 'ed' : 'noop']];
    }

    /* ───────────────────────── Activity ───────────────────────── */

    public function activity(?int $actor, bool $securityOnly = false): array
    {
        if ($g = $this->gate($actor, 'view_activity')) return [$g, null];
        $isAdmin = self::can($this->platformRole($actor), 'view_security_activity');
        if ($securityOnly && !$isAdmin) return [self::FORBIDDEN, null];
        $sql = 'SELECT p.id, p.action, p.target_type, p.target_id, p.note, p.security, p.state, p.created_at,
                       u.display_name AS actor_name
                  FROM platform_activity p LEFT JOIN users u ON u.id = p.actor_id';
        // Moderators see moderation activity plus escalations they can act on.
        if ($securityOnly) $sql .= ' WHERE p.security = 1';
        elseif (!$isAdmin) $sql .= " WHERE p.security = 0 OR p.action = 'account_escalated'";
        $sql .= ' ORDER BY p.id DESC LIMIT 200';
        return [self::OK, ['activity' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'action' => (string) $r['action'], 'target_type' => (string) $r['target_type'],
            'target_id' => $r['target_id'] !== null ? (int) $r['target_id'] : null, 'note' => $r['note'],
            'security' => (int) $r['security'] === 1, 'state' => (string) $r['state'],
            'actor_name' => $r['actor_name'], 'created_at' => (string) $r['created_at'],
        ], $this->pdo->query($sql)->fetchAll())]];
    }

    /* ───────────────────────── Helpers ───────────────────────── */

    public static function maintenanceActive(PDO $pdo): bool
    {
        try { return (new SettingsRepository($pdo))->getBool('maintenance_mode', false); }
        catch (\Throwable $e) { return false; }
    }

    private function log(?int $actor, string $action, string $type, ?int $targetId, ?string $note, bool $security, string $state = 'logged'): int
    {
        $this->pdo->prepare('INSERT INTO platform_activity (actor_id, action, target_type, target_id, note, security, state)
                             VALUES (:a,:ac,:t,:ti,:n,:s,:st)')
            ->execute([':a' => $actor, ':ac' => $action, ':t' => $type, ':ti' => $targetId,
                ':n' => ($note === null || $note === '') ? null : $note, ':s' => $security ? 1 : 0, ':st' => $state]);
        return (int) $this->pdo->lastInsertId();
    }

    private function clip(string $s): string { return mb_substr(trim(strip_tags($s)), 0, 500); }

    private function userExists(int $id): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM users WHERE id = :u AND status != 'deleted'");
        $s->execute([':u' => $id]);
        return (bool) $s->fetchColumn();
    }

    private function adminCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM user_roles r JOIN users u ON u.id = r.user_id WHERE r.role = 'admin' AND u.status = 'active'")->fetchColumn();
    }

    private function tx(callable $fn): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try { $fn(); if ($own) $this->pdo->commit(); }
        catch (\Throwable $e) { if ($own) $this->pdo->rollBack(); throw $e; }
    }
}
