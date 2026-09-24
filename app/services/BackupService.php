<?php
/**
 * BackupService — safe SQLite backup, restore, retention, and integrity
 * checks (v0.27.0).
 *
 * Backups never copy the live file byte-for-byte. They use SQLite's own
 * `VACUUM INTO`, which produces a consistent, self-contained snapshot
 * even while WAL readers are active. The write lock is held for the
 * duration so no application write interleaves with the snapshot.
 *
 * Restores verify the candidate backup first, take a safety backup of
 * the current database, and then swap the file in under the write lock
 * after checkpointing and removing stale -wal/-shm files.
 */

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class BackupService
{
    public const PREFIX = 'branching-paths-';

    private string $dbPath;
    private string $backupDir;
    private WriteLock $lock;

    public function __construct(?string $dbPath = null, ?string $backupDir = null, ?WriteLock $lock = null)
    {
        $config = bp_config();
        $this->dbPath    = $dbPath ?? $config['database']['path'];
        $this->backupDir = $backupDir ?? BP_ROOT . '/private/backups';
        $this->lock      = $lock ?? new WriteLock();
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0770, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('backup directory is not writable');
        }
    }

    /** Create a consistent snapshot. Returns the backup file name (not path). */
    public function backup(string $label = ''): string
    {
        if (!is_file($this->dbPath)) {
            throw new RuntimeException('database not found');
        }
        $label = preg_replace('/[^a-z0-9-]/', '', strtolower($label)) ?? '';
        $name = self::PREFIX . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3))
              . ($label !== '' ? '-' . $label : '') . '.sqlite';
        $target = $this->backupDir . '/' . $name;

        $this->lock->withLock(function () use ($target): void {
            $pdo = Database::open($this->dbPath);
            $stmt = $pdo->prepare('VACUUM INTO :t');
            $stmt->execute([':t' => $target]);
            $pdo = null;
        });

        $check = self::integrityOf($target);
        if (!$check['ok']) {
            @unlink($target);
            throw new RuntimeException('backup failed integrity check');
        }
        @chmod($target, 0640);
        file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . $name . "\n");
        return $name;
    }

    /**
     * Restore a named backup. A safety backup of the current database is
     * taken first; its name is returned so operators can roll back.
     */
    public function restore(string $name): string
    {
        $source = $this->resolve($name);
        $sumFile = $source . '.sha256';
        if (is_file($sumFile)) {
            $expected = strtok((string) file_get_contents($sumFile), ' ');
            if (!hash_equals((string) $expected, hash_file('sha256', $source))) {
                throw new RuntimeException('backup checksum mismatch');
            }
        }
        if (!self::integrityOf($source)['ok']) {
            throw new RuntimeException('backup failed integrity check');
        }

        $safety = is_file($this->dbPath) ? $this->backup('pre-restore') : '';

        $this->lock->withLock(function () use ($source): void {
            $dir = dirname($this->dbPath);
            $tmp = $dir . '/.restore-' . bin2hex(random_bytes(4)) . '.sqlite';
            if (!copy($source, $tmp)) {
                throw new RuntimeException('restore copy failed');
            }
            if (is_file($this->dbPath)) {
                try {
                    $pdo = Database::open($this->dbPath);
                    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    $pdo = null;
                } catch (\Throwable $e) { /* continue: file is replaced below */ }
            }
            @unlink($this->dbPath . '-wal');
            @unlink($this->dbPath . '-shm');
            if (!rename($tmp, $this->dbPath)) {
                @unlink($tmp);
                throw new RuntimeException('restore swap failed');
            }
        });

        return $safety;
    }

    /**
     * Apply retention: keep the newest $keep backups plus one per day for
     * the last $days days. Returns the deleted names.
     *
     * @return list<string>
     */
    public function prune(int $keep = 14, int $days = 30, ?int $now = null): array
    {
        $now ??= time();
        $keep = max(1, $keep);
        $list = $this->list();
        $kept = [];
        $seenDays = [];
        $deleted = [];
        foreach ($list as $i => $b) {
            $day = gmdate('Y-m-d', $b['mtime']);
            $withinDays = $b['mtime'] >= $now - $days * 86400;
            if ($i < $keep || ($withinDays && !isset($seenDays[$day]))) {
                $kept[] = $b['name'];
                $seenDays[$day] = true;
                continue;
            }
            @unlink($this->backupDir . '/' . $b['name']);
            @unlink($this->backupDir . '/' . $b['name'] . '.sha256');
            $deleted[] = $b['name'];
        }
        return $deleted;
    }

    /** @return list<array{name:string,size:int,mtime:int}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->backupDir . '/' . self::PREFIX . '*.sqlite') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
        return $out;
    }

    /** @return array{ok:bool,problems:list<string>} */
    public function integrity(): array
    {
        return self::integrityOf($this->dbPath);
    }

    /** @return array{ok:bool,problems:list<string>} */
    public static function integrityOf(string $path): array
    {
        if (!is_file($path)) return ['ok' => false, 'problems' => ['file missing']];
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $problems = [];
            foreach ($pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) as $r) {
                if ($r !== 'ok') $problems[] = (string) $r;
            }
            $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($fk as $r) {
                $problems[] = 'foreign key violation in ' . ($r['table'] ?? '?');
            }
            return ['ok' => $problems === [], 'problems' => $problems];
        } catch (\Throwable $e) {
            return ['ok' => false, 'problems' => ['not a readable SQLite database']];
        }
    }

    private function resolve(string $name): string
    {
        $base = basename($name);
        if ($base !== $name || !str_starts_with($base, self::PREFIX) || !str_ends_with($base, '.sqlite')) {
            throw new RuntimeException('invalid backup name');
        }
        $path = $this->backupDir . '/' . $base;
        if (!is_file($path)) throw new RuntimeException('backup not found');
        return $path;
    }
}
