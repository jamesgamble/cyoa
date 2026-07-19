<?php
/**
 * scripts/initialize.php — first-time project setup.
 *
 * Creates the private directories the application depends on (data,
 * logs, locks, migrations, backups) and opens the SQLite database so
 * the WAL/shm files exist before any user traffic arrives.
 *
 * Safe to run repeatedly; nothing is destroyed.
 *
 * Usage:
 *   php scripts/initialize.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database;

$config = bp_config();

$dirs = [
    BP_ROOT . '/private',
    BP_ROOT . '/private/data',
    BP_ROOT . '/private/logs',
    BP_ROOT . '/private/locks',
    BP_ROOT . '/private/backups',
    BP_ROOT . '/private/migrations',
    dirname($config['database']['path']),
    dirname($config['lock']['path']),
];

foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        fwrite(STDOUT, "exists  $dir\n");
        continue;
    }
    if (@mkdir($dir, 0775, true) || is_dir($dir)) {
        fwrite(STDOUT, "created $dir\n");
    } else {
        fwrite(STDERR, "failed  $dir\n");
        exit(1);
    }
}

// Warm the SQLite file so WAL is applied from the start.
try {
    $pdo = Database::open();
    $mode = Database::journalMode($pdo);
    fwrite(STDOUT, "database ready — journal_mode=$mode\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "database initialization failed\n");
    exit(1);
}

fwrite(STDOUT, "initialize: done\n");
