<?php
/**
 * UserRepository — case-insensitive user reads and inserts.
 *
 * All uniqueness checks compare against the normalised (lower-cased)
 * form of the field so `Alice@Example.com` and `alice@example.com`
 * count as the same account. The database-level unique indexes on
 * `email_normalized` and `username_normalized` guarantee this even
 * when two writers race.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function normaliseUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM users WHERE email_normalized = :e LIMIT 1'
        );
        $stmt->execute([':e' => self::normaliseEmail($email)]);
        return (bool) $stmt->fetch();
    }

    public function usernameExists(string $username): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM users
              WHERE username_normalized = :u
                 OR username = :raw
              LIMIT 1'
        );
        $stmt->execute([
            ':u'   => self::normaliseUsername($username),
            ':raw' => $username,
        ]);
        return (bool) $stmt->fetch();
    }

    /**
     * Insert a new user. Returns the id on success, or null if the
     * database rejected the row (typically a race with a duplicate
     * email or username — the caller responds with the same generic
     * "accepted" payload either way).
     *
     * @param array{
     *   email:string, username:string, display_name:string,
     *   password_hash:string, password_algo:string, status:string,
     *   terms_accepted_at:string
     * } $row
     */
    public function insert(array $row): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO users (
                    email, email_normalized, username, username_normalized,
                    display_name, password_hash, password_algo, status,
                    terms_accepted_at, created_at, updated_at
                 ) VALUES (
                    :email, :email_norm, :username, :username_norm,
                    :display, :hash, :algo, :status,
                    :terms,
                    strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                    strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                 )"
            );
            $stmt->execute([
                ':email'         => $row['email'],
                ':email_norm'    => self::normaliseEmail($row['email']),
                ':username'      => $row['username'],
                ':username_norm' => self::normaliseUsername($row['username']),
                ':display'       => $row['display_name'],
                ':hash'          => $row['password_hash'],
                ':algo'          => $row['password_algo'],
                ':status'        => $row['status'],
                ':terms'         => $row['terms_accepted_at'],
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            // Unique-index violation — treat as a duplicate.
            return null;
        }
    }
}
