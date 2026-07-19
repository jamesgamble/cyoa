<?php
/**
 * Migrator — applies numbered SQL migrations from
 * `private/migrations/*.sql` in order, tracking which have run in the
 * `schema_migrations` table.
 *
 * Migrations are plain `.sql` files named `NNNN_short_slug.sql` (for
 * example `0001_initial.sql`). Each file is executed inside a single
 * SQLite transaction that only commits when every statement in the
 * file succeeds. If a migration fails mid-file it is rolled back
 * entirely and the failure is surfaced to the caller.
 */

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    private PDO $pdo;
    private string $migrationsPath;

    public function __construct(PDO $pdo, ?string $migrationsPath = null)
    {
        $this->pdo = $pdo;
        $config = bp_config();
        $this->migrationsPath = $migrationsPath ?? $config['migrations']['path'];
        $this->ensureTrackingTable();
    }

    /**
     * Ensure the schema_migrations table exists. Idempotent.
     */
    public function ensureTrackingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            '  version TEXT PRIMARY KEY,' .
            '  applied_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))' .
            ')'
        );
    }

    /**
     * Return the identifiers of every migration file discovered on
     * disk, sorted lexicographically (which for zero-padded names is
     * numerical order).
     *
     * @return array<int, array{version: string, path: string}>
     */
    public function availableMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'version' => basename($file, '.sql'),
                'path'    => $file,
            ];
        }
        return $out;
    }

    /**
     * Return the list of migration versions already applied.
     *
     * @return array<int, string>
     */
    public function appliedVersions(): array
    {
        $rows = $this->pdo->query(
            'SELECT version FROM schema_migrations ORDER BY version ASC'
        )->fetchAll();
        return array_map(static fn ($r) => (string) $r['version'], $rows);
    }

    /**
     * The highest applied version, or "0" when no migrations have
     * been applied yet. Suitable for the health endpoint.
     */
    public function currentVersion(): string
    {
        $rows = $this->appliedVersions();
        if ($rows === []) {
            return '0';
        }
        return end($rows);
    }

    /**
     * Apply every pending migration. Returns the list of versions
     * that were applied by this call.
     *
     * @return array<int, string>
     */
    public function migrate(): array
    {
        $applied = array_flip($this->appliedVersions());
        $pending = array_values(array_filter(
            $this->availableMigrations(),
            static fn ($m) => !isset($applied[$m['version']])
        ));

        $ran = [];
        foreach ($pending as $migration) {
            $this->applyOne($migration['version'], $migration['path']);
            $ran[] = $migration['version'];
        }
        return $ran;
    }

    /**
     * Apply a single migration inside a transaction.
     */
    private function applyOne(string $version, string $path): void
    {
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('migration ' . $version . ' could not be read');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($sql);
            $stmt = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version) VALUES (:v)'
            );
            $stmt->execute([':v' => $version]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Preserve the original message for the CLI/log only —
            // callers relaying to end users should map this to a
            // generic "migration failed" string.
            throw new RuntimeException(
                'migration ' . $version . ' failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
