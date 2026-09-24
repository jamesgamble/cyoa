<?php
/**
 * scripts/prune-backups.php — backup retention. Keeps the newest N
 * backups plus one per day for the last D days.
 *
 * Usage: php scripts/prune-backups.php [keep=14] [days=30]
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$keep = (int) ($argv[1] ?? 14);
$days = (int) ($argv[2] ?? 30);
$deleted = (new App\BackupService())->prune($keep, $days);
foreach ($deleted as $d) fwrite(STDOUT, "deleted $d\n");
fwrite(STDOUT, count($deleted) . " backup(s) removed\n");
