<?php
/**
 * scripts/process-notification-digests.php
 *
 * Aggregates pending followed-adventure updates into one email per
 * recipient and hands them to the ordinary email queue. Intended for
 * a slower cron cadence than the email worker, e.g. hourly:
 *
 *   17 * * * * /usr/bin/php /var/www/branching-paths/scripts/process-notification-digests.php
 *
 * The in-site notifications were already delivered the moment the
 * update happened; this only controls the email side, which is why
 * running it rarely is safe.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\NotificationService;
use App\WriteLock;

$lockPath = bp_resolve_path('private/locks/digest-worker.lock');
$dir = dirname($lockPath);
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$handle = @fopen($lockPath, 'c');
if ($handle === false) {
    fwrite(STDERR, "error: digest lock file could not be opened\n");
    exit(1);
}
if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); exit(0); }

try {
    $pdo = Database::open();
    $svc = new NotificationService($pdo);
    $report = (new WriteLock())->withLock(static fn () => $svc->flushDigests());
    fwrite(STDOUT, sprintf(
        "digest worker: users=%d entries=%d\n",
        $report['users'], $report['entries']
    ));
} catch (\Throwable $e) {
    error_log('[bp] digest worker error: ' . $e->getMessage());
    fwrite(STDERR, "digest worker: aborted (see php-error.log)\n");
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
