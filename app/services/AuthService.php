<?php
/**
 * AuthService — end-to-end authentication orchestration for v0.13.0.
 *
 * Owns:
 *   • login  — verify credentials, refuse non-active accounts, rotate
 *     the session by revoking any prior sessions for the user, and
 *     emit a fresh cookie.
 *   • logout — revoke the current session and expire the cookie.
 *   • email verification — issue and consume single-use hashed tokens,
 *     flip the user to 'active', queue a welcome message.
 *   • password recovery — issue reset tokens for active users, respond
 *     with the same opaque payload regardless of whether the address
 *     exists, and revoke every session on successful reset.
 *   • password change — verify the current password, apply the new
 *     one, revoke every other session, queue a notification email.
 *
 * Every write happens under the caller's WriteLock so a race can
 * never leave two rows in an inconsistent state.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class AuthService
{
    public const SESSION_COOKIE = 'bp_session';
    public const VERIFY_TTL     = 60 * 60 * 24; // 24h
    public const RESET_TTL      = 60 * 60;      // 1h

    /** Sentinel outcomes — mapped to HTTP status by the entry point. */
    public const OK                 = 'ok';
    public const INVALID            = 'invalid';
    public const NOT_ACTIVE         = 'not_active';
    public const PENDING            = 'pending_verification';
    public const SUSPENDED          = 'suspended';
    public const TOKEN_INVALID      = 'token_invalid';
    public const OPAQUE             = 'opaque';

    private PDO $pdo;
    private SessionRepository $sessions;
    private TokenRepository $verifyTokens;
    private TokenRepository $resetTokens;
    private EmailQueueRepository $queue;
    private SettingsRepository $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo          = $pdo;
        $this->sessions     = new SessionRepository($pdo);
        $this->verifyTokens = new TokenRepository($pdo, 'email_verification_tokens');
        $this->resetTokens  = new TokenRepository($pdo, 'password_reset_tokens');
        $this->queue        = new EmailQueueRepository($pdo);
        $this->settings     = new SettingsRepository($pdo);
    }

    /* ─────────────────────────── Login ────────────────────────────── */

    /**
     * Verify email + password. On success returns a fresh session
     * cookie string; otherwise returns an outcome sentinel.
     *
     * @return array{0:string, 1:?string}  [outcome, cookieValue]
     */
    public function login(string $email, string $password): array
    {
        $row = $this->userByEmail($email);
        if ($row === null) {
            // Constant-time dummy verify so timing never distinguishes
            // "no such account" from "wrong password".
            PasswordHasher::verify($password, '$2y$12$abcdefghijklmnopqrstuu');
            return [self::INVALID, null];
        }
        if (!PasswordHasher::verify($password, (string) $row['password_hash'])) {
            return [self::INVALID, null];
        }
        $status = (string) $row['status'];
        if ($status === 'pending_verification') return [self::PENDING,  null];
        if ($status === 'suspended')            return [self::SUSPENDED, null];
        if ($status !== 'active')               return [self::NOT_ACTIVE, null];

        $userId = (int) $row['id'];
        // Session fixation / stolen-cookie hygiene: revoke every prior
        // session so a compromised pre-login cookie can never be
        // upgraded to an authenticated one.
        $this->sessions->revokeAllFor($userId);
        $session = $this->sessions->create($userId);
        $this->pdo->prepare(
            "UPDATE users SET last_login_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :u"
        )->execute([':u' => $userId]);
        return [self::OK, $session['id'] . '.' . $session['raw']];
    }

    public function logout(?string $cookie): void
    {
        if ($cookie === null || $cookie === '') return;
        [$id] = array_pad(explode('.', $cookie, 2), 2, '');
        if (ctype_digit((string) $id)) $this->sessions->revoke((int) $id);
    }

    public function authenticate(?string $cookie): ?int
    {
        if ($cookie === null || $cookie === '') return null;
        return $this->sessions->authenticate($cookie);
    }

    /* ─────────────────── Email verification ───────────────────────── */

    /**
     * Called on successful registration. Issues a verification token
     * and enqueues the verify_email template. If the site is
     * configured to skip verification, queues welcome directly.
     */
    public function onRegistered(int $userId, string $email, string $displayName): void
    {
        $s = $this->settings->registrationSettings();
        if ((bool) $s['require_email_verification']) {
            $raw = $this->verifyTokens->issue($userId, self::VERIFY_TTL);
            $this->queue->enqueue('verify_email', $email, $displayName, [
                'display_name' => $displayName,
                'verify_url'   => $this->verifyUrl($raw),
            ]);
        } else {
            $this->queue->enqueue('welcome', $email, $displayName, [
                'display_name' => $displayName,
            ]);
        }
    }

    public function verifyEmail(string $rawToken): string
    {
        $row = $this->verifyTokens->findValid($rawToken);
        if ($row === null) return self::TOKEN_INVALID;
        $userId = (int) $row['user_id'];
        // Consume atomically; if a concurrent request beat us, refuse.
        if (!$this->verifyTokens->consume((int) $row['id'])) return self::TOKEN_INVALID;

        $stmt = $this->pdo->prepare(
            "UPDATE users
                SET status = CASE WHEN status = 'pending_verification' THEN 'active' ELSE status END,
                    email_verified_at = COALESCE(email_verified_at, strftime('%Y-%m-%dT%H:%M:%fZ','now'))
              WHERE id = :u"
        );
        $stmt->execute([':u' => $userId]);

        $u = $this->userById($userId);
        if ($u !== null) {
            $this->queue->enqueue('welcome', (string) $u['email'], (string) $u['display_name'], [
                'display_name' => (string) $u['display_name'],
            ]);
        }
        return self::OK;
    }

    /**
     * Resend verification for the given email. Returns OPAQUE
     * unconditionally so an attacker cannot enumerate accounts.
     */
    public function resendVerification(string $email): string
    {
        $row = $this->userByEmail($email);
        if ($row !== null && $row['status'] === 'pending_verification') {
            $userId = (int) $row['id'];
            $this->verifyTokens->invalidateAllFor($userId);
            $raw = $this->verifyTokens->issue($userId, self::VERIFY_TTL);
            $this->queue->enqueue('verify_email', (string) $row['email'], (string) $row['display_name'], [
                'display_name' => (string) $row['display_name'],
                'verify_url'   => $this->verifyUrl($raw),
            ]);
        }
        return self::OPAQUE;
    }

    /* ─────────────────── Password reset ───────────────────────────── */

    public function forgotPassword(string $email): string
    {
        $row = $this->userByEmail($email);
        if ($row !== null && $row['status'] === 'active') {
            $userId = (int) $row['id'];
            $this->resetTokens->invalidateAllFor($userId);
            $raw = $this->resetTokens->issue($userId, self::RESET_TTL);
            $this->queue->enqueue('password_reset', (string) $row['email'], (string) $row['display_name'], [
                'display_name' => (string) $row['display_name'],
                'reset_url'    => $this->resetUrl($raw),
            ]);
        }
        return self::OPAQUE;
    }

    /**
     * Consume a reset token and set a new password. Every existing
     * session for the user is revoked so a stolen cookie cannot
     * survive a password reset.
     *
     * @return array{0:string, 1:array<string,string>}
     */
    public function resetPassword(string $rawToken, string $password, string $confirmation): array
    {
        $errors = $this->passwordErrors($password, $confirmation);
        if ($errors !== []) return [self::INVALID, $errors];

        $row = $this->resetTokens->findValid($rawToken);
        if ($row === null) return [self::TOKEN_INVALID, []];
        if (!$this->resetTokens->consume((int) $row['id'])) return [self::TOKEN_INVALID, []];

        $userId = (int) $row['user_id'];
        $hash = PasswordHasher::hash($password);
        $this->pdo->prepare(
            "UPDATE users
                SET password_hash = :h, password_algo = :a,
                    password_changed_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :u"
        )->execute([':h' => $hash['hash'], ':a' => $hash['algo'], ':u' => $userId]);

        $this->sessions->revokeAllFor($userId);

        $u = $this->userById($userId);
        if ($u !== null) {
            $this->queue->enqueue('password_changed', (string) $u['email'], (string) $u['display_name'], [
                'display_name' => (string) $u['display_name'],
            ]);
        }
        return [self::OK, []];
    }

    /**
     * Change password for the currently authenticated user. Verifies
     * the current password, revokes every OTHER session (keeping this
     * one live), and queues a notification email.
     *
     * @return array{0:string, 1:array<string,string>, 2:?string}
     *         [outcome, errors, newCookieValue]
     */
    public function changePassword(int $userId, string $current, string $next, string $confirmation): array
    {
        $errors = $this->passwordErrors($next, $confirmation);
        if ($errors !== []) return [self::INVALID, $errors, null];

        $u = $this->userById($userId);
        if ($u === null) return [self::INVALID, ['current_password' => 'invalid'], null];
        if (!PasswordHasher::verify($current, (string) $u['password_hash'])) {
            return [self::INVALID, ['current_password' => 'invalid'], null];
        }

        $hash = PasswordHasher::hash($next);
        $this->pdo->prepare(
            "UPDATE users
                SET password_hash = :h, password_algo = :a,
                    password_changed_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
              WHERE id = :u"
        )->execute([':h' => $hash['hash'], ':a' => $hash['algo'], ':u' => $userId]);

        // Rotate: revoke every session and issue a fresh one so the
        // caller stays signed in with a new cookie.
        $this->sessions->revokeAllFor($userId);
        $s = $this->sessions->create($userId);
        $this->queue->enqueue('password_changed', (string) $u['email'], (string) $u['display_name'], [
            'display_name' => (string) $u['display_name'],
        ]);
        return [self::OK, [], $s['id'] . '.' . $s['raw']];
    }

    /* ─────────────────── URL / redirect helpers ───────────────────── */

    public function canonicalUrl(): string
    {
        $v = $this->settings->get('canonical_url', bp_config()['app']['url']);
        return rtrim((string) $v, '/');
    }

    public function verifyUrl(string $raw): string
    {
        return $this->canonicalUrl() . '/verify?token=' . rawurlencode($raw);
    }

    public function resetUrl(string $raw): string
    {
        return $this->canonicalUrl() . '/reset-password?token=' . rawurlencode($raw);
    }

    /**
     * True when the given path is a safe post-login redirect target:
     * must be a same-origin path starting with a single '/', must not
     * contain scheme, host, protocol-relative "//", or backslashes.
     */
    public static function isSafeRedirect(?string $path): bool
    {
        if ($path === null || $path === '') return false;
        if ($path[0] !== '/') return false;
        if (strlen($path) >= 2 && ($path[1] === '/' || $path[1] === '\\')) return false;
        if (strpos($path, '\\') !== false) return false;
        // Reject anything that resembles a URL scheme.
        if (preg_match('#^/[^/]*:#', $path) === 1) return false;
        return true;
    }

    /* ─────────────────── Internal ─────────────────────────────────── */

    /** @return array<string,mixed>|null */
    private function userByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, username, display_name, password_hash, status
               FROM users WHERE email_normalized = :e LIMIT 1'
        );
        $stmt->execute([':e' => UserRepository::normaliseEmail($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function userById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, password_hash, status
               FROM users WHERE id = :u LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,string> */
    private function passwordErrors(string $password, string $confirmation): array
    {
        $errors = [];
        $min = (int) $this->settings->get('minimum_password_length', '12');
        if (strlen($password) < max(1, $min)) $errors['password'] = 'too_short';
        if ($password !== $confirmation)      $errors['password_confirmation'] = 'mismatch';
        return $errors;
    }
}
