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
use App\Csrf;
use App\Database;
use App\EmailQueueRepository;
use App\EmailQueueService;
use App\EmailTemplateRepository;
use App\Mailer\SmtpTransport;
use App\Migrator;
use App\PublicRepository;
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
        echo json_encode(['contributions' => $svc->myContributions($userId)]);
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
