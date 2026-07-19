<?php
/**
 * scripts/system-check.php — pre-flight diagnostics for operators.
 *
 * Verifies the PHP runtime, required extensions, filesystem layout,
 * SQLite pragmas, and write-lock behaviour. Emits a summary of pass/
 * warn/fail lines and exits non-zero if any check fails.
 *
 * This script is intentionally the ONLY surface that reveals paths,
 * because it runs on the server and is invoked by operators — never
 * over HTTP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\WriteLock;

$failed = 0;
$warned = 0;

function pass(string $msg): void { fwrite(STDOUT, "  ok    $msg\n"); }
function warn(string $msg): void { global $warned; $warned++; fwrite(STDOUT, "  warn  $msg\n"); }
function fail(string $msg): void { global $failed; $failed++; fwrite(STDOUT, "  fail  $msg\n"); }

fwrite(STDOUT, "PHP runtime\n");
if (PHP_VERSION_ID >= 80200) {
    pass('php version ' . PHP_VERSION);
} else {
    fail('php >= 8.2 required, found ' . PHP_VERSION);
}

foreach (['pdo', 'pdo_sqlite', 'json', 'mbstring'] as $ext) {
    if (extension_loaded($ext)) {
        pass("extension $ext");
    } else {
        fail("missing extension $ext");
    }
}

$config = bp_config();

fwrite(STDOUT, "\nFilesystem\n");
$paths = [
    'root'        => BP_ROOT,
    'data dir'    => dirname($config['database']['path']),
    'lock dir'    => dirname($config['lock']['path']),
    'logs dir'    => BP_ROOT . '/private/logs',
    'migrations'  => $config['migrations']['path'],
];
foreach ($paths as $label => $path) {
    if (!is_dir($path)) {
        fail("$label missing: $path (run scripts/initialize.php)");
        continue;
    }
    if (!is_writable($path)) {
        fail("$label not writable: $path");
        continue;
    }
    pass("$label writable ($path)");
}

fwrite(STDOUT, "\nSQLite\n");
try {
    $pdo = Database::open();
    $mode = Database::journalMode($pdo);
    if ($mode === 'wal') {
        pass('journal_mode = wal');
    } else {
        warn("journal_mode = $mode (expected wal)");
    }
    if (Database::foreignKeysEnabled($pdo)) {
        pass('foreign_keys = on');
    } else {
        fail('foreign_keys is off');
    }
    $busy = $pdo->query('PRAGMA busy_timeout')->fetch();
    $busyMs = (int) ($busy['timeout'] ?? 0);
    if ($busyMs >= 10000) {
        pass("busy_timeout = {$busyMs}ms");
    } else {
        warn("busy_timeout = {$busyMs}ms (expected >= 10000)");
    }
    $sync = $pdo->query('PRAGMA synchronous')->fetch();
    $syncValue = (int) ($sync['synchronous'] ?? -1);
    // NORMAL = 1
    if ($syncValue === 1) {
        pass('synchronous = normal');
    } else {
        warn("synchronous = $syncValue (expected 1 = normal)");
    }
} catch (\Throwable $e) {
    fail('could not open database');
}

fwrite(STDOUT, "\nWrite lock\n");
try {
    $lock = new WriteLock();
    $lock->acquire(1000);
    if ($lock->isHeld()) {
        pass('acquired write lock');
    } else {
        fail('write lock reported not held after acquire');
    }
    $lock->release();
    pass('released write lock');
} catch (\Throwable $e) {
    fail('write lock failed');
}

fwrite(STDOUT, "\nSummary\n");
fwrite(STDOUT, "  failures: $failed\n");
fwrite(STDOUT, "  warnings: $warned\n");
fwrite(STDOUT, '  app version: ' . $config['app']['version'] . "\n");

exit($failed > 0 ? 1 : 0);
