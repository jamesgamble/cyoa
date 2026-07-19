<?php
/**
 * scripts/migrate.php — apply pending SQL migrations.
 *
 * Runs every migration under `private/migrations/` that has not yet
 * been recorded in `schema_migrations`, in ascending filename order.
 *
 * Usage:
 *   php scripts/migrate.php          apply pending migrations
 *   php scripts/migrate.php status   list applied vs pending, no changes
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\Migrator;
use App\WriteLock;

$command = $argv[1] ?? 'up';

try {
    $pdo = Database::open();
} catch (\Throwable $e) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

$migrator = new Migrator($pdo);

if ($command === 'status') {
    $applied   = $migrator->appliedVersions();
    $available = array_map(static fn ($m) => $m['version'], $migrator->availableMigrations());
    $appliedIx = array_flip($applied);
    fwrite(STDOUT, "applied:\n");
    foreach ($applied as $v) {
        fwrite(STDOUT, "  [x] $v\n");
    }
    fwrite(STDOUT, "pending:\n");
    foreach ($available as $v) {
        if (!isset($appliedIx[$v])) {
            fwrite(STDOUT, "  [ ] $v\n");
        }
    }
    fwrite(STDOUT, "current schema version: " . $migrator->currentVersion() . "\n");
    exit(0);
}

if ($command !== 'up') {
    fwrite(STDERR, "unknown command: $command\n");
    fwrite(STDERR, "usage: php scripts/migrate.php [up|status]\n");
    exit(2);
}

$lock = new WriteLock();
try {
    $ran = $lock->withLock(static fn () => $migrator->migrate());
} catch (\Throwable $e) {
    fwrite(STDERR, "migration failed\n");
    error_log('[bp] migrate: ' . $e->getMessage());
    exit(1);
}

if ($ran === []) {
    fwrite(STDOUT, "no pending migrations\n");
} else {
    foreach ($ran as $v) {
        fwrite(STDOUT, "applied $v\n");
    }
}
fwrite(STDOUT, "current schema version: " . $migrator->currentVersion() . "\n");
