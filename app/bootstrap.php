<?php
/**
 * Application bootstrap.
 *
 * Loads configuration, wires the autoloader for the tiny `App\` namespace
 * used by our internal classes, and installs safe error handlers that
 * never leak paths, stack traces, or SQL details to end users. This file
 * MUST be included by every entry point — CLI scripts and the HTTP API —
 * before anything else runs.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

// ── Autoloader ──────────────────────────────────────────────────────
// A minimalist PSR-4 style loader mapping "App\..." to app/... .
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $candidates = [
        BP_ROOT . '/app/database/' . $relative . '.php',
        BP_ROOT . '/app/services/' . $relative . '.php',
        BP_ROOT . '/app/repositories/' . $relative . '.php',
        BP_ROOT . '/app/security/' . $relative . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// ── Error and exception handling ────────────────────────────────────
// Never reveal file paths, SQL error text, or stack traces to the
// caller. Errors are logged to `private/logs/php-error.log` and a
// short, opaque JSON response is returned by the API entry point.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$logsDir = BP_ROOT . '/private/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}
ini_set('error_log', $logsDir . '/php-error.log');

set_exception_handler(static function (\Throwable $e): void {
    error_log(sprintf(
        '[bp] uncaught %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    // For CLI, print a short, non-revealing line and exit.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "error: an unexpected condition prevented the command from finishing.\n");
        exit(1);
    }
    // For HTTP, return an opaque 500 payload if the response has not
    // started yet.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'internal_error']);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Respect the current error_reporting mask (e.g. @-suppressed calls).
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
