<?php
/**
 * RegistrationRateLimiter — enforces the per-IP hourly ceiling.
 *
 * Uses the `registration_attempts` ledger. `recentCount()` counts rows
 * from the last hour for a given IP; `record()` appends a row for
 * every POST regardless of outcome, so an attacker cannot avoid the
 * limit by intentionally submitting invalid payloads.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class RegistrationRateLimiter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function recentCount(string $ip): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM registration_attempts
             WHERE ip = :ip
               AND occurred_at >= strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-1 hour')"
        );
        $stmt->execute([':ip' => $ip]);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    public function record(string $ip, string $outcome): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_attempts (ip, outcome) VALUES (:ip, :o)'
        )->execute([':ip' => $ip, ':o' => $outcome]);
    }

    /** True when the given IP has already exceeded the ceiling. */
    public function isBlocked(string $ip, int $perHour): bool
    {
        if ($perHour <= 0) {
            return false;
        }
        return $this->recentCount($ip) >= $perHour;
    }
}
