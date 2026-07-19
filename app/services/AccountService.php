<?php
/**
 * AccountService — read/write for the /account dashboard (v0.14.0).
 *
 * Every method operates as the given user_id. Callers MUST pass the
 * id returned by AuthService::authenticate() on the request's session
 * cookie — this class never trusts client-supplied identifiers.
 *
 * Email changes are two-step:
 *   requestEmailChange() records the target address and queues a
 *   verification link keyed by a hashed single-use token. Nothing on
 *   `users` moves until confirmEmailChange() consumes the token.
 *
 * Password/email/deletion-adjacent actions revoke sessions where the
 * threat model requires it. Notification prefs and profile edits do
 * not.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class AccountService
{
    public const OK             = 'ok';
    public const INVALID        = 'invalid';
    public const TOKEN_INVALID  = 'token_invalid';
    public const EMAIL_IN_USE   = 'email_in_use';

    private PDO $pdo;
    private SessionRepository $sessions;
    private TokenRepository $emailChangeTokens;
    private EmailQueueRepository $queue;
    private SettingsRepository $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo               = $pdo;
        $this->sessions          = new SessionRepository($pdo);
        $this->emailChangeTokens = new TokenRepository($pdo, 'pending_email_changes');
        $this->queue             = new EmailQueueRepository($pdo);
        $this->settings          = new SettingsRepository($pdo);
    }

    /* ─────────────────────── Profile / prefs ───────────────────── */

    /** @return array<string,mixed>|null */
    public function profile(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, username, display_name, bio,
                    public_profile, notify_replies, notify_moderation,
                    notify_updates, email_verified_at,
                    password_changed_at, last_login_at, created_at
               FROM users WHERE id = :u LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        return [
            'id'                  => (int) $row['id'],
            'email'               => (string) $row['email'],
            'username'            => (string) $row['username'],
            'display_name'        => (string) $row['display_name'],
            'bio'                 => $row['bio'] !== null ? (string) $row['bio'] : '',
            'public_profile'      => (int) $row['public_profile'] === 1,
            'notify_replies'      => (int) $row['notify_replies'] === 1,
            'notify_moderation'   => (int) $row['notify_moderation'] === 1,
            'notify_updates'      => (int) $row['notify_updates'] === 1,
            'email_verified_at'   => $row['email_verified_at'],
            'password_changed_at' => $row['password_changed_at'],
            'last_login_at'       => $row['last_login_at'],
            'created_at'          => $row['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:string,1:array<string,string>}
     */
    public function updateProfile(int $userId, array $input): array
    {
        $display = trim((string) ($input['display_name'] ?? ''));
        $bio     = (string) ($input['bio'] ?? '');
        $errors  = [];
        if ($display === '' || mb_strlen($display) > 60) {
            $errors['display_name'] = 'invalid';
        }
        if (mb_strlen($bio) > 500) $errors['bio'] = 'too_long';
        if ($errors !== []) return [self::INVALID, $errors];

        $public = !empty($input['public_profile']) ? 1 : 0;
        $this->pdo->prepare(
            "UPDATE users
                SET display_name  = :d, bio = :b, public_profile = :p,
                    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :u"
        )->execute([':d' => $display, ':b' => $bio, ':p' => $public, ':u' => $userId]);
        return [self::OK, []];
    }

    /** @param array<string,mixed> $input */
    public function updateNotifications(int $userId, array $input): void
    {
        $this->pdo->prepare(
            "UPDATE users
                SET notify_replies    = :r,
                    notify_moderation = :m,
                    notify_updates    = :n,
                    updated_at        = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :u"
        )->execute([
            ':r' => !empty($input['notify_replies'])    ? 1 : 0,
            ':m' => !empty($input['notify_moderation']) ? 1 : 0,
            ':n' => !empty($input['notify_updates'])    ? 1 : 0,
            ':u' => $userId,
        ]);
    }

    /* ─────────────────────── Sessions ──────────────────────────── */

    /** @return list<array<string,mixed>> */
    public function listSessions(int $userId, int $currentSessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, created_at, last_active_at, expires_at, user_agent
               FROM sessions
              WHERE user_id = :u
                AND revoked_at IS NULL
                AND expires_at > strftime('%Y-%m-%dT%H:%M:%fZ','now')
              ORDER BY last_active_at DESC"
        );
        $stmt->execute([':u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'             => (int) $row['id'],
                'created_at'     => (string) $row['created_at'],
                'last_active_at' => (string) $row['last_active_at'],
                'expires_at'     => (string) $row['expires_at'],
                'user_agent'     => $row['user_agent'] !== null ? (string) $row['user_agent'] : '',
                'is_current'     => ((int) $row['id']) === $currentSessionId,
            ];
        }
        return $out;
    }

    /** Revoke every session for the user except $keepSessionId. */
    public function revokeOtherSessions(int $userId, int $keepSessionId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sessions
                SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE user_id = :u AND id <> :k AND revoked_at IS NULL"
        );
        $stmt->execute([':u' => $userId, ':k' => $keepSessionId]);
        return $stmt->rowCount();
    }

    /* ─────────────────────── Email change ──────────────────────── */

    /**
     * Request a change of email. Returns TOKEN_INVALID if the new
     * address is malformed and EMAIL_IN_USE if another account owns
     * it. On success, queues a verification mail to the new address.
     *
     * @return array{0:string, 1:array<string,string>}
     */
    public function requestEmailChange(int $userId, string $newEmail): array
    {
        $errors = [];
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid';
            return [self::INVALID, $errors];
        }
        $norm = UserRepository::normaliseEmail($newEmail);
        // Reject when another account already owns the address.
        $stmt = $this->pdo->prepare(
            'SELECT id FROM users WHERE email_normalized = :e LIMIT 1'
        );
        $stmt->execute([':e' => $norm]);
        $row = $stmt->fetch();
        if ($row !== false && (int) $row['id'] !== $userId) {
            return [self::EMAIL_IN_USE, ['email' => 'in_use']];
        }
        // Invalidate any prior pending change for this user.
        $this->pdo->prepare(
            "UPDATE pending_email_changes
                SET used_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE user_id = :u AND used_at IS NULL"
        )->execute([':u' => $userId]);

        $raw = TokenRepository::generate();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 60 * 60 * 24);
        $ins = $this->pdo->prepare(
            'INSERT INTO pending_email_changes
                (user_id, new_email, new_email_norm, token_hash, expires_at)
             VALUES (:u, :e, :en, :h, :x)'
        );
        $ins->execute([
            ':u'  => $userId, ':e' => $newEmail, ':en' => $norm,
            ':h'  => TokenRepository::hash($raw), ':x' => $expires,
        ]);

        // Look up display name for the template.
        $u = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :u');
        $u->execute([':u' => $userId]);
        $display = (string) ($u->fetch()['display_name'] ?? '');

        $verifyUrl = rtrim((string) $this->settings->get('canonical_url', bp_config()['app']['url']), '/')
                   . '/account/security?email_change_token=' . rawurlencode($raw);
        $this->queue->enqueue('email_change_verify', $newEmail, $display, [
            'display_name' => $display,
            'verify_url'   => $verifyUrl,
        ]);
        return [self::OK, []];
    }

    /**
     * Consume the token issued by requestEmailChange() and commit the
     * new address. All sessions except the caller's are revoked so a
     * stolen cookie loses value.
     *
     * @return array{0:string, 1:?string}  [outcome, newEmail]
     */
    public function confirmEmailChange(int $userId, string $rawToken): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, new_email, new_email_norm, expires_at, used_at
               FROM pending_email_changes
              WHERE token_hash = :h LIMIT 1"
        );
        $stmt->execute([':h' => TokenRepository::hash($rawToken)]);
        $row = $stmt->fetch();
        if ($row === false) return [self::TOKEN_INVALID, null];
        if ($row['used_at'] !== null) return [self::TOKEN_INVALID, null];
        if ($row['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) return [self::TOKEN_INVALID, null];
        if ((int) $row['user_id'] !== $userId) return [self::TOKEN_INVALID, null];

        // A race elsewhere could have taken the address in the interim.
        $check = $this->pdo->prepare(
            'SELECT id FROM users WHERE email_normalized = :e AND id <> :u LIMIT 1'
        );
        $check->execute([':e' => $row['new_email_norm'], ':u' => $userId]);
        if ($check->fetch() !== false) return [self::EMAIL_IN_USE, null];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE users
                    SET email = :e, email_normalized = :en,
                        email_verified_at = strftime('%Y-%m-%dT%H:%M:%fZ','now'),
                        updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :u"
            )->execute([':e' => $row['new_email'], ':en' => $row['new_email_norm'], ':u' => $userId]);
            $this->pdo->prepare(
                "UPDATE pending_email_changes
                    SET used_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
                  WHERE id = :i"
            )->execute([':i' => $row['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return [self::OK, (string) $row['new_email']];
    }

    /* ─────────────────────── Bookmarks / history ────────────────── */

    /**
     * Import client-side bookmarks and history for many adventures.
     * Each entry is `{ slug, bookmarks: string[], history: string[] }`
     * where the string values are scene slugs. Unknown slugs are
     * silently dropped. Returns counts for the caller to display.
     *
     * @param list<array{slug:string, bookmarks?:list<string>, history?:list<string>}> $entries
     * @return array{imported_bookmarks:int, imported_history:int, skipped:int}
     */
    public function importLocalProgress(int $userId, array $entries): array
    {
        $importedB = 0; $importedH = 0; $skipped = 0;
        $ins = $this->pdo->prepare(
            'INSERT OR IGNORE INTO user_bookmarks
                (user_id, adventure_id, scene_id, kind)
             VALUES (:u, :a, :s, :k)'
        );
        foreach ($entries as $entry) {
            $slug = (string) ($entry['slug'] ?? '');
            if ($slug === '') { $skipped++; continue; }
            $advStmt = $this->pdo->prepare(
                'SELECT id FROM adventures WHERE slug = :s LIMIT 1'
            );
            $advStmt->execute([':s' => $slug]);
            $adv = $advStmt->fetch();
            if ($adv === false) { $skipped++; continue; }
            $adventureId = (int) $adv['id'];
            $sceneStmt = $this->pdo->prepare(
                'SELECT id, slug FROM scenes WHERE adventure_id = :a'
            );
            $sceneStmt->execute([':a' => $adventureId]);
            $sceneMap = [];
            foreach ($sceneStmt->fetchAll() as $s) {
                $sceneMap[(string) $s['slug']] = (int) $s['id'];
            }
            foreach ((array) ($entry['bookmarks'] ?? []) as $bslug) {
                $sceneId = $sceneMap[(string) $bslug] ?? null;
                if ($sceneId === null) { $skipped++; continue; }
                $ins->execute([':u' => $userId, ':a' => $adventureId, ':s' => $sceneId, ':k' => 'bookmark']);
                if ($ins->rowCount() > 0) $importedB++;
            }
            foreach ((array) ($entry['history'] ?? []) as $hslug) {
                $sceneId = $sceneMap[(string) $hslug] ?? null;
                if ($sceneId === null) { $skipped++; continue; }
                $ins->execute([':u' => $userId, ':a' => $adventureId, ':s' => $sceneId, ':k' => 'history']);
                if ($ins->rowCount() > 0) $importedH++;
            }
        }
        return [
            'imported_bookmarks' => $importedB,
            'imported_history'   => $importedH,
            'skipped'            => $skipped,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listBookmarks(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.kind, b.created_at,
                    a.slug AS adventure_slug, a.title AS adventure_title,
                    s.slug AS scene_slug, s.title AS scene_title
               FROM user_bookmarks b
               JOIN adventures a ON a.id = b.adventure_id
          LEFT JOIN scenes     s ON s.id = b.scene_id
              WHERE b.user_id = :u AND b.kind = 'bookmark'
              ORDER BY b.created_at DESC"
        );
        $stmt->execute([':u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'              => (int) $r['id'],
                'kind'            => (string) $r['kind'],
                'adventure_slug'  => (string) $r['adventure_slug'],
                'adventure_title' => (string) $r['adventure_title'],
                'scene_slug'      => $r['scene_slug'] !== null ? (string) $r['scene_slug'] : null,
                'scene_title'     => $r['scene_title'] !== null ? (string) $r['scene_title'] : null,
                'created_at'      => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /* ─────────────────────── Adventures / contributions ────────── */

    /** Adventures authored by the caller (any state). */
    public function myAdventures(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, title, state, visibility, contribution_state, updated_at
               FROM adventures WHERE author_id = :u ORDER BY updated_at DESC'
        );
        $stmt->execute([':u' => $userId]);
        return array_map(static fn ($r) => [
            'id'                 => (int) $r['id'],
            'slug'               => (string) $r['slug'],
            'title'              => (string) $r['title'],
            'state'              => (string) $r['state'],
            'visibility'         => (string) $r['visibility'],
            'contribution_state' => (string) $r['contribution_state'],
            'updated_at'         => (string) $r['updated_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Contributions authored by the caller. v0.14.0 has no authoring
     * flow yet, so this always returns an empty list — the endpoint
     * exists so the account UI has a stable shape.
     */
    public function myContributions(int $userId): array
    {
        return [];
    }

    /* ─────────────────────── Helpers ───────────────────────────── */

    /** Session id from the cookie value used by AuthService. */
    public static function sessionIdFromCookie(?string $cookie): int
    {
        if ($cookie === null || $cookie === '') return 0;
        [$idStr] = array_pad(explode('.', $cookie, 2), 2, '');
        return ctype_digit((string) $idStr) ? (int) $idStr : 0;
    }
}
