<?php
/**
 * TokenRepository — hashed, expiring, single-use bearer tokens.
 *
 * v0.13.0 uses two token flavours: verification tokens and password
 * reset tokens. Both share the same shape (user_id, token_hash,
 * expires_at, used_at) so a single generic repository parameterised
 * by table name keeps the auth code short.
 *
 * The raw token is exchanged with the user only once (via email); the
 * server never sees it again. On the wire and in the database we
 * store only SHA-256 of the token, so a database dump cannot be used
 * to construct a valid link. Consuming a token flips `used_at`, which
 * both proves single-use and provides an audit trail.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class TokenRepository
{
    /** Table name — one of the two token tables. */
    private string $table;
    private PDO $pdo;

    public function __construct(PDO $pdo, string $table)
    {
        // Table names are controlled by callers, not user input, but a
        // strict allow-list here means we never construct a SQL name
        // from anything else even by accident.
        if (!in_array($table, ['email_verification_tokens', 'password_reset_tokens'], true)) {
            throw new \InvalidArgumentException('unknown token table');
        }
        $this->pdo = $pdo;
        $this->table = $table;
    }

    /** SHA-256 hex of a raw token. */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** Cryptographically random 32-byte token, URL-safe base64. */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Issue a token for the given user. Returns the raw token to
     * send to the user; only the hash is stored.
     */
    public function issue(int $userId, int $ttlSeconds): string
    {
        $raw = self::generate();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + max(60, $ttlSeconds));
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (user_id, token_hash, expires_at)
             VALUES (:u, :h, :e)"
        );
        $stmt->execute([':u' => $userId, ':h' => self::hash($raw), ':e' => $expires]);
        return $raw;
    }

    /**
     * Look up a raw token; returns the row if it exists, is not yet
     * used, and has not expired. Otherwise returns null. The row is
     * NOT flipped to used here — callers do so via consume() after
     * they have finished the follow-on write.
     *
     * @return array<string,mixed>|null
     */
    public function findValid(string $raw): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, expires_at, used_at
               FROM {$this->table}
              WHERE token_hash = :h LIMIT 1"
        );
        $stmt->execute([':h' => self::hash($raw)]);
        $row = $stmt->fetch();
        if ($row === false) return null;
        if ($row['used_at'] !== null) return null;
        if ($row['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) return null;
        return $row;
    }

    /** Flip the row's used_at atomically. Returns true if it flipped. */
    public function consume(int $tokenId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
                SET used_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE id = :id AND used_at IS NULL"
        );
        $stmt->execute([':id' => $tokenId]);
        return $stmt->rowCount() === 1;
    }

    /** Invalidate every outstanding token for a user (e.g. before issuing a new one). */
    public function invalidateAllFor(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
                SET used_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
              WHERE user_id = :u AND used_at IS NULL"
        );
        $stmt->execute([':u' => $userId]);
    }
}
