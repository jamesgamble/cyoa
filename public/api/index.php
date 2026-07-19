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

use App\AdminSession;
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

