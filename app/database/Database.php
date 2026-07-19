<?php
/**
 * Database.php — a small wrapper around PDO SQLite that applies the
 * pragmas Branching Paths depends on and hides the DSN details from
 * calling code.
 *
 * Pragmas set on every connection:
 *   - journal_mode = WAL          (concurrent readers with a single writer)
 *   - foreign_keys = ON           (FKs are OFF by default in SQLite)
 *   - busy_timeout = 10000        (retry-on-lock window, in ms)
 *   - synchronous  = NORMAL       (WAL-safe durability without full fsync)
 *
 * The class is intentionally not a singleton: callers pass around the
 * PDO instance so tests can supply their own. `Database::open()` is a
 * convenience factory that reads `bp_config()` and applies the
 * standard settings.
 */

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
     * Open the configured SQLite database and return a PDO instance
     * with the standard pragmas applied.
     */
    public static function open(?string $path = null): PDO
    {
        $config = bp_config();
        $path = $path ?? $config['database']['path'];
        $busyMs = (int) $config['database']['busy_timeout_ms'];

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('database directory is not writable');
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Do not surface the raw driver message; it may include a
            // filesystem path.
            throw new RuntimeException('database connection failed');
        }

        self::applyPragmas($pdo, $busyMs);
        return $pdo;
    }

    /**
     * Apply the standard pragmas to a freshly opened PDO connection.
     * Public for tests that want to reuse the pragma set against an
     * `:memory:` handle.
     */
    public static function applyPragmas(PDO $pdo, int $busyTimeoutMs = 10000): void
    {
        // WAL cannot be applied to in-memory databases; skip silently.
        $isMemory = false;
        try {
            $isMemory = ($pdo->query('PRAGMA database_list')->fetch()['file'] ?? '') === '';
        } catch (\Throwable $_) {
            // Older SQLite builds without database_list still accept
            // WAL setup fine.
        }

        if (!$isMemory) {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);
    }

    /**
     * Return the current journal mode. Useful for diagnostics.
     */
    public static function journalMode(PDO $pdo): string
    {
        $row = $pdo->query('PRAGMA journal_mode')->fetch();
        return strtolower((string) ($row['journal_mode'] ?? ''));
    }

    /**
     * Whether foreign key enforcement is currently active.
     */
    public static function foreignKeysEnabled(PDO $pdo): bool
    {
        $row = $pdo->query('PRAGMA foreign_keys')->fetch();
        return ((int) ($row['foreign_keys'] ?? 0)) === 1;
    }
}
