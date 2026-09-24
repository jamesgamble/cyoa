<?php
/**
 * tests/e2e/release_e2e.php — the 1.0.0 release check.
 *
 * Performs a clean install from the working tree into a temporary
 * directory and drives every core workflow over real HTTP against the
 * PHP built-in server, with a local SMTP sink standing in for the
 * mail provider. Nothing leaves 127.0.0.1.
 *
 * Usage: php tests/e2e/release_e2e.php [--skip-frontend] [--keep]
 *   --skip-frontend  reuse an existing frontend/dist instead of npm ci + build
 *   --keep           leave the temporary install on disk for inspection
 */
declare(strict_types=1);

$SRC   = dirname(__DIR__, 2);
$PHP   = PHP_BINARY;
$WORK  = sys_get_temp_dir() . '/bp-release-' . getmypid();
$SITE  = "$WORK/site";
$STORE = "$WORK/storage";
$MAIL  = "$WORK/mail";
$skipFrontend = in_array('--skip-frontend', $argv, true);
$keep = in_array('--keep', $argv, true);
$procs = [];
$step = 0;
$failed = false;

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function ok(string $label): void { global $step; $step++; out(sprintf("  ok  %2d. %s", $step, $label)); }
function fail(string $label, $detail = null): never {
    global $step;
    out(sprintf("  X   %2d. %s", $step + 1, $label));
    if ($detail !== null) out('      ' . (is_string($detail) ? $detail : json_encode($detail)));
    throw new RuntimeException($label);
}
function check(bool $cond, string $label, $detail = null): void { if (!$cond) fail($label, $detail); }
function sh(string $cmd, array $env = [], ?string $cwd = null): array {
    $full = '';
    foreach ($env as $k => $v) $full .= 'export ' . $k . '=' . escapeshellarg($v) . '; ';
    $full .= '{ ' . $cmd . '; } 2>&1';
    $o = []; $code = 0;
    exec(($cwd ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $full, $o, $code);
    return [$code, implode("\n", $o)];
}

/** Minimal cookie-aware JSON client that follows the double-submit CSRF rules. */
final class Client {
    public array $cookies = [];
    public int $status = 0;
    public array $headers = [];
    public string $raw = '';
    public function __construct(private string $base) {}
    public function req(string $method, string $path, ?array $body = null): array {
        if ($method !== 'GET' && !isset($this->cookies['bp_csrf'])) $this->req('GET', '/api/csrf-token');
        $h = ["Accept: application/json", "Content-Type: application/json"];
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if (isset($this->cookies['bp_csrf'])) $h[] = 'X-CSRF-Token: ' . urldecode($this->cookies['bp_csrf']);
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $h),
            'content' => $body === null ? '' : json_encode($body),
            'ignore_errors' => true, 'timeout' => 30,
        ]]);
        $this->raw = (string) @file_get_contents($this->base . $path, false, $ctx);
        $this->headers = $http_response_header ?? [];
        $this->status = 0;
        foreach ($this->headers as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $this->status = (int) $m[1];
            if (stripos($line, 'Set-Cookie:') === 0) {
                $pair = explode(';', trim(substr($line, 11)))[0];
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                if ($v === '' || str_contains(strtolower($line), 'expires=thu, 01 jan 1970')) unset($this->cookies[$k]);
                else $this->cookies[$k] = $v;
            }
        }
        $d = json_decode($this->raw, true);
        return is_array($d) ? $d : [];
    }
}

function startServer(string $docroot, int $port, array $env): void {
    global $PHP, $procs, $SRC;
    $e = array_merge(getenv(), $env);
    $p = proc_open([$PHP, '-S', "127.0.0.1:$port", '-t', $docroot, "$SRC/tests/e2e/router.php"],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', dirname($docroot, 2) . "/server-$port.log", 'a']], $pipes, null, $e);
    $procs[] = $p;
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port);
        if ($s) { fclose($s); return; }
        usleep(100000);
    }
    throw new RuntimeException("server on $port did not start");
}

