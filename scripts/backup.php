<?php
/**
 * scripts/backup.php — create a consistent SQLite backup (VACUUM INTO,
 * under the write lock). Never copies the live file directly.
 *
 * Usage: php scripts/backup.php [label]
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
try {
    $name = (new App\BackupService())->backup($argv[1] ?? '');
    fwrite(STDOUT, "backup created: $name\n");
} catch (\Throwable $e) {
    error_log('[bp] backup: ' . $e->getMessage());
    fwrite(STDERR, "backup failed: " . $e->getMessage() . "\n");
    exit(1);
}
