<?php
/**
 * scripts/integrity-check.php — PRAGMA integrity_check and
 * foreign_key_check on the live database (or a named file).
 *
 * Usage: php scripts/integrity-check.php [path-to-sqlite]
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$path = $argv[1] ?? bp_config()['database']['path'];
$r = App\BackupService::integrityOf($path);
if ($r['ok']) { fwrite(STDOUT, "integrity: ok\n"); exit(0); }
foreach ($r['problems'] as $p) fwrite(STDOUT, "problem: $p\n");
exit(1);