function mails(): array {
    global $MAIL;
    $files = glob("$MAIL/*.eml") ?: [];
    natsort($files);
    return array_map(static function ($f) {
        $raw = (string) file_get_contents($f);
        // decode quoted-printable / base64 bodies so links are readable
        $decoded = $raw;
        if (stripos($raw, 'quoted-printable') !== false) $decoded .= "\n" . quoted_printable_decode($raw);
        if (preg_match_all('#\r?\n\r?\n([A-Za-z0-9+/=\r\n]{40,})#', $raw, $m)) {
            foreach ($m[1] as $b) { $x = base64_decode(preg_replace('/\s+/', '', $b), true); if ($x) $decoded .= "\n" . $x; }
        }
        return $decoded;
    }, array_values($files));
}
function tokenFromMail(string $to, string $pattern): ?string {
    foreach (array_reverse(mails()) as $m) {
        if (stripos($m, $to) === false) continue;
        if (preg_match($pattern, $m, $mm)) return $mm[1];
    }
    return null;
}
function processQueue(array $env): string {
    global $PHP, $SITE;
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/process-email-queue.php', $env, $SITE);
    return $o;
}

$env = [
    'APP_ENV' => 'production',
    'APP_URL' => 'http://127.0.0.1:8099',
    'DATABASE_PATH' => "$STORE/data/branching-paths.sqlite",
    'WRITE_LOCK_PATH' => "$STORE/locks/write.lock",
];

