<?php
/**
 * Public HTTP API entry point.
 *
 * This file is the ONLY PHP file exposed under the web root at
 * `public/api/`. Every request is dispatched from here so we can
 * apply consistent headers, error handling, and safety guarantees.
 *
 * v0.9.0 exposes a single route: `GET /api/health`. It intentionally
 * returns only:
 *   - api status
 *   - database status
 *   - schema version
 *   - application version
 *
 * It never leaks paths, secrets, stack traces, or raw SQL error text.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;
use App\Migrator;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';

// Strip an /api prefix so both /api/health and /health resolve.
$route = preg_replace('#^/api#', '', $path) ?: '/';

if ($method === 'GET' && ($route === '/health' || $route === '/health/')) {
    respond_health();
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);
exit;

/**
 * Emit the health payload. Any failure while gathering it downgrades
 * the corresponding status but never surfaces the underlying error.
 */
function respond_health(): void
{
    $config = bp_config();

    $dbStatus = 'unknown';
    $schemaVersion = '0';
    try {
        $pdo = Database::open();
        // Trivial round-trip to confirm the connection works.
        $pdo->query('SELECT 1');
        $dbStatus = 'ok';
        try {
            $migrator = new Migrator($pdo);
            $schemaVersion = $migrator->currentVersion();
        } catch (\Throwable $_) {
            // Tracking table not yet initialised — that is fine and
            // is reported as schema version 0 rather than an error.
            $schemaVersion = '0';
        }
    } catch (\Throwable $e) {
        error_log('[bp] health db error: ' . $e->getMessage());
        $dbStatus = 'unavailable';
    }

    echo json_encode([
        'api'             => 'ok',
        'database'        => $dbStatus,
        'schema_version'  => $schemaVersion,
        'app_version'     => (string) $config['app']['version'],
    ]);
}
