<?php
/**
 * scripts/process-email-queue.php
 *
 * The email worker. Intended to be run every minute from cron:
 *
 *   * * * * * /usr/bin/php /var/www/branching-paths/scripts/process-email-queue.php
 *
 * It uses a dedicated `flock()`-backed lock file (separate from the
 * SQLite write lock) so that a second cron tick can never spawn two
 * workers competing for the same rows. If the lock is already held
 * the script exits immediately with status 0 — this is the normal
 * "someone else is running" case, not an error.
 *
 * Each run:
 *   - Recovers any 'sending' row abandoned by a crashed previous
 *     worker (see EmailQueueRepository::recoverStale()).
 *   - Claims a bounded batch capped by smtp_settings.batch_size.
 *   - Sends via the SmtpTransport described in smtp_settings.
 *   - Records outcomes with redacted errors and exponential retry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Database;
use App\EmailQueueRepository;
use App\EmailQueueService;
use App\EmailTemplateRepository;
use App\Mailer\SmtpTransport;
use App\SmtpSettingsRepository;

$config = bp_config();
$workerLockPath = bp_resolve_path('private/locks/email-worker.lock');
$dir = dirname($workerLockPath);
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

$handle = @fopen($workerLockPath, 'c');
if ($handle === false) {
    fwrite(STDERR, "error: worker lock file could not be opened\n");
    exit(1);
}
if (!flock($handle, LOCK_EX | LOCK_NB)) {
    // Another worker is running — this is expected under cron overlap.
    fclose($handle);
    exit(0);
}
@ftruncate($handle, 0);
@fwrite($handle, (string) getmypid());
@fflush($handle);

try {
    $pdo = Database::open();
    $smtp = (new SmtpSettingsRepository($pdo))->load();
    if (!$smtp['enabled']) {
        fwrite(STDOUT, "email worker: smtp is disabled — nothing to do\n");
    } else {
        $transport = new SmtpTransport($smtp);
        $svc = new EmailQueueService(
            new EmailQueueRepository($pdo),
            new EmailTemplateRepository($pdo),
            $transport,
            $smtp
        );
        $report = $svc->runOnce();
        fwrite(STDOUT, sprintf(
            "email worker: claimed=%d sent=%d retried=%d failed=%d recovered=%d\n",
            $report['claimed'], $report['sent'], $report['retried'],
            $report['failed'], $report['recovered']
        ));
    }
} catch (\Throwable $e) {
    error_log('[bp] email worker error: ' . $e->getMessage());
    fwrite(STDERR, "email worker: aborted (see php-error.log)\n");
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