try {
    out("Branching Paths release check — work dir $WORK");
    mkdir($WORK, 0775, true);

    // ── Clean checkout ────────────────────────────────────────────
    $ex = ['.git', 'node_modules', 'frontend/node_modules', 'frontend/dist', '.lovable', '.workspace',
           'private/data/*', 'private/backups/*', 'private/keys/*', 'private/locks/*', 'private/logs/*', 'src', 'tsconfig.tsbuildinfo'];
    $exArgs = implode(' ', array_map(fn ($e) => '--exclude=' . escapeshellarg("./$e"), $ex));
    [$c, $o] = sh("mkdir -p " . escapeshellarg($SITE) . " && tar -C " . escapeshellarg($SRC) . " $exArgs -cf - . | tar -C " . escapeshellarg($SITE) . " -xf -");
    check($c === 0, 'clean checkout copied', $o);
    check(!file_exists("$SITE/private/data/branching-paths.sqlite"), 'clean checkout has no database');

    // 1–3. Frontend install, build, stage
    if ($skipFrontend) {
        [$c, $o] = sh('cp -R ' . escapeshellarg("$SRC/frontend/dist") . ' ' . escapeshellarg("$SITE/frontend/dist"));
        check($c === 0 && is_file("$SITE/frontend/dist/index.html"), 'reuse frontend/dist (run npm run build first)', $o);
        ok('install frontend dependencies (skipped: --skip-frontend)');
        ok('build static assets (reused frontend/dist)');
    } else {
        [$c, $o] = sh('npm ci --no-audit --no-fund', [], "$SITE/frontend");
        check($c === 0, 'npm ci', substr($o, -800));
        ok('install frontend dependencies');
        [$c, $o] = sh('npm run build', [], "$SITE/frontend");
        check($c === 0 && is_file("$SITE/frontend/dist/index.html"), 'npm run build', substr($o, -800));
        ok('build static assets');
    }
    [$c, $o] = sh('cp -R ' . escapeshellarg("$SITE/frontend/dist") . '/. ' . escapeshellarg("$SITE/public/"));
    check($c === 0 && is_file("$SITE/public/index.html") && is_file("$SITE/public/api/index.php"), 'stage public/', $o);
    $bundle = implode('', array_map('file_get_contents', glob("$SITE/public/assets/*.js") ?: []));
    check(!str_contains($bundle, 'Marisol Vega'), 'production bundle carries no fixture data');
    ok('stage production files');

    // 4–6. Private storage, SQLite, migrations
    mkdir("$STORE/data", 0770, true); mkdir("$STORE/locks", 0770, true);
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/initialize.php', $env, $SITE);
    check($c === 0, 'initialize', $o);
    ok('configure private storage outside public/ (' . $STORE . ')');
    check(is_file($env['DATABASE_PATH']), 'SQLite database file created', $o);
    ok('initialize SQLite');
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/migrate.php', $env, $SITE);
    check($c === 0, 'migrate', $o);
    [$c, $o2] = sh(escapeshellarg($PHP) . ' scripts/migrate.php', $env, $SITE);
    check($c === 0, 'migrate is idempotent', $o2);
    ok('apply migrations (re-run is a no-op)');

    // 7. First administrator
    $adminPass = 'Release-Admin-Passphrase-1';
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/bootstrap-admin.php --email=admin@example.test --username=admin --display=Administrator --password=' . escapeshellarg($adminPass), $env, $SITE);
    check($c === 0, 'bootstrap-admin', $o);
    ok('create the first administrator');

    // Servers
    $sink = proc_open([$PHP, "$SRC/tests/e2e/smtp-sink.php", '2526', $MAIL], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$WORK/sink.log", 'a']], $pp);
    $procs[] = $sink;
    startServer("$SITE/public", 8099, $env);
    $base = 'http://127.0.0.1:8099';
    $h = @file_get_contents("$base/discover");
    check(is_string($h) && str_contains($h, '<div id="root"'), 'SPA shell served for client routes');
    $priv = @file_get_contents("$base/../private/data/branching-paths.sqlite");
    check($priv === false || !str_contains((string) $priv, 'SQLite format'), 'database not reachable over HTTP');

    // 8. Safe SMTP (local sink)
    $admin = new Client($base);
    $admin->req('POST', '/api/auth/login', ['email' => 'admin@example.test', 'password' => $adminPass]);
    check($admin->status === 200, 'admin login', $admin->raw);
    $smtp = ['host' => '127.0.0.1', 'port' => 2526, 'encryption' => 'none', 'username' => '', 'password' => '',
             'from_email' => 'no-reply@example.test', 'from_name' => 'Branching Paths', 'enabled' => true];
    // A fresh sign-in counts as recent authentication (the password was
    // just entered); expiry of that window is covered by the unit tests.
    $admin->req('POST', '/api/auth/reauthenticate', ['password' => $adminPass]);
    check($admin->status === 200, 'admin reauthenticate', $admin->raw);
    $admin->req('PUT', '/api/master/settings/email', $smtp);
    check($admin->status === 200, 'save SMTP settings', $admin->raw);
    $leak = $admin->req('GET', '/api/master/settings');
    check(!preg_match('/password_hash|smtp_password_enc|encryption_key|\.sqlite/i', $admin->raw), 'master settings leak no secrets', $admin->raw);
    ok('configure safe SMTP (local sink on 127.0.0.1:2526)');

    // 9. Queued test email
    $admin->req('POST', '/api/master/settings/email/test', ['to' => 'operator@example.test']);
    check($admin->status === 200, 'queue test email', $admin->raw);
    $o = processQueue($env);
    check(preg_match('/sent=([1-9]\d*)/', $o) === 1 && str_contains($o, 'failed=0'), 'worker sent the queue', $o);
    check(count(array_filter(mails(), fn ($m) => stripos($m, 'To: operator@example.test') !== false || stripos($m, 'operator@example.test') !== false)) >= 1, 'sink received the test email');
    ok('process a queued test email');

    // 10. Register and verify
    $register = static function (string $user, string $email, string $pass) use ($base, $env): Client {
        $c = new Client($base);
        $c->req('POST', '/api/register', ['username' => $user, 'display_name' => ucfirst($user), 'email' => $email,
            'password' => $pass, 'password_confirmation' => $pass, 'terms_accepted' => true, 'nickname_url' => '']);
        check($c->status === 202, "register $user", $c->raw);
        processQueue($env);
        $tok = tokenFromMail($email, '#127\.0\.0\.1:8099/verify\?token=([A-Za-z0-9_\-]+)#');
        check($tok !== null, "verification email for $user", substr(implode("\n----\n", mails()), -1500));
        $c->req('POST', '/api/auth/verify-email', ['token' => $tok]);
        check($c->status === 200, "verify $user", $c->raw);
        $c->req('POST', '/api/auth/verify-email', ['token' => $tok]);
        check($c->status === 400, 'verification token is single-use', $c->raw);
        return $c;
    };
    $passA = 'Author-Passphrase-Long-1'; $passB = 'Contrib-Passphrase-Long-2';
    $a = $register('author', 'author@example.test', $passA);
    $b = $register('contrib', 'contrib@example.test', $passB);
    ok('register and verify users (author, contributor)');

    // 11. Log in
    $a->req('POST', '/api/auth/login', ['email' => 'author@example.test', 'password' => 'wrong-password-123']);
    check($a->status === 401, 'wrong password refused', $a->raw);
    $a->req('POST', '/api/auth/login', ['email' => 'author@example.test', 'password' => $passA]);
    check($a->status === 200, 'author login', $a->raw);
    $b->req('POST', '/api/auth/login', ['email' => 'contrib@example.test', 'password' => $passB]);
    check($b->status === 200, 'contributor login', $b->raw);
    $s = $a->req('GET', '/api/auth/session');
    check(!empty($s['authenticated']), 'session reads back', $a->raw);
    ok('log in');

    // 12. Create
    $d = $a->req('POST', '/api/adventures', [
        'template' => 'open-community', 'title' => 'The Release Road', 'genre' => 'fantasy',
        'description' => 'A short road walked once to prove the release.', 'content_rating' => 'everyone',
        'content_warnings' => [], 'visibility' => 'public', 'contribution_mode' => 'approval',
        'anonymous_contributions' => false, 'max_branches_per_scene' => 4,
        'opening_title' => 'The first milestone', 'opening_body' => '<p>The road begins at a <strong>crooked</strong> stone.</p><script>alert(1)</script>',
        'status' => 'draft', 'writing_guidelines' => 'Keep it kind.',
    ]);
    check($a->status === 201, 'create adventure', $a->raw);
    $slug = (string) ($d['adventure']['slug'] ?? '');
    $opening = (string) ($d['adventure']['opening_scene']['slug'] ?? '');
    check($slug !== '' && $opening !== '', 'adventure slug + opening scene returned', $d);
    (new Client($base))->req('GET', "/api/adventures/$slug");
    ok("create an adventure ($slug)");

    // 13. Publish
    $pub = new Client($base);
    $pub->req('GET', "/api/adventures/$slug");
    check($pub->status === 404, 'draft is not public', [$pub->status]);
    $a->req('PUT', "/api/adventures/$slug/draft", ['description' => 'A short road walked once to prove the release.']);
    check($a->status === 200 || $a->status === 422, 'save draft does not crash', $a->raw);
    $a->req('POST', "/api/adventures/$slug/status", ['action' => 'publish']);
    check($a->status === 200, 'publish', $a->raw);
    $pd = $pub->req('GET', "/api/adventures/$slug");
    check($pub->status === 200, 'published adventure public', $pub->raw);
    $sc = $pub->req('GET', "/api/adventures/$slug/scenes/$opening");
    check($pub->status === 200 && !str_contains($pub->raw, '<script'), 'opening scene public and sanitized', $pub->raw);
    ok('publish it');

    // 14. Submit a branch
    $branch = ['choice_text' => 'Follow the lantern light', 'scene_title' => 'Lantern hollow',
        'scene_body' => '<p>A hollow full of warm light.</p>', 'scene_type' => 'story', 'attribution' => 'username', 'private_note' => ''];
    $bd = $b->req('POST', "/api/adventures/$slug/scenes/$opening/branch", $branch);
    check($b->status === 201, 'submit branch', $b->raw);
    $subId = (int) ($bd['submission_id'] ?? 0);
    check($subId > 0 && ($bd['state'] ?? '') === 'pending', 'submission pending review', $bd);
    ok('submit a branch');

    // 15. Request changes
    $a->req('POST', "/api/adventures/$slug/moderation/submissions/$subId/decision", ['action' => 'request_changes', 'feedback' => '']);
    check($a->status === 422, 'request changes requires feedback', $a->raw);
    $a->req('POST', "/api/adventures/$slug/moderation/submissions/$subId/decision", ['action' => 'request_changes', 'feedback' => 'Please describe the lantern.']);
    check($a->status === 200, 'request changes', $a->raw);
    $b->req('GET', '/api/account/contributions');
    check(str_contains($b->raw, 'Please describe the lantern.'), 'contributor reads feedback', $b->raw);
    ok('request changes');

    // 16. Resubmit
    $b->req('PUT', "/api/account/contributions/$subId", array_merge($branch, ['scene_body' => '<p>A hollow lit by a brass lantern.</p>', 'resubmit' => true]));
    check($b->status === 200, 'resubmit', $b->raw);
    $b->req('GET', '/api/account/contributions');
    check(str_contains($b->raw, 'brass lantern'), 'resubmitted content stored', $b->raw);
    ok('resubmit');

    // 17. Approve
    $a->req('POST', "/api/adventures/$slug/moderation/submissions/$subId/decision", ['action' => 'approve']);
    check($a->status === 200, 'approve', $a->raw);
    $a->req('POST', "/api/adventures/$slug/moderation/submissions/$subId/decision", ['action' => 'approve']);
    check($a->status !== 200, 'double approval refused', $a->raw);
    ok('approve it');

    // 18. Read the path
    $sc = $pub->req('GET', "/api/adventures/$slug/scenes/$opening");
    check(str_contains($pub->raw, 'Follow the lantern light'), 'new choice visible on the opening scene', $pub->raw);
    $next = null;
    array_walk_recursive($sc, function ($v, $k) use (&$next) { if ($k === 'to' || $k === 'target_slug' || $k === 'destination') $next ??= $v; });
    if ($next === null && preg_match('#"(?:slug|to|target)":"(lantern-hollow[^"]*)"#', $pub->raw, $mm)) $next = $mm[1];
    check(is_string($next) && $next !== '', 'choice destination found', $pub->raw);
    $pub->req('GET', "/api/adventures/$slug/scenes/$next");
    check($pub->status === 200 && str_contains($pub->raw, 'brass lantern'), 'resulting scene readable', $pub->raw);
    $sceneId = null;
    $sd = json_decode($pub->raw, true);
    array_walk_recursive($sd, function ($v, $k) use (&$sceneId) { if ($k === 'id' && $sceneId === null) $sceneId = (int) $v; });
    ok('read the resulting path');

    // 19. Report
    $rep = new Client($base);
    $rd = $rep->req('POST', "/api/adventures/$slug/reports", ['target_type' => 'adventure', 'reason' => 'spam', 'details' => 'Release check report.', 'website' => '']);
    check(in_array($rep->status, [200, 201], true) && !empty($rd['report_id']), 'report created', $rep->raw);
    $reportId = (int) $rd['report_id'];
    $pub->req('GET', "/api/adventures/$slug");
    check($pub->status === 200, 'report does not auto-remove content');
    ok('report content');

    // 20. Resolve
    $rep->req('POST', "/api/adventures/$slug/moderation/reports/$reportId/act", ['action' => 'dismiss']);
    check(in_array($rep->status, [401, 403], true), 'anonymous cannot act on reports', $rep->raw);
    $a->req('POST', "/api/adventures/$slug/moderation/reports/$reportId/act", ['action' => 'dismiss', 'note' => 'Not spam.']);
    check($a->status === 200 && str_contains($a->raw, 'dismissed'), 'owner resolves report', $a->raw);
    ok('resolve the report');

    // 21. Invite
    $a->req('POST', "/api/adventures/$slug/collaborators/invitations", ['email' => 'contrib@example.test', 'role' => 'editor']);
    check(in_array($a->status, [200, 201], true), 'invite', $a->raw);
    processQueue($env);
    $inv = tokenFromMail('contrib@example.test', '#127\.0\.0\.1:8099/invitations/([A-Za-z0-9_\-]{20,})#');
    check($inv !== null, 'invitation email delivered', substr(implode("\n----\n", mails()), -1500));
    $b->req('POST', "/api/invitations/$inv/accept");
    check($b->status === 200, 'accept invitation', $b->raw);
    $b->req('POST', "/api/invitations/$inv/accept");
    check($b->status !== 200, 'invitation single-use', $b->raw);
    ok('invite a collaborator');

    // 22. Transfer ownership
    $roster = $a->req('GET', "/api/adventures/$slug/collaborators");
    $bId = null;
    array_walk_recursive($roster, function ($v, $k) use (&$bId) {});
    if (preg_match('#\{[^{}]*"username":"contrib"[^{}]*\}#', $a->raw, $mm)) { $row = json_decode($mm[0], true); $bId = (int) ($row['user_id'] ?? $row['id'] ?? 0); }
    check(!empty($bId), 'contributor on roster', $a->raw);
    $a->req('POST', '/api/auth/reauthenticate', ['password' => $passA]);
    check($a->status === 200, 'author reauth', $a->raw);
    $a->req('POST', "/api/adventures/$slug/collaborators/transfer", ['user_id' => $bId, 'confirm' => false]);
    check($a->status !== 200, 'transfer requires explicit confirmation', $a->raw);
    $a->req('POST', "/api/adventures/$slug/collaborators/transfer", ['user_id' => $bId, 'confirm' => true, 'stay_as_editor' => true]);
    check($a->status === 200, 'transfer', $a->raw);
    $b->req('GET', "/api/adventures/$slug/manage");
    check($b->status === 200 && str_contains($b->raw, '"owner"'), 'new owner manages', $b->raw);
    ok('transfer ownership');

    // 23. Export
    foreach (['json', 'text', 'print', 'play'] as $fmt) {
        $b->req('GET', "/api/adventures/$slug/moderation/export?format=$fmt");
        check($b->status === 200 && str_contains($b->raw, 'brass lantern'), "export $fmt", substr($b->raw, 0, 400));
        check(!preg_match('/password|session|smtp|ip_hash|\/tmp\//i', $b->raw), "export $fmt redacted");
    }
    $b->req('GET', "/api/adventures/$slug/moderation/export?format=play");
    check(!preg_match('#<script[^>]+src=|https?://(?!www\.w3\.org)#i', $b->raw), 'standalone export is offline', substr($b->raw, 0, 400));
    ok('export the adventure (json, text, print, play)');

    // 24. Backup
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/backup.php release', $env, $SITE);
    check($c === 0 && preg_match('#backup created: (\S+)#', $o, $bm), 'backup', $o);
    $backupName = $bm[1];
    ok("back up the site ($backupName)");

    // 25. Restore into a clean test location
    $R = "$WORK/restore";
    [$c, $o] = sh('mkdir -p ' . escapeshellarg("$R/storage/data") . ' ' . escapeshellarg("$R/storage/locks") . ' ' . escapeshellarg("$R/site")
        . ' && tar -C ' . escapeshellarg($SITE) . " --exclude='./private/backups/*' --exclude='./private/data/*' -cf - . | tar -C " . escapeshellarg("$R/site") . ' -xf -');
    check($c === 0, 'copy code to restore location', $o);
    sh('mkdir -p ' . escapeshellarg("$R/site/private/backups") . ' && cp ' . escapeshellarg("$SITE/private/backups/$backupName") . '* ' . escapeshellarg("$R/site/private/backups/"));
    $renv = ['APP_ENV' => 'production', 'APP_URL' => 'http://127.0.0.1:8098',
             'DATABASE_PATH' => "$R/storage/data/branching-paths.sqlite", 'WRITE_LOCK_PATH' => "$R/storage/locks/write.lock"];
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/restore.php ' . escapeshellarg($backupName), $renv, "$R/site");
    check($c === 2, 'restore refuses without --yes', $o);
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/restore.php ' . escapeshellarg($backupName) . ' --yes', $renv, "$R/site");
    check($c === 0 && is_file($renv['DATABASE_PATH']), 'restore', $o);
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/migrate.php && ' . escapeshellarg($PHP) . ' scripts/integrity-check.php', $renv, "$R/site");
    check($c === 0, 'restored database migrates and passes integrity', $o);
    ok('restore into a clean test location');

    // 26. Verify restored auth + content
    startServer("$R/site/public", 8098, $renv);
    $ra = new Client('http://127.0.0.1:8098');
    $ra->req('POST', '/api/auth/login', ['email' => 'author@example.test', 'password' => $passA]);
    check($ra->status === 200, 'restored author login', $ra->raw);
    $rb = new Client('http://127.0.0.1:8098');
    $rb->req('POST', '/api/auth/login', ['email' => 'contrib@example.test', 'password' => $passB]);
    check($rb->status === 200, 'restored new-owner login', $rb->raw);
    $rb->req('GET', "/api/adventures/$slug/manage");
    check($rb->status === 200 && str_contains($rb->raw, '"owner"'), 'restored ownership', $rb->raw);
    $old = new Client('http://127.0.0.1:8098'); $old->cookies = $a->cookies;
    $old->req('GET', '/api/auth/session');
    check(str_contains($old->raw, '"authenticated":true'), 'existing sessions survive restore (same database)', $old->raw);
    $rp = new Client('http://127.0.0.1:8098');
    $rp->req('GET', "/api/adventures/$slug/scenes/$next");
    check($rp->status === 200 && str_contains($rp->raw, 'brass lantern'), 'restored content readable', $rp->raw);
    $ra->req('POST', '/api/auth/login', ['email' => 'admin@example.test', 'password' => $adminPass]);
    check($ra->status === 200, 'restored admin login', $ra->raw);
    ok('verify restored authentication and content');

    // 27. Read-only mode
    $admin->req('POST', '/api/auth/reauthenticate', ['password' => $adminPass]);
    $admin->req('PUT', '/api/master/settings/maintenance', ['maintenance_mode' => true, 'maintenance_message' => 'Back soon.']);
    check($admin->status === 200, 'enable read-only', $admin->raw);
    $st = $pub->req('GET', '/api/status');
    check(!empty($st['read_only']) && ($st['notice'] ?? '') === 'Back soon.', 'status reports read-only + notice', $pub->raw);
    $pub->req('GET', "/api/adventures/$slug/scenes/$next");
    check($pub->status === 200, 'public reading continues in read-only mode');
    $b->req('POST', "/api/adventures/$slug/scenes/$opening/branch", $branch + ['choice_text' => 'Another way']);
    check($b->status === 503, 'writes blocked in read-only mode', [$b->status, $b->raw]);
    $adm2 = new Client($base);
    $adm2->req('POST', '/api/auth/login', ['email' => 'admin@example.test', 'password' => $adminPass]);
    check($adm2->status === 200, 'administrator can still sign in');
    $admin->req('PUT', '/api/master/settings/maintenance', ['maintenance_mode' => false, 'maintenance_message' => '']);
    check($admin->status === 200, 'disable read-only', $admin->raw);
    $b->req('POST', "/api/adventures/$slug/follow");
    check($b->status === 200, 'writes resume after read-only', [$b->status, $b->raw]);
    ok('enable and disable read-only mode');

    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/integrity-check.php', $env, $SITE);
    check($c === 0, 'final integrity check', $o);
    [$c, $o] = sh(escapeshellarg($PHP) . ' scripts/system-check.php', $env, $SITE);
    out("\nsystem-check:\n" . preg_replace('/^/m', '    ', $o));
    out("\nRELEASE E2E: PASS ($step steps)");
} catch (\Throwable $e) {
    $failed = true;
    out("\nRELEASE E2E: FAIL — " . $e->getMessage());
    foreach (glob("$WORK/site/private/logs/*.log") ?: [] as $l) out("--- $l\n" . substr((string) file_get_contents($l), -2000));
    foreach (glob("$WORK/server-*.log") ?: [] as $l) out("--- $l\n" . substr((string) file_get_contents($l), -2000));
} finally {
    foreach ($procs as $p) { @proc_terminate($p); }
    if (!$keep && !$failed) sh('rm -rf ' . escapeshellarg($WORK));
    elseif ($failed) out("kept $WORK for inspection");
}
exit($failed ? 1 : 0);
