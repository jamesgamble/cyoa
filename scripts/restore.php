<?php
/**
 * scripts/restore.php — restore a backup by name. Verifies checksum and
 * integrity, takes a pre-restore safety backup, then swaps under lock.
 *
 * Usage: php scripts/restore.php list
 *        php scripts/restore.php <backup-file-name> --yes
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$svc = new App\BackupService();
$arg = $argv[1] ?? 'list';
if ($arg === 'list') {
    foreach ($svc->list() as $b) {
        fwrite(STDOUT, sprintf("%s  %d bytes  %s\n", $b['name'], $b['size'], gmdate('c', $b['mtime'])));
    }
    exit(0);
}
if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "refusing to restore without --yes (put the site in read-only mode first)\n");
    exit(2);
}
try {
    $safety = $svc->restore($arg);
    fwrite(STDOUT, "restored $arg\n");
    if ($safety !== '') fwrite(STDOUT, "previous database saved as $safety\n");
    fwrite(STDOUT, "next: php scripts/migrate.php && php scripts/integrity-check.php\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "restore failed: " . $e->getMessage() . "\n");
    exit(1);
}
