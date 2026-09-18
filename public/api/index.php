<?php
/**
 * Public HTTP API entry point.
 *
 * Every request is dispatched from here so we can apply consistent
 * headers, error handling, and safety guarantees.
 *
 * v0.10.0 exposes these read-only endpoints in addition to /health:
 *
 *   GET /api/adventures                         — Discover list
 *   GET /api/adventures/{slug}                  — Adventure landing data
 *   GET /api/adventures/{slug}/outline          — Public story outline
 *   GET /api/adventures/{slug}/scenes/{sceneId} — Published scene + choices
 *
 * Draft and suspended adventures are never returned. Unlisted
 * adventures are excluded from the Discover list but remain readable
 * by slug. Hidden and draft scenes are never returned. Choices are
 * filtered to hide unpublished destinations.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\AccountService;
use App\AdventureService;
use App\AdminSession;
use App\AuthService;
use App\BranchSubmissionService;
use App\Csrf;
use App\Database;
use App\EmailQueueRepository;
use App\EmailQueueService;
use App\EmailTemplateRepository;
use App\Mailer\SmtpTransport;
use App\Migrator;
use App\ModerationService;
use App\NotificationService;
use App\PublicationService;
use App\PublicRepository;
use App\ReportService;
use App\RegistrationService;
use App\SettingsRepository;
use App\SessionRepository;
use App\SmtpSettingsRepository;
use App\WriteLock;


header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';
$route  = preg_replace('#^/api#', '', $path) ?: '/';
$route  = rtrim($route, '/');
if ($route === '') { $route = '/'; }

// ── Registration (POST) ────────────────────────────────────────────
if ($method === 'POST' && $route === '/register') {
    handle_register();
    exit;
}

// ── Adventure creation (v0.16.0) ───────────────────────────────────
if ($route === '/adventures/creation-settings' && $method === 'GET') {
    handle_creation_settings();
    exit;
}
if ($route === '/adventures' && $method === 'POST') {
    handle_adventure_create();
    exit;
}


// ── Publication workflow (v0.17.0) ─────────────────────────────────
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)/(manage|preview|status|draft)$#', $route, $pm)) {
    handle_publication($method, $pm[1], $pm[2]);
    exit;
}

// ── Moderation and owner controls (v0.19.0) ────────────────────────
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)/moderation(/.*)?$#', $route, $mm)) {
    handle_moderation($method, $mm[1], rtrim($mm[2] ?? '', '/'));
    exit;
}
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)/reports$#', $route, $rm) && $method === 'POST') {
    handle_report_create($rm[1]);
    exit;
}

// ── Reports and content warnings (v0.21.0) ─────────────────────────
if (preg_match('#^/reports/(\d+)/privacy$#', $route, $pv) && $method === 'POST') {
    handle_report_privacy((int) $pv[1]);
    exit;
}

// ── Collaborators and ownership transfer (v0.20.0) ─────────────────
if ($route === '/auth/reauthenticate' && $method === 'POST') {
    handle_reauthenticate();
    exit;
}
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)/collaborators(/.*)?$#', $route, $cm)) {
    handle_collaborators($method, $cm[1], rtrim($cm[2] ?? '', '/'));
    exit;
}
if (preg_match('#^/invitations(/.*)?$#', $route, $im)) {
    handle_invitations($method, rtrim($im[1] ?? '', '/'));
    exit;
}
if (preg_match('#^/notifications(/.*)?$#', $route, $nm)) {
    handle_notifications($method, rtrim($nm[1] ?? '', '/'));
    exit;
}

// ── Follows (v0.22.0) ──────────────────────────────────────────────
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)/follow$#', $route, $fm)) {
    handle_follow($method, $fm[1]);
    exit;
}


// ── Branch submissions (v0.18.0) ───────────────────────────────────
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)/scenes/([A-Za-z0-9\-]+)/branch$#', $route, $bm)) {
    handle_branch_submission($method, $bm[1], $bm[2]);
    exit;
}

// ── Authentication (login, logout, verify, reset, change) ──────────
if (strncmp($route, '/auth', 5) === 0) {
    handle_auth($method, substr($route, 5));
    exit;
}

// ── Account dashboard (profile, security, notifications, …) ────────
if (strncmp($route, '/account', 8) === 0) {
    handle_account($method, substr($route, 8));
    exit;
}


// ── Master (admin) routes ──────────────────────────────────────────
if (strncmp($route, '/master', 7) === 0) {
    handle_master($method, substr($route, 7));
    exit;
}

if ($method !== 'GET') {
    respond_error(405, 'method_not_allowed');
    exit;
}


// ── CSRF token issuance ────────────────────────────────────────────
if ($route === '/csrf-token') {
    $token = Csrf::issue();
    echo json_encode(['token' => $token]);
    exit;
}

// ── Public registration settings ───────────────────────────────────
if ($route === '/registration/settings') {
    try {
        $repo = new SettingsRepository(Database::open());
        $s = $repo->registrationSettings();
        echo json_encode([
            'registration_enabled'    => $s['registration_enabled'],
            'minimum_password_length' => $s['minimum_password_length'],
            'requires_email_verification' => $s['require_email_verification'],
            'requires_admin_approval'     => $s['require_admin_approval'],
        ]);
    } catch (\Throwable $e) {
        error_log('[bp] api error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
    }
    exit;
}

// ── Health ─────────────────────────────────────────────────────────
if ($route === '/health') {
    respond_health();
    exit;
}


// ── Discover list ──────────────────────────────────────────────────
if ($route === '/adventures') {
    with_repo(static function (PublicRepository $repo): void {
        $filters = [];
        foreach (['q','genre','rating','status','contributions','sort'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $filters[$key] = $_GET[$key];
            }
        }
        echo json_encode(['adventures' => $repo->discover($filters)]);
    });
    exit;
}

// ── /adventures/{slug}, /adventures/{slug}/outline,
//    /adventures/{slug}/scenes/{sceneSlug} ─────────────────────────
if (preg_match('#^/adventures/([A-Za-z0-9\-]+)(/.*)?$#', $route, $m)) {
    $slug = $m[1];
    $tail = $m[2] ?? '';

    with_repo(static function (PublicRepository $repo) use ($slug, $tail): void {
        if ($tail === '' || $tail === '/') {
            $adv = $repo->adventureBySlug($slug);
            if ($adv === null) { respond_error(404, 'not_found'); return; }
            echo json_encode(['adventure' => $adv]);
            return;
        }
        if ($tail === '/outline') {
            $out = $repo->outline($slug);
            if ($out === null) { respond_error(404, 'not_found'); return; }
            echo json_encode($out);
            return;
        }
        if (preg_match('#^/scenes/([A-Za-z0-9\-]+)$#', $tail, $sm)) {
            $scene = $repo->scene($slug, $sm[1]);
            if ($scene === null) { respond_error(404, 'not_found'); return; }
            echo json_encode(['scene' => $scene]);
            return;
        }
        respond_error(404, 'not_found');
    });
    exit;
}

respond_error(404, 'not_found');
exit;

// ────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────

function respond_error(int $status, string $code): void
{
    http_response_code($status);
    echo json_encode(['error' => $code]);
}

/** Open the DB and pass a PublicRepository to $fn, mapping any error to 503. */
function with_repo(callable $fn): void
{
    try {
        $pdo = Database::open();
        $repo = new PublicRepository($pdo);
        $fn($repo);
    } catch (\Throwable $e) {
        error_log('[bp] api error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
    }
}

function respond_health(): void
{
    $config = bp_config();
    $dbStatus = 'unknown';
    $schemaVersion = '0';
    try {
        $pdo = Database::open();
        $pdo->query('SELECT 1');
        $dbStatus = 'ok';
        try {
            $migrator = new Migrator($pdo);
            $schemaVersion = $migrator->currentVersion();
        } catch (\Throwable $_) {
            $schemaVersion = '0';
        }
    } catch (\Throwable $e) {
        error_log('[bp] health db error: ' . $e->getMessage());
        $dbStatus = 'unavailable';
    }
    echo json_encode([
        'api'            => 'ok',
        'database'       => $dbStatus,
        'schema_version' => $schemaVersion,
        'app_version'    => (string) $config['app']['version'],
    ]);
}

/**
 * Handle POST /api/register.
 *
 * The full transaction runs while holding the file-based write lock
 * so a duplicate email or username can never sneak in via a race.
 * The response body is intentionally opaque — see RegistrationService
 * for the enumeration-safety rationale.
 */
function handle_register(): void
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    // Support form-encoded bodies too so the honeypot still works
    // even if a scraper strips the Content-Type header.
    if ($payload === [] && !empty($_POST)) {
        $payload = $_POST;
    }

    $csrfOk = Csrf::validate();
    $ip     = client_ip();

    try {
        $pdo  = Database::open();
        $lock = new WriteLock();
        $result = $lock->withLock(static function () use ($pdo, $payload, $ip, $csrfOk): array {
            $svc = new RegistrationService($pdo);
            // Wire the verification / welcome email into a successful
            // insert. The RegistrationService always returns the
            // same opaque outcome regardless of whether the hook ran.
            $svc->setOnRegistered(static function (int $uid, string $email, string $name) use ($pdo): void {
                (new AuthService($pdo))->onRegistered($uid, $email, $name);
            });
            return $svc->handle($payload, $ip, $csrfOk);
        });
    } catch (\Throwable $e) {
        error_log('[bp] register error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    [$outcome, $errors] = $result;
    switch ($outcome) {
        case RegistrationService::OUTCOME_ACCEPTED:
            http_response_code(202);
            echo json_encode(['status' => 'accepted']);
            return;
        case RegistrationService::OUTCOME_DISABLED:
            http_response_code(403);
            echo json_encode(['error' => 'registration_disabled']);
            return;
        case RegistrationService::OUTCOME_RATE_LIMITED:
            http_response_code(429);
            echo json_encode(['error' => 'rate_limited']);
            return;
        case RegistrationService::OUTCOME_CSRF_FAILED:
            http_response_code(403);
            echo json_encode(['error' => 'csrf_failed']);
            return;
        case RegistrationService::OUTCOME_INVALID:
        default:
            http_response_code(422);
            echo json_encode(['error' => 'invalid', 'fields' => $errors]);
            return;
    }
}

/**
 * Best-effort client IP. We deliberately do NOT trust
 * X-Forwarded-For unless the operator has configured the site behind
 * a reverse proxy; for now the remote address wins.
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!is_string($ip) || $ip === '') {
        $ip = '0.0.0.0';
    }
    return $ip;
}

/**
 * Handle every `/api/master/*` request.
 *
 * All routes except POST /master/login and GET /master/session require
 * an authenticated administrator session (see AdminSession). Mutating
 * routes additionally require a valid CSRF token, and the SMTP save +
 * test-email path runs inside the write lock so a concurrent worker
 * never reads a half-written configuration.
 */
function handle_master(string $method, string $tail): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] master db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    $session = new AdminSession();

    // ── Login ───────────────────────────────────────────────────
    if ($method === 'POST' && $tail === '/login') {
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
        $body = read_json_body();
        $email = (string) ($body['email'] ?? '');
        $pass  = (string) ($body['password'] ?? '');
        $uid = $session->login($pdo, $email, $pass);
        if ($uid === null) { respond_error(401, 'invalid_credentials'); return; }
        echo json_encode(['status' => 'ok', 'user_id' => $uid]);
        return;
    }
    if ($method === 'POST' && $tail === '/logout') {
        $session->clear();
        echo json_encode(['status' => 'ok']);
        return;
    }
    if ($method === 'GET' && $tail === '/session') {
        $uid = $session->authenticate($pdo);
        echo json_encode(['authenticated' => $uid !== null, 'user_id' => $uid]);
        return;
    }

    // Every other master route needs a live admin session.
    $uid = $session->authenticate($pdo);
    if ($uid === null) { respond_error(401, 'unauthenticated'); return; }

    // ── GET /master/settings/email ─────────────────────────────
    if ($method === 'GET' && $tail === '/settings/email') {
        $repo = new SmtpSettingsRepository($pdo);
        echo json_encode(['settings' => $repo->loadForApi()]);
        return;
    }
    // ── PUT /master/settings/email ─────────────────────────────
    if ($method === 'PUT' && $tail === '/settings/email') {
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
        $body = read_json_body();
        try {
            $result = (new WriteLock())->withLock(static function () use ($pdo, $body): array {
                return (new SmtpSettingsRepository($pdo))->save($body);
            });
        } catch (\Throwable $e) {
            error_log('[bp] smtp save error: ' . $e->getMessage());
            respond_error(503, 'service_unavailable');
            return;
        }
        [$ok, $errors] = $result;
        if (!$ok) { http_response_code(422); echo json_encode(['error'=>'invalid','fields'=>$errors]); return; }
        echo json_encode(['status' => 'saved']);
        return;
    }
    // ── POST /master/settings/email/test ───────────────────────
    if ($method === 'POST' && $tail === '/settings/email/test') {
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
        $body = read_json_body();
        $to = (string) ($body['to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { respond_error(422, 'invalid_recipient'); return; }
        try {
            $smtp = (new SmtpSettingsRepository($pdo))->load();
            (new EmailQueueRepository($pdo))->enqueue(
                'operator_test', $to, '', ['recipient' => $to]
            );
        } catch (\Throwable $e) {
            error_log('[bp] test email error: ' . $e->getMessage());
            respond_error(503, 'service_unavailable');
            return;
        }
        echo json_encode(['status' => 'queued']);
        return;
    }
    // ── GET /master/email-queue ────────────────────────────────
    if ($method === 'GET' && $tail === '/email-queue') {
        $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : null;
        $repo = new EmailQueueRepository($pdo);
        echo json_encode([
            'counts'   => $repo->counts(),
            'messages' => $repo->recent(100, $status),
        ]);
        return;
    }
    // ── POST /master/email-queue/{id}/cancel ───────────────────
    if ($method === 'POST' && preg_match('#^/email-queue/(\d+)/cancel$#', $tail, $m)) {
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
        $ok = (new EmailQueueRepository($pdo))->cancel((int) $m[1]);
        echo json_encode(['status' => $ok ? 'cancelled' : 'noop']);
        return;
    }

    respond_error(404, 'not_found');
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') { return is_array($_POST) ? $_POST : []; }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Handle every `/api/auth/*` request.
 *
 * All mutating routes require a matching CSRF token; all writes run
 * under the write lock. Password recovery and resend-verification
 * always respond identically ("opaque") regardless of whether the
 * account exists.
 *
 * Session cookies (`bp_session`) are HttpOnly, SameSite=Lax, Secure
 * on HTTPS, and carry a bounded lifetime that matches the row in
 * `sessions.expires_at`.
 */
function handle_auth(string $method, string $tail): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] auth db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    $auth = new AuthService($pdo);

    // ── GET /auth/session ──────────────────────────────────────────
    if ($method === 'GET' && $tail === '/session') {
        $uid = $auth->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
        echo json_encode([
            'authenticated' => $uid !== null,
            'user_id'       => $uid,
        ]);
        return;
    }

    // Everything below is a mutating request.
    if ($method !== 'POST') { respond_error(405, 'method_not_allowed'); return; }
    if (!Csrf::validate())  { respond_error(403, 'csrf_failed'); return; }
    $body = read_json_body();

    try {
        $lock = new WriteLock();
        switch ($tail) {
            case '/login': {
                $email = (string) ($body['email'] ?? '');
                $pass  = (string) ($body['password'] ?? '');
                $redirect = isset($body['redirect']) ? (string) $body['redirect'] : null;
                $result = $lock->withLock(static fn() => $auth->login($email, $pass));
                [$outcome, $cookie] = $result;
                if ($outcome === AuthService::OK && is_string($cookie)) {
                    auth_set_cookie($cookie);
                    $uid = $auth->authenticate($cookie);
                    if ($uid !== null) {
                        notify_security_event(
                            $pdo, $uid, 'New sign-in to your account',
                            'If this was not you, change your password and sign out other sessions.'
                        );
                    }
                    echo json_encode([
                        'status'   => 'ok',
                        'redirect' => AuthService::isSafeRedirect($redirect) ? $redirect : '/',
                    ]);
                    return;
                }
                http_response_code(401);
                echo json_encode(['error' => $outcome]);
                return;
            }
            case '/logout': {
                $cookie = $_COOKIE[AuthService::SESSION_COOKIE] ?? null;
                $lock->withLock(static function () use ($auth, $cookie): void {
                    $auth->logout($cookie);
                });
                auth_clear_cookie();
                echo json_encode(['status' => 'ok']);
                return;
            }
            case '/verify-email': {
                $token = (string) ($body['token'] ?? '');
                $outcome = $lock->withLock(static fn() => $auth->verifyEmail($token));
                if ($outcome === AuthService::OK) {
                    echo json_encode(['status' => 'ok']);
                    return;
                }
                http_response_code(400);
                echo json_encode(['error' => 'token_invalid']);
                return;
            }
            case '/resend-verification': {
                $email = (string) ($body['email'] ?? '');
                $lock->withLock(static function () use ($auth, $email): void {
                    $auth->resendVerification($email);
                });
                echo json_encode(['status' => 'ok']);
                return;
            }
            case '/forgot-password': {
                $email = (string) ($body['email'] ?? '');
                $lock->withLock(static function () use ($auth, $email): void {
                    $auth->forgotPassword($email);
                });
                echo json_encode(['status' => 'ok']);
                return;
            }
            case '/reset-password': {
                $token = (string) ($body['token'] ?? '');
                $pass  = (string) ($body['password'] ?? '');
                $conf  = (string) ($body['password_confirmation'] ?? '');
                $result = $lock->withLock(static fn() => $auth->resetPassword($token, $pass, $conf));
                [$outcome, $errors] = $result;
                if ($outcome === AuthService::OK) {
                    // Revoke any pre-reset cookie the caller happened to
                    // hold so the browser cannot present a stale one.
                    auth_clear_cookie();
                    echo json_encode(['status' => 'ok']);
                    return;
                }
                if ($outcome === AuthService::INVALID) {
                    http_response_code(422);
                    echo json_encode(['error' => 'invalid', 'fields' => $errors]);
                    return;
                }
                http_response_code(400);
                echo json_encode(['error' => 'token_invalid']);
                return;
            }
            case '/change-password': {
                $uid = $auth->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
                if ($uid === null) { respond_error(401, 'unauthenticated'); return; }
                $current = (string) ($body['current_password'] ?? '');
                $next    = (string) ($body['new_password'] ?? '');
                $conf    = (string) ($body['new_password_confirmation'] ?? '');
                $result = $lock->withLock(static fn() => $auth->changePassword($uid, $current, $next, $conf));
                [$outcome, $errors, $newCookie] = $result;
                if ($outcome === AuthService::OK && is_string($newCookie)) {
                    auth_set_cookie($newCookie);
                    echo json_encode(['status' => 'ok']);
                    return;
                }
                http_response_code(422);
                echo json_encode(['error' => 'invalid', 'fields' => $errors]);
                return;
            }
        }
    } catch (\Throwable $e) {
        error_log('[bp] auth error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    respond_error(404, 'not_found');
}

/** Emit the session cookie for the current response. */
function auth_set_cookie(string $value): void
{
    $secure = (($_SERVER['HTTPS'] ?? 'off') !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(AuthService::SESSION_COOKIE, $value, [
        'expires'  => time() + SessionRepository::TTL_SECONDS,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[AuthService::SESSION_COOKIE] = $value;
}

function auth_clear_cookie(): void
{
    setcookie(AuthService::SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[AuthService::SESSION_COOKIE]);
}


/**
 * Handle every `/api/account/*` request.
 *
 * Every route requires an authenticated session cookie. Mutating
 * routes additionally require a valid CSRF token, and writes execute
 * under the WriteLock so a race can never leave user rows in an
 * inconsistent shape.
 */
function handle_account(string $method, string $tail): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] account db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    $auth   = new AuthService($pdo);
    $cookie = $_COOKIE[AuthService::SESSION_COOKIE] ?? null;
    $userId = $auth->authenticate($cookie);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $sessionId = AccountService::sessionIdFromCookie($cookie);

    $svc = new AccountService($pdo);

    if ($method === 'GET' && ($tail === '' || $tail === '/' || $tail === '/profile')) {
        echo json_encode(['profile' => $svc->profile($userId)]);
        return;
    }
    if ($method === 'GET' && $tail === '/security') {
        echo json_encode([
            'profile'  => $svc->profile($userId),
            'sessions' => $svc->listSessions($userId, $sessionId),
        ]);
        return;
    }
    if ($method === 'GET' && $tail === '/adventures') {
        echo json_encode(['adventures' => $svc->myAdventures($userId)]);
        return;
    }
    if ($method === 'GET' && $tail === '/contributions') {
        echo json_encode([
            'contributions' => (new BranchSubmissionService($pdo))->historyForUser($userId),
        ]);
        return;
    }
    if ($method === 'GET' && $tail === '/bookmarks') {
        echo json_encode(['bookmarks' => $svc->listBookmarks($userId)]);
        return;
    }

    // ── All mutating routes below ──
    if ($method !== 'PUT' && $method !== 'POST') { respond_error(405, 'method_not_allowed'); return; }
    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
    $body = read_json_body();

    // ── Contributor actions on their own submissions (v0.19.0) ──
    // The submission is matched on id AND user id, so one contributor
    // can never edit or withdraw another's branch.
    if (preg_match('#^/contributions/(\d+)$#', $tail, $cm) && $method === 'PUT') {
        $mod = new ModerationService($pdo);
        $subId = (int) $cm[1];
        $resubmit = !array_key_exists('resubmit', $body) || !empty($body['resubmit']);
        [$outcome, $data] = (new WriteLock())->withLock(
            static fn () => $mod->contributorUpdate($subId, $userId, $body, $resubmit)
        );
        if ($outcome === ModerationService::OK && $resubmit) {
            notify_resubmitted($pdo, $subId, $userId);
        }
        moderation_respond($outcome, $data);
        return;
    }
    if (preg_match('#^/contributions/(\d+)/withdraw$#', $tail, $wm) && $method === 'POST') {
        $mod = new ModerationService($pdo);
        $subId = (int) $wm[1];
        [$outcome, $data] = (new WriteLock())->withLock(
            static fn () => $mod->withdraw($subId, $userId)
        );
        moderation_respond($outcome, $data);
        return;
    }

    try {
        $lock = new WriteLock();
        if ($method === 'PUT' && $tail === '/profile') {
            $r = $lock->withLock(static fn () => $svc->updateProfile($userId, $body));
            [$outcome, $errors] = $r;
            if ($outcome === AccountService::OK) { echo json_encode(['status' => 'ok']); return; }
            http_response_code(422);
            echo json_encode(['error' => 'invalid', 'fields' => $errors]);
            return;
        }
        if ($method === 'PUT' && $tail === '/notifications') {
            $lock->withLock(static function () use ($svc, $userId, $body) { $svc->updateNotifications($userId, $body); });
            echo json_encode(['status' => 'ok']);
            return;
        }
        if ($method === 'POST' && $tail === '/email-change') {
            $newEmail = (string) ($body['email'] ?? '');
            $r = $lock->withLock(static fn () => $svc->requestEmailChange($userId, $newEmail));
            [$outcome, $errors] = $r;
            if ($outcome === AccountService::OK) { echo json_encode(['status' => 'ok']); return; }
            $code = $outcome === AccountService::EMAIL_IN_USE ? 409 : 422;
            http_response_code($code);
            echo json_encode(['error' => $outcome, 'fields' => $errors]);
            return;
        }
        if ($method === 'POST' && $tail === '/email-change/confirm') {
            $token = (string) ($body['token'] ?? '');
            $r = $lock->withLock(static fn () => $svc->confirmEmailChange($userId, $token));
            [$outcome, $newEmail] = $r;
            if ($outcome === AccountService::OK) { echo json_encode(['status' => 'ok', 'email' => $newEmail]); return; }
            $code = $outcome === AccountService::EMAIL_IN_USE ? 409 : 400;
            http_response_code($code);
            echo json_encode(['error' => $outcome]);
            return;
        }
        if ($method === 'POST' && $tail === '/sessions/revoke-others') {
            $revoked = $lock->withLock(static fn () => $svc->revokeOtherSessions($userId, $sessionId));
            echo json_encode(['status' => 'ok', 'revoked' => $revoked]);
            return;
        }
        if ($method === 'POST' && $tail === '/bookmarks/import') {
            $entries = isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : [];
            $counts = $lock->withLock(static fn () => $svc->importLocalProgress($userId, $entries));
            echo json_encode(['status' => 'ok'] + $counts);
            return;
        }
    } catch (\Throwable $e) {
        error_log('[bp] account error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    respond_error(404, 'not_found');
}




/**
 * GET /api/adventures/creation-settings (v0.16.0).
 *
 * Requires a live session. Returns the templates, the enumerated
 * option lists the wizard renders, and the caller's remaining budget
 * so the UI can disable creation before a doomed submit.
 */
function handle_creation_settings(): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] creation settings db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }

    $svc = new AdventureService($pdo);
    $limits = $svc->creationLimits($userId);
    echo json_encode([
        'templates'          => AdventureService::templates(),
        'genres'             => AdventureService::GENRES,
        'content_ratings'    => AdventureService::RATINGS,
        'visibilities'       => AdventureService::VISIBILITIES,
        'contribution_modes' => AdventureService::CONTRIBUTION_MODES,
        'statuses'           => AdventureService::STATUSES,
        'max_branches_min'   => AdventureService::MAX_BRANCHES_MIN,
        'max_branches_max'   => AdventureService::MAX_BRANCHES_MAX,
        'limits'             => $limits,
        'can_create'         => $limits['owned'] < $limits['max_adventures_per_user']
                                && $limits['recent'] < $limits['adventures_per_user_per_hour'],
    ]);
}

/**
 * POST /api/adventures (v0.16.0).
 *
 * Session-authenticated and CSRF-protected. The owner is always the
 * authenticated caller — any author id in the body is ignored. The
 * whole write runs under the file write lock so the adventure + its
 * opening scene commit as one serialized transaction.
 */
function handle_adventure_create(): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] adventure create db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

    $body = read_json_body();
    $ip   = client_ip();

    try {
        $lock = new WriteLock();
        $result = $lock->withLock(static function () use ($pdo, $userId, $body, $ip): array {
            return (new AdventureService($pdo))->create($userId, $body, $ip);
        });
    } catch (\Throwable $e) {
        error_log('[bp] adventure create error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    [$outcome, $errors, $adventure] = $result;
    switch ($outcome) {
        case AdventureService::OK:
            http_response_code(201);
            echo json_encode(['status' => 'ok', 'adventure' => $adventure]);
            return;
        case AdventureService::FORBIDDEN:
            respond_error(403, 'not_active');
            return;
        case AdventureService::RATE_LIMITED:
            respond_error(429, 'rate_limited');
            return;
        case AdventureService::LIMIT_REACHED:
            respond_error(409, 'limit_reached');
            return;
        default:
            http_response_code(422);
            echo json_encode(['error' => 'invalid', 'fields' => $errors]);
            return;
    }
}


/**
 * Publication workflow endpoints (v0.17.0).
 *
 *   GET  /api/adventures/{slug}/manage   — state, role, actions, activity
 *   GET  /api/adventures/{slug}/preview  — every scene, drafts included
 *   PUT  /api/adventures/{slug}/draft    — save draft edits
 *   POST /api/adventures/{slug}/status   — publish / unpublish / …
 *
 * Authorisation is always derived server-side from the authenticated
 * session: author, collaborator roster, or administrator role. A role
 * or owner id in the request body is ignored. Preview responses carry
 * X-Robots-Tag: noindex so a leaked link is never indexed.
 */
function handle_publication(string $method, string $slug, string $tail): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] publication db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    $userId  = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    $isAdmin = $userId !== null && AdminSession::hasRole($pdo, $userId, 'admin');
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }

    $svc = new PublicationService($pdo);

    try {
        if ($method === 'GET' && $tail === 'manage') {
            [$outcome, $data] = $svc->managePayload($slug, $userId, $isAdmin);
            publication_respond($outcome, $data);
            return;
        }
        if ($method === 'GET' && $tail === 'preview') {
            header('X-Robots-Tag: noindex, nofollow');
            [$outcome, $data] = $svc->previewPayload($slug, $userId, $isAdmin);
            publication_respond($outcome, $data);
            return;
        }
        if ($method === 'PUT' && $tail === 'draft') {
            if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
            $adv = $svc->adventureBySlug($slug);
            if ($adv === null) { respond_error(404, 'not_found'); return; }
            $role = $svc->roleFor((int) $adv['id'], $userId, $isAdmin);
            $body = read_json_body();
            $lock = new WriteLock();
            [$outcome, $fields] = $lock->withLock(static function () use ($svc, $adv, $role, $body): array {
                return $svc->saveDraft((int) $adv['id'], $role, $body);
            });
            if ($outcome === PublicationService::OK) { echo json_encode(['status' => 'ok']); return; }
            if ($outcome === PublicationService::INVALID) {
                http_response_code(422);
                echo json_encode(['error' => 'invalid', 'fields' => $fields]);
                return;
            }
            publication_respond($outcome, null);
            return;
        }
        if ($method === 'POST' && $tail === 'status') {
            if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
            $adv = $svc->adventureBySlug($slug);
            if ($adv === null) { respond_error(404, 'not_found'); return; }
            $role   = $svc->roleFor((int) $adv['id'], $userId, $isAdmin);
            $body   = read_json_body();
            $action = isset($body['action']) ? (string) $body['action'] : '';
            $lock   = new WriteLock();
            [$outcome, $data] = $lock->withLock(static function () use ($svc, $adv, $userId, $role, $action): array {
                return $svc->changeStatus((int) $adv['id'], $userId, $role, $action);
            });
            if ($outcome === PublicationService::OK) {
                echo json_encode(['status' => 'ok'] + ($data ?? []));
                return;
            }
            publication_respond($outcome, null);
            return;
        }
    } catch (\Throwable $e) {
        error_log('[bp] publication error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    respond_error(405, 'method_not_allowed');
}

/** Map a PublicationService outcome to an HTTP response. */
function publication_respond(string $outcome, ?array $data): void
{
    switch ($outcome) {
        case PublicationService::OK:
            echo json_encode($data ?? []);
            return;
        case PublicationService::NOT_FOUND:
            respond_error(404, 'not_found');
            return;
        case PublicationService::FORBIDDEN:
            respond_error(403, 'forbidden');
            return;
        case PublicationService::READ_ONLY:
            respond_error(409, 'read_only');
            return;
        case PublicationService::NO_OPENING:
            respond_error(422, 'no_opening_scene');
            return;
        default:
            http_response_code(422);
            echo json_encode(['error' => 'invalid']);
            return;
    }
}


/**
 * Branch submissions (v0.18.0).
 *
 *   GET  /api/adventures/{slug}/scenes/{scene}/branch  — form context
 *   POST /api/adventures/{slug}/scenes/{scene}/branch  — submit a branch
 *
 * A session is optional: adventures that allow anonymous contributions
 * accept a signed-out visitor. Whoever the caller is, the contributor
 * identity comes from the session cookie and the request IP — never
 * from the request body. Writes require CSRF and run under the write
 * lock so two concurrent submissions cannot exceed the branch limit.
 */
function handle_branch_submission(string $method, string $slug, string $sceneRef): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] branch db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    $ip     = client_ip();
    $svc    = new BranchSubmissionService($pdo);

    try {
        if ($method === 'GET') {
            [$outcome, $data] = $svc->context($slug, $sceneRef, $userId, $ip);
            if ($outcome === BranchSubmissionService::OK) { echo json_encode($data); return; }
            branch_respond($outcome, []);
            return;
        }
        if ($method !== 'POST') { respond_error(405, 'method_not_allowed'); return; }
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

        $body = read_json_body();
        $lock = new WriteLock();
        [$outcome, $errors, $data] = $lock->withLock(
            static function () use ($svc, $slug, $sceneRef, $body, $userId, $ip): array {
                return $svc->submit($slug, $sceneRef, $body, $userId, $ip);
            }
        );
        if ($outcome === BranchSubmissionService::OK) {
            notify_branch_submitted($pdo, $slug, $data ?? [], $userId);
            http_response_code(201);
            echo json_encode(['status' => 'ok'] + ($data ?? []));
            return;
        }
        branch_respond($outcome, $errors);
    } catch (\Throwable $e) {
        error_log('[bp] branch submission error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
    }
}

/** Map a BranchSubmissionService outcome to an HTTP response. */
function branch_respond(string $outcome, array $errors): void
{
    $map = [
        BranchSubmissionService::NOT_FOUND          => 404,
        BranchSubmissionService::UNAVAILABLE        => 409,
        BranchSubmissionService::CLOSED             => 409,
        BranchSubmissionService::SOURCE_UNAVAILABLE => 409,
        BranchSubmissionService::SCENE_LOCKED       => 409,
        BranchSubmissionService::BRANCH_LIMIT       => 409,
        BranchSubmissionService::DUPLICATE          => 409,
        BranchSubmissionService::BLOCKED            => 403,
        BranchSubmissionService::UNAUTHENTICATED    => 401,
        BranchSubmissionService::PASSCODE_REQUIRED  => 422,
        BranchSubmissionService::PASSCODE_INVALID   => 422,
        BranchSubmissionService::RATE_LIMITED       => 429,
        BranchSubmissionService::INVALID            => 422,
    ];
    // A tripped honeypot is answered exactly like a successful
    // submission so a bot learns nothing, but nothing is stored.
    if ($outcome === BranchSubmissionService::HONEYPOT) {
        http_response_code(201);
        echo json_encode(['status' => 'ok', 'state' => 'pending', 'published' => false]);
        return;
    }
    $status = $map[$outcome] ?? 422;
    http_response_code($status);
    $payload = ['error' => $outcome];
    if ($errors !== []) { $payload['fields'] = $errors; }
    echo json_encode($payload);
}


/**
 * Moderation and owner controls (v0.19.0).
 *
 *   GET  /moderation                              overview + counts
 *   GET  /moderation/submissions?state=pending    one queue tab
 *   POST /moderation/submissions/{id}/decision    approve / reject / …
 *   POST /moderation/submissions/{id}/review      reviewer note
 *   GET  /moderation/story                        scenes and choices
 *   PUT  /moderation/scenes/{id}                  edit scene + choices
 *   POST /moderation/scenes/{id}/action           lock/unlock/hide/restore
 *   POST /moderation/scenes/{id}/branch           owner-created branch
 *   PUT  /moderation/details                      details + guidelines
 *   PUT  /moderation/settings                     contribution settings
 *   GET  /moderation/permissions                  standings + roster
 *   POST /moderation/permissions                  trusted/approval/blocked
 *   POST /moderation/collaborators                grant or remove a role
 *   GET  /moderation/reports?state=open           report queue
 *   POST /moderation/reports/{id}/resolve         resolve or dismiss
 *
 * Every route requires a live session; the role is derived
 * server-side. Writes are CSRF-protected and serialized by the write
 * lock, so concurrent decisions cannot both publish a branch.
 */
function handle_moderation(string $method, string $slug, string $tail): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] moderation db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $isAdmin = AdminSession::hasRole($pdo, $userId, 'admin');
    $svc = new ModerationService($pdo);

    try {
        if ($method === 'GET') {
            if ($tail === '' ) {
                [$o, $d] = $svc->overview($slug, $userId, $isAdmin);
                moderation_respond($o, $d);
                return;
            }
            if ($tail === '/submissions') {
                $state = isset($_GET['state']) ? (string) $_GET['state'] : 'pending';
                [$o, $d] = $svc->submissions($slug, $userId, $isAdmin, $state);
                moderation_respond($o, $d);
                return;
            }
            if ($tail === '/story') {
                [$o, $d] = $svc->story($slug, $userId, $isAdmin);
                moderation_respond($o, $d);
                return;
            }
            if ($tail === '/permissions') {
                [$o, $d] = $svc->permissions($slug, $userId, $isAdmin);
                moderation_respond($o, $d);
                return;
            }
            if ($tail === '/reports') {
                $state = isset($_GET['state']) ? (string) $_GET['state'] : 'open';
                [$o, $d] = (new ReportService($pdo))->queue($slug, $userId, $isAdmin, $state);
                report_respond($o, $d);
                return;
            }
            if ($tail === '/warnings') {
                $adv = $svc->adventureBySlug($slug);
                if ($adv === null) { respond_error(404, 'not_found'); return; }
                $role = $svc->roleFor((int) $adv['id'], $userId, $isAdmin);
                if (!$svc->canView($role)) { respond_error(403, 'forbidden'); return; }
                echo json_encode([
                    'status'   => 'ok',
                    'codes'    => ReportService::WARNING_CODES,
                    'labels'   => ReportService::WARNING_LABELS,
                    'warnings' => (new ReportService($pdo))->warnings((int) $adv['id']),
                    'can_edit' => $svc->canDecide($role),
                ]);
                return;
            }
            respond_error(404, 'not_found');
            return;
        }

        if ($method !== 'POST' && $method !== 'PUT') { respond_error(405, 'method_not_allowed'); return; }
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

        $body = read_json_body();
        $lock = new WriteLock();

        if ($method === 'POST' && preg_match('#^/submissions/(\d+)/decision$#', $tail, $m)) {
            $id     = (int) $m[1];
            $action = (string) ($body['action'] ?? '');
            [$o, $d] = $lock->withLock(
                static fn () => $svc->decide($slug, $id, $userId, $isAdmin, $action, $body)
            );
            if ($o === ModerationService::OK && is_array($d)) {
                notify_submission_decided($pdo, $slug, $d, $userId);
            }
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'POST' && preg_match('#^/submissions/(\d+)/review$#', $tail, $m)) {
            $id  = (int) $m[1];
            $rec = isset($body['recommendation']) && $body['recommendation'] !== ''
                 ? (string) $body['recommendation'] : null;
            [$o, $d] = $lock->withLock(
                static fn () => $svc->addReview($slug, $id, $userId, $isAdmin, (string) ($body['note'] ?? ''), $rec)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'PUT' && preg_match('#^/scenes/(\d+)$#', $tail, $m)) {
            $id = (int) $m[1];
            [$o, $d] = $lock->withLock(
                static fn () => $svc->updateScene($slug, $id, $userId, $isAdmin, $body)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'POST' && preg_match('#^/scenes/(\d+)/action$#', $tail, $m)) {
            $id = (int) $m[1];
            $action = (string) ($body['action'] ?? '');
            [$o, $d] = $lock->withLock(
                static fn () => $svc->sceneAction($slug, $id, $userId, $isAdmin, $action)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'POST' && preg_match('#^/scenes/(\d+)/branch$#', $tail, $m)) {
            $id = (int) $m[1];
            [$o, $d] = $lock->withLock(
                static fn () => $svc->createOwnerBranch($slug, $id, $userId, $isAdmin, $body)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'PUT' && $tail === '/details') {
            [$o, $d] = $lock->withLock(
                static fn () => $svc->updateDetails($slug, $userId, $isAdmin, $body)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'PUT' && $tail === '/settings') {
            [$o, $d] = $lock->withLock(
                static fn () => $svc->updateSettings($slug, $userId, $isAdmin, $body)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'POST' && $tail === '/permissions') {
            $target = (int) ($body['user_id'] ?? 0);
            $level  = isset($body['level']) && $body['level'] !== '' ? (string) $body['level'] : null;
            [$o, $d] = $lock->withLock(
                static fn () => $svc->setPermission(
                    $slug, $userId, $isAdmin, $target, $level, (string) ($body['note'] ?? '')
                )
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'POST' && $tail === '/collaborators') {
            $target = (int) ($body['user_id'] ?? 0);
            $role   = isset($body['role']) && $body['role'] !== '' ? (string) $body['role'] : null;
            [$o, $d] = $lock->withLock(
                static fn () => $svc->setCollaborator($slug, $userId, $isAdmin, $target, $role)
            );
            moderation_respond($o, $d);
            return;
        }
        if ($method === 'POST' && preg_match('#^/reports/(\d+)/act$#', $tail, $m)) {
            $id     = (int) $m[1];
            $action = (string) ($body['action'] ?? '');
            $note   = (string) ($body['note'] ?? '');
            $reports = new ReportService($pdo);
            [$o, $d] = $lock->withLock(
                static fn () => $reports->act($slug, $id, $userId, $isAdmin, $action, $note)
            );
            report_respond($o, $d);
            return;
        }
        if ($method === 'PUT' && $tail === '/warnings') {
            $items = isset($body['warnings']) && is_array($body['warnings']) ? $body['warnings'] : [];
            $reports = new ReportService($pdo);
            [$o, $d] = $lock->withLock(
                static fn () => $reports->setWarnings($slug, $userId, $isAdmin, $items)
            );
            report_respond($o, $d);
            return;
        }
        if ($method === 'POST' && preg_match('#^/reports/(\d+)/resolve$#', $tail, $m)) {
            $id = (int) $m[1];
            [$o, $d] = $lock->withLock(
                static fn () => $svc->resolveReport(
                    $slug, $id, $userId, $isAdmin,
                    (string) ($body['action'] ?? 'resolve'), (string) ($body['note'] ?? '')
                )
            );
            moderation_respond($o, $d);
            return;
        }
    } catch (\Throwable $e) {
        error_log('[bp] moderation error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    respond_error(404, 'not_found');
}

/**
 * POST /api/adventures/{slug}/reports (v0.21.0).
 *
 * Readers — signed in or not — can report the adventure, a scene, a
 * choice, or an accessible contribution. The reporter is taken from
 * the session cookie and the request IP; the body carries only the
 * target, the reason, an optional note, and the honeypot field.
 */
function handle_report_create(string $slug): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] report db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    $body   = read_json_body();
    $svc    = new ReportService($pdo);
    $ip     = client_ip();

    try {
        [$o, $d] = (new WriteLock())->withLock(
            static fn (): array => $svc->create($slug, $userId, $ip, $body)
        );
    } catch (\Throwable $e) {
        error_log('[bp] report error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    if ($o === ReportService::OK) {
        http_response_code(201);
        echo json_encode(['status' => 'ok'] + ($d ?? []));
        return;
    }
    report_respond($o, $d);
}

/**
 * POST /api/reports/{id}/privacy — administrators only (v0.21.0).
 *
 * Marks a report platform-private so it stays off the adventure
 * team's queue.
 */
function handle_report_privacy(int $reportId): void
{
    try {
        $pdo = Database::open();
    } catch (\Throwable $e) {
        error_log('[bp] report privacy db error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $isAdmin = AdminSession::hasRole($pdo, $userId, 'admin');

    $body    = read_json_body();
    $private = (bool) ($body['platform_private'] ?? true);
    $svc     = new ReportService($pdo);

    try {
        [$o, $d] = (new WriteLock())->withLock(
            static fn (): array => $svc->setPlatformPrivate($reportId, $isAdmin, $private)
        );
    } catch (\Throwable $e) {
        error_log('[bp] report privacy error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }
    report_respond($o, $d);
}

/** Map a ReportService outcome to an HTTP response. */
function report_respond(string $outcome, ?array $data): void
{
    switch ($outcome) {
        case ReportService::OK:
            echo json_encode(['status' => 'ok'] + ($data ?? []));
            return;
        case ReportService::DUPLICATE:
            http_response_code(200);
            echo json_encode(['status' => 'duplicate'] + ($data ?? []));
            return;
        case ReportService::RATE_LIMITED:
            respond_error(429, 'rate_limited');
            return;
        case ReportService::NOT_FOUND:
            respond_error(404, 'not_found');
            return;
        case ReportService::FORBIDDEN:
            respond_error(403, 'forbidden');
            return;
        case ReportService::CONFLICT:
            http_response_code(409);
            echo json_encode(['status' => 'error', 'error' => 'conflict'] + ($data ?? []));
            return;
        default:
            http_response_code(422);
            echo json_encode(['status' => 'error', 'error' => 'invalid', 'fields' => $data ?? []]);
            return;
    }
}


/** Map a ModerationService outcome to an HTTP response. */
function moderation_respond(string $outcome, ?array $data): void
{
    switch ($outcome) {
        case ModerationService::OK:
            echo json_encode(['status' => 'ok'] + ($data ?? []));
            return;
        case ModerationService::NOT_FOUND:
            respond_error(404, 'not_found');
            return;
        case ModerationService::FORBIDDEN:
            respond_error(403, 'forbidden');
            return;
        case ModerationService::READ_ONLY:
            respond_error(409, 'read_only');
            return;
        case ModerationService::CONFLICT:
            http_response_code(409);
            echo json_encode(['error' => 'conflict'] + ($data ?? []));
            return;
        case ModerationService::LIMIT:
            respond_error(409, 'branch_limit_reached');
            return;
        default:
            http_response_code(422);
            echo json_encode(['error' => 'invalid'] + ($data ?? []));
            return;
    }
}

/* ═══════════════ Collaborators and ownership (v0.20.0) ═══════════════ */

/**
 * Map a CollaborationService outcome onto an HTTP response. The
 * service never returns a raw invitation token, so nothing secret can
 * leak through this path.
 */
function collaboration_respond(string $outcome, ?array $data): void
{
    switch ($outcome) {
        case CollaborationService::OK:
            echo json_encode(['status' => 'ok'] + ($data ?? []));
            return;
        case CollaborationService::NOT_FOUND:
            http_response_code(404);
            echo json_encode(['error' => 'not_found'] + ($data ?? []));
            return;
        case CollaborationService::FORBIDDEN:
            respond_error(403, 'forbidden');
            return;
        case CollaborationService::REAUTH:
            respond_error(403, 'reauthentication_required');
            return;
        case CollaborationService::UNCONFIRMED:
            respond_error(422, 'confirmation_required');
            return;
        case CollaborationService::EXPIRED:
            respond_error(410, 'expired');
            return;
        case CollaborationService::CONFLICT:
            http_response_code(409);
            echo json_encode(['error' => 'conflict'] + ($data ?? []));
            return;
        default:
            http_response_code(422);
            echo json_encode(['error' => 'invalid'] + ($data ?? []));
            return;
    }
}

/**
 * POST /api/auth/reauthenticate — re-enter the password to unlock a
 * sensitive action (currently ownership transfer) for a short window.
 */
function handle_reauthenticate(): void
{
    try { $pdo = Database::open(); }
    catch (\Throwable $e) { error_log('[bp] reauth db error: ' . $e->getMessage()); respond_error(503, 'service_unavailable'); return; }

    $cookie = $_COOKIE[AuthService::SESSION_COOKIE] ?? null;
    $userId = (new AuthService($pdo))->authenticate($cookie);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

    $body = read_json_body();
    $svc  = new CollaborationService($pdo);
    [$o, $d] = (new WriteLock())->withLock(static fn () => $svc->reauthenticate(
        $userId,
        AccountService::sessionIdFromCookie($cookie),
        (string) ($body['password'] ?? '')
    ));
    collaboration_respond($o, $d);
}

/**
 * /api/adventures/{slug}/collaborators … — roster, invitations, role
 * changes, removals, and ownership transfer. Every entry point derives
 * the caller's role server-side.
 */
function handle_collaborators(string $method, string $slug, string $tail): void
{
    try { $pdo = Database::open(); }
    catch (\Throwable $e) { error_log('[bp] collaborators db error: ' . $e->getMessage()); respond_error(503, 'service_unavailable'); return; }

    $cookie = $_COOKIE[AuthService::SESSION_COOKIE] ?? null;
    $userId = (new AuthService($pdo))->authenticate($cookie);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $isAdmin = AdminSession::hasRole($pdo, $userId, 'admin');
    $svc = new CollaborationService($pdo);

    try {
        if ($method === 'GET' && $tail === '') {
            [$o, $d] = $svc->roster($slug, $userId, $isAdmin);
            collaboration_respond($o, $d);
            return;
        }

        if ($method !== 'POST' && $method !== 'PUT' && $method !== 'DELETE') {
            respond_error(405, 'method_not_allowed');
            return;
        }
        if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

        $body = read_json_body();
        $lock = new WriteLock();

        if ($method === 'POST' && $tail === '/invitations') {
            [$o, $d] = $lock->withLock(static fn () => $svc->invite(
                $slug, $userId, $isAdmin,
                (string) ($body['email'] ?? ''),
                (string) ($body['role'] ?? 'editor'),
                (string) ($body['message'] ?? '')
            ));
            collaboration_respond($o, $d);
            return;
        }
        if (($method === 'POST' || $method === 'DELETE')
            && preg_match('#^/invitations/(\d+)/revoke$#', $tail, $m)) {
            $id = (int) $m[1];
            [$o, $d] = $lock->withLock(static fn () => $svc->revokeInvitation($slug, $userId, $isAdmin, $id));
            collaboration_respond($o, $d);
            return;
        }
        if ($method === 'PUT' && preg_match('#^/(\d+)$#', $tail, $m)) {
            $target = (int) $m[1];
            $role = isset($body['role']) && $body['role'] !== '' ? (string) $body['role'] : null;
            [$o, $d] = $lock->withLock(static fn () => $svc->setRole($slug, $userId, $isAdmin, $target, $role));
            collaboration_respond($o, $d);
            return;
        }
        if ($method === 'DELETE' && preg_match('#^/(\d+)$#', $tail, $m)) {
            $target = (int) $m[1];
            [$o, $d] = $lock->withLock(static fn () => $svc->setRole($slug, $userId, $isAdmin, $target, null));
            collaboration_respond($o, $d);
            return;
        }
        if ($method === 'POST' && $tail === '/transfer') {
            $sessionId = AccountService::sessionIdFromCookie($cookie);
            [$o, $d] = $lock->withLock(static fn () => $svc->transferOwnership(
                $slug, $userId, $isAdmin, $sessionId,
                (int) ($body['user_id'] ?? 0),
                (bool) ($body['confirm'] ?? false),
                array_key_exists('stay_as_editor', $body) ? (bool) $body['stay_as_editor'] : true
            ));
            collaboration_respond($o, $d);
            return;
        }
    } catch (\Throwable $e) {
        error_log('[bp] collaborators error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    respond_error(404, 'not_found');
}

/**
 * /api/invitations/{token} — the recipient's accept / decline screen.
 * The token is looked up by hash; only its addressee can act on it.
 */
function handle_invitations(string $method, string $tail): void
{
    try { $pdo = Database::open(); }
    catch (\Throwable $e) { error_log('[bp] invitations db error: ' . $e->getMessage()); respond_error(503, 'service_unavailable'); return; }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $svc = new CollaborationService($pdo);

    try {
        if ($method === 'GET' && preg_match('#^/([A-Za-z0-9_\-]+)$#', $tail, $m)) {
            [$o, $d] = $svc->invitationByToken($m[1], $userId);
            collaboration_respond($o, $d);
            return;
        }
        if ($method === 'POST' && preg_match('#^/([A-Za-z0-9_\-]+)/(accept|decline)$#', $tail, $m)) {
            if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
            $token = $m[1];
            $action = $m[2];
            [$o, $d] = (new WriteLock())->withLock(static fn () => $action === 'accept'
                ? $svc->acceptInvitation($token, $userId)
                : $svc->declineInvitation($token, $userId));
            collaboration_respond($o, $d);
            return;
        }
    } catch (\Throwable $e) {
        error_log('[bp] invitations error: ' . $e->getMessage());
        respond_error(503, 'service_unavailable');
        return;
    }

    respond_error(404, 'not_found');
}

/**
 * /api/notifications — the signed-in user's inbox (v0.22.0).
 *
 *   GET    /api/notifications              — inbox + unread count
 *   GET    /api/notifications/preferences  — per-kind email settings
 *   PUT    /api/notifications/preferences  — save them
 *   POST   /api/notifications/read         — mark all read
 *   POST   /api/notifications/{id}/read    — mark one read
 *   DELETE /api/notifications/{id}         — delete one routine item
 *   DELETE /api/notifications/read         — delete read routine items
 */
function handle_notifications(string $method, string $tail): void
{
    try { $pdo = Database::open(); }
    catch (\Throwable $e) { error_log('[bp] notifications db error: ' . $e->getMessage()); respond_error(503, 'service_unavailable'); return; }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $svc = new NotificationService($pdo);

    if ($method === 'GET' && $tail === '') {
        echo json_encode([
            'status'        => 'ok',
            'notifications' => $svc->inbox($userId),
            'unread'        => $svc->unreadCount($userId),
        ]);
        return;
    }
    if ($method === 'GET' && $tail === '/preferences') {
        echo json_encode([
            'status'      => 'ok',
            'preferences' => $svc->preferences($userId),
            'following'   => $svc->following($userId),
        ]);
        return;
    }

    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }
    $lock = new WriteLock();

    if ($method === 'PUT' && $tail === '/preferences') {
        $body = read_json_body();
        $prefs = (array) ($body['preferences'] ?? $body);
        $saved = $lock->withLock(static fn () => $svc->updatePreferences($userId, $prefs));
        echo json_encode(['status' => 'ok', 'preferences' => $saved]);
        return;
    }
    if ($method === 'POST' && ($tail === '/read' || preg_match('#^/(\d+)/read$#', $tail, $m))) {
        $id = isset($m[1]) ? (int) $m[1] : null;
        $lock->withLock(static function () use ($svc, $userId, $id) { $svc->markRead($userId, $id); return null; });
        echo json_encode(['status' => 'ok', 'unread' => $svc->unreadCount($userId)]);
        return;
    }
    if ($method === 'DELETE' && $tail === '/read') {
        $removed = $lock->withLock(static fn () => $svc->deleteRead($userId));
        echo json_encode(['status' => 'ok', 'deleted' => $removed, 'unread' => $svc->unreadCount($userId)]);
        return;
    }
    if ($method === 'DELETE' && preg_match('#^/(\d+)$#', $tail, $dm)) {
        $outcome = $lock->withLock(static fn () => $svc->delete($userId, (int) $dm[1]));
        if ($outcome === NotificationService::OK) {
            echo json_encode(['status' => 'ok', 'unread' => $svc->unreadCount($userId)]);
            return;
        }
        respond_error($outcome === NotificationService::FORBIDDEN ? 403 : 404, $outcome);
        return;
    }
    respond_error(404, 'not_found');
}

/**
 * /api/adventures/{slug}/follow — an update subscription.
 *
 * Distinct from a bookmark, which records a reading position. POST
 * subscribes, DELETE unsubscribes. Follower counts are never returned.
 */
function handle_follow(string $method, string $slug): void
{
    try { $pdo = Database::open(); }
    catch (\Throwable $e) { error_log('[bp] follow db error: ' . $e->getMessage()); respond_error(503, 'service_unavailable'); return; }

    $userId = (new AuthService($pdo))->authenticate($_COOKIE[AuthService::SESSION_COOKIE] ?? null);
    if ($userId === null) { respond_error(401, 'unauthenticated'); return; }
    $svc = new NotificationService($pdo);

    if ($method === 'GET') {
        $adv = $svc->adventureBySlug($slug);
        if ($adv === null) { respond_error(404, 'not_found'); return; }
        echo json_encode(['status' => 'ok', 'following' => $svc->isFollowing((int) $adv['id'], $userId)]);
        return;
    }
    if ($method !== 'POST' && $method !== 'DELETE') { respond_error(405, 'method_not_allowed'); return; }
    if (!Csrf::validate()) { respond_error(403, 'csrf_failed'); return; }

    $lock = new WriteLock();
    [$outcome, $data] = $lock->withLock(
        static fn () => $method === 'POST' ? $svc->follow($slug, $userId) : $svc->unfollow($slug, $userId)
    );
    if ($outcome !== NotificationService::OK) { respond_error(404, 'not_found'); return; }
    echo json_encode(['status' => 'ok'] + $data);
}

/* ─────────────────── Notification fan-out (v0.22.0) ────────────────
 *
 * Emission lives here, at the edge, so the services that own the
 * transaction stay focused on their invariants. Each helper runs
 * after its write has committed: a failed notification can never
 * roll back an approved branch.
 */

/** One submitted branch: tell the team, and the reviewers who gate it. */
function notify_branch_submitted(PDO $pdo, string $slug, array $data, ?int $userId): void
{
    try {
        $svc = new NotificationService($pdo);
        $adv = $svc->adventureBySlug($slug);
        if ($adv === null) return;
        $advId = (int) $adv['id'];
        $title = (string) $adv['title'];
        $url   = '/manage/' . $slug;

        if (!empty($data['published'])) {
            $svc->announceUpdate(
                $advId, $title . ' has a new branch',
                'A new branch was published.', '/adventure/' . $slug, $userId
            );
            return;
        }
        (new WriteLock())->withLock(static function () use ($svc, $advId, $title, $url, $userId) {
            $svc->emitMany($svc->teamIds($advId), 'submission_received',
                'New submission for ' . $title,
                'A reader submitted a branch and it is waiting for a decision.',
                $url, $advId, ['actor_id' => $userId]);
            $svc->emitMany($svc->teamIds($advId, ['reviewer']), 'review_needed',
                'A submission needs review in ' . $title,
                'Add a note or a recommendation when you have a moment.',
                $url, $advId, ['actor_id' => $userId]);
            return null;
        });
    } catch (\Throwable $e) {
        error_log('[bp] submission notification failed: ' . $e->getMessage());
    }
}

/** A decision on one submission: tell the contributor, then followers. */
function notify_submission_decided(PDO $pdo, string $slug, array $data, ?int $actorId): void
{
    try {
        $svc = new NotificationService($pdo);
        $adv = $svc->adventureBySlug($slug);
        if ($adv === null) return;
        $advId  = (int) $adv['id'];
        $title  = (string) $adv['title'];
        $action = (string) ($data['action'] ?? '');

        $s = $pdo->prepare('SELECT user_id, choice_text FROM branch_submissions WHERE id = :i');
        $s->execute([':i' => (int) ($data['submission_id'] ?? 0)]);
        $sub = $s->fetch(PDO::FETCH_ASSOC);
        $contributor = ($sub !== false && $sub['user_id'] !== null) ? (int) $sub['user_id'] : null;

        $map = [
            'approve'          => ['submission_approved', 'Your branch was published',
                                   'Your contribution is now part of the story.'],
            'edit_and_approve' => ['submission_approved', 'Your branch was published',
                                   'An editor made small changes and published your contribution.'],
            'reject'           => ['submission_rejected', 'Your submission was not accepted',
                                   'Open your contributions page to read the feedback.'],
            'request_changes'  => ['changes_requested', 'Changes were requested',
                                   'Edit your submission and send it back when you are ready.'],
        ];

        (new WriteLock())->withLock(static function () use ($svc, $map, $action, $contributor, $title, $slug, $advId, $actorId) {
            if ($contributor !== null && isset($map[$action])) {
                [$kind, $subject, $body] = $map[$action];
                $svc->emit($contributor, $kind, $subject . ' — ' . $title, $body,
                    '/account/contributions', $advId, ['actor_id' => null]);
            }
            if ($action === 'approve' || $action === 'edit_and_approve') {
                $svc->announceUpdate($advId, $title . ' has a new branch',
                    'A new branch was published.', '/adventure/' . $slug, $actorId);
            }
            return null;
        });
    } catch (\Throwable $e) {
        error_log('[bp] decision notification failed: ' . $e->getMessage());
    }
}

/** A contributor sent their revised branch back to the team. */
function notify_resubmitted(PDO $pdo, int $submissionId, int $userId): void
{
    try {
        $svc = new NotificationService($pdo);
        $s = $pdo->prepare(
            'SELECT b.adventure_id, a.slug, a.title
               FROM branch_submissions b JOIN adventures a ON a.id = b.adventure_id
              WHERE b.id = :i'
        );
        $s->execute([':i' => $submissionId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return;
        $advId = (int) $row['adventure_id'];
        $title = (string) $row['title'];
        $url   = '/manage/' . (string) $row['slug'];
        (new WriteLock())->withLock(static function () use ($svc, $advId, $title, $url, $userId) {
            $svc->emitMany($svc->teamIds($advId), 'submission_resubmitted',
                'A revised submission is ready in ' . $title,
                'The contributor addressed the requested changes.',
                $url, $advId, ['actor_id' => $userId]);
            return null;
        });
    } catch (\Throwable $e) {
        error_log('[bp] resubmission notification failed: ' . $e->getMessage());
    }
}

/**
 * An account security event. This kind can never be switched off, so
 * it always reaches the inbox and the account's email address.
 */
function notify_security_event(PDO $pdo, int $userId, string $subject, string $body): void
{
    try {
        $svc = new NotificationService($pdo);
        (new WriteLock())->withLock(static function () use ($svc, $userId, $subject, $body) {
            $svc->emit($userId, 'account_security', $subject, $body, '/account/security');
            return null;
        });
    } catch (\Throwable $e) {
        error_log('[bp] security notification failed: ' . $e->getMessage());
    }
}
