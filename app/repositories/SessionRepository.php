<?php
/**
 * SessionRepository — database-backed login sessions.
 *
 * The cookie transmitted to the browser is `<session_id>.<raw_token>`
 * where raw_token is 32 cryptographically random bytes base64-encoded.
 * The server stores only SHA-256(raw_token). An attacker who reads a
 * database dump gets the id and the hash but no way to reconstruct
 * the token — and thus no way to authenticate.
 *
 * Sessions carry a bounded `expires_at` (14 days by default). Every
 * successful `authenticate()` refreshes `last_active_at`.
 *
 * Revocation writes `revoked_at`. Any session with a non-null
 * revoked_at, an expired expires_at, or a mismatched hash is rejected
 * before authenticate() returns.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class SessionRepository
{
    public const TTL_SECONDS = 60 * 60 * 24 * 14; // 14 days

    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @return array{id:int, raw:string} */
    public function create(int $userId, int $ttlSeconds = self::TTL_SECONDS): array
    {
        $raw = TokenRepository::generate();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + max(60, $ttlSeconds));
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, token_hash, expires_at)
             VALUES (:u, :h, :e)'
        );
        $stmt->execute([':u' => $userId, ':h' => TokenRepository::hash($raw), ':e' => $expires]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'raw' => $raw];
    }

    /**
     * Authenticate a cookie value of the form "id.raw". Returns the
     * user_id on success, or null. On success, `last_active_at` is
     * touched so an idle-timeout policy could later expire dormant
     * sessions.
     */
    public function authenticate(string $cookie): ?int
    {
        if ($cookie === '' || strpos($cookie, '.') === false) return null;
        [$idStr, $raw] = explode('.', $cookie, 2);
        if (!ctype_digit($idStr) || $raw === '') return null;
        $id = (int) $idStr;
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, expires_at, revoked_at
               FROM sessions WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        if ($row['revoked_at'] !== null) return null;
        if ($row['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) return null;
        if (!hash_equals((string) $row['token_hash'], TokenRepository::hash($raw))) return null;

        $this->pdo->prepare(
            "UPDATE sessions SET last_active_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :id"
        )->execute([':id' => $id]);

        return (int) $row['user_id'];
    }

    /** Revoke a single session by id. */
    public function revoke(int $sessionId): void
    {
        $this->pdo->prepare(
            "UPDATE sessions SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :id AND revoked_at IS NULL"
        )->execute([':id' => $sessionId]);
    }

    /** Revoke every session belonging to a user. */
    public function revokeAllFor(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sessions SET revoked_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE user_id = :u AND revoked_at IS NULL"
        );
        $stmt->execute([':u' => $userId]);
        return $stmt->rowCount();
    }

    public function isActive(int $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sessions
              WHERE id = :id AND revoked_at IS NULL
                AND expires_at > strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              LIMIT 1"
        );
        $stmt->execute([':id' => $sessionId]);
        return (bool) $stmt->fetch();
    }
}
