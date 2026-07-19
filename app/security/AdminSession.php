<?php
/**
 * AdminSession — HMAC-signed cookie sessions for the master console.
 *
 * The registration flow does not yet have logins wired up (that is a
 * future prompt), but v0.12.0 has to gate the SMTP settings and
 * queue pages behind administrator authentication *now*. Rather than
 * add a full session store, this class:
 *
 *   1. Reads a random secret from `private/keys/session.key` (created
 *      on first use — 32 bytes, mode 0600). The secret is scoped to
 *      admin sessions and is separate from the application encryption
 *      key so rotating one never invalidates the other.
 *   2. Issues a cookie of the form  base64url(payload).base64url(hmac)
 *      where payload is {uid, exp} JSON. The cookie is HttpOnly,
 *      SameSite=Strict, and Secure when the request is HTTPS.
 *   3. Verifies signatures with hash_equals() so an attacker cannot
 *      time-side-channel the MAC.
 *
 * Admin authorization is a two-step check: the cookie proves who the
 * user is; the caller must then confirm that user_roles row still
 * grants 'admin'. This keeps role revocation instant.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class AdminSession
{
    public const COOKIE = 'bp_admin';
    public const TTL_SECONDS = 60 * 60 * 8; // 8h

    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? self::loadOrCreateSecret();
    }

    private static function loadOrCreateSecret(): string
    {
        $path = bp_resolve_path('private/keys/session.key');
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        if (is_file($path)) {
            $data = @file_get_contents($path);
            if (is_string($data) && strlen($data) >= 32) {
                return $data;
            }
        }
        $key = random_bytes(32);
        @file_put_contents($path, $key);
        @chmod($path, 0600);
        return $key;
    }

    /**
     * Verify a login (email + password) against the users table and,
     * on success, set the session cookie. Returns the user id or null.
     */
    public function login(PDO $pdo, string $email, string $password): ?int
    {
        $email = trim($email);
        if ($email === '' || $password === '') { return null; }
        $stmt = $pdo->prepare(
            'SELECT id, password_hash, status FROM users
              WHERE email_normalized = :e LIMIT 1'
        );
        $stmt->execute([':e' => UserRepository::normaliseEmail($email)]);
        $row = $stmt->fetch();
        if ($row === false) {
            // Perform a dummy verify so timing does not leak account existence.
            PasswordHasher::verify($password, '$2y$12$abcdefghijklmnopqrstuu');
            return null;
        }
        if ($row['status'] !== 'active') { return null; }
        if (!PasswordHasher::verify($password, (string) $row['password_hash'])) {
            return null;
        }
        $userId = (int) $row['id'];
        if (!self::hasRole($pdo, $userId, 'admin')) { return null; }

        $this->issue($userId);
        return $userId;
    }

    public function issue(int $userId): void
    {
        $exp = time() + self::TTL_SECONDS;
        $payload = self::b64u(json_encode(['uid' => $userId, 'exp' => $exp]));
        $mac = self::b64u(hash_hmac('sha256', $payload, $this->secret, true));
        $token = $payload . '.' . $mac;

        $secure = (($_SERVER['HTTPS'] ?? 'off') !== 'off');
        setcookie(self::COOKIE, $token, [
            'expires'  => $exp,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    public function clear(): void
    {
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    /**
     * Return the authenticated admin user id from the cookie, or null.
     * A second query confirms the 'admin' role is still granted so a
     * revoke takes effect immediately.
     */
    public function authenticate(PDO $pdo): ?int
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($raw) || strpos($raw, '.') === false) { return null; }
        [$payload, $mac] = explode('.', $raw, 2);
        $expected = self::b64u(hash_hmac('sha256', $payload, $this->secret, true));
        if (!hash_equals($expected, $mac)) { return null; }
        $decoded = json_decode(self::b64uDecode($payload), true);
        if (!is_array($decoded)) { return null; }
        $uid = (int) ($decoded['uid'] ?? 0);
        $exp = (int) ($decoded['exp'] ?? 0);
        if ($uid <= 0 || $exp < time()) { return null; }
        if (!self::hasRole($pdo, $uid, 'admin')) { return null; }
        return $uid;
    }

    public static function hasRole(PDO $pdo, int $userId, string $role): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM user_roles WHERE user_id = :u AND role = :r LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':r' => $role]);
        return (bool) $stmt->fetch();
    }

    private static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad !== 0) { $data .= str_repeat('=', 4 - $pad); }
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
