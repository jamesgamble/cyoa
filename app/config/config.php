<?php
/**
 * Application configuration.
 *
 * All runtime configuration for Branching Paths lives here. Values are
 * derived from environment variables where available, with safe defaults
 * for local development. This file is intentionally tiny and side-effect
 * free — it may be included multiple times without ill effect.
 *
 * Paths are always resolved relative to the project root (the parent of
 * the `app/` directory) so scripts can be invoked from anywhere.
 */

declare(strict_types=1);

if (!defined('BP_ROOT')) {
    define('BP_ROOT', dirname(__DIR__, 2));
}

/**
 * Read an environment variable, falling back to a default.
 *
 * We check `getenv()` (populated by CLI/exec), `$_ENV`, and `$_SERVER`
 * so this works under both Apache and CLI without a .env parser.
 */
function bp_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

/**
 * Resolve a path against the project root when it is not absolute.
 */
function bp_resolve_path(string $path): string
{
    if ($path === '') {
        return BP_ROOT;
    }
    // Absolute on POSIX (starts with /) or Windows (drive letter).
    if ($path[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
        return $path;
    }
    return BP_ROOT . DIRECTORY_SEPARATOR . $path;
}

/**
 * The single canonical configuration array. Callers should treat this
 * as read-only.
 */
function bp_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $version = trim((string) @file_get_contents(BP_ROOT . '/VERSION'));
    if ($version === '') {
        $version = '0.0.0';
    }

    $config = [
        'app' => [
            'name'    => 'Branching Paths',
            'env'     => bp_env('APP_ENV', 'development'),
            'url'     => bp_env('APP_URL', 'http://localhost:8000'),
            'version' => $version,
        ],
        'database' => [
            'path' => bp_resolve_path(
                bp_env('DATABASE_PATH', 'private/data/branching-paths.sqlite') ?? ''
            ),
            'busy_timeout_ms' => 10000,
        ],
        'lock' => [
            'path'        => bp_resolve_path(
                bp_env('WRITE_LOCK_PATH', 'private/locks/write.lock') ?? ''
            ),
            'timeout_ms'  => 5000,
        ],
        'migrations' => [
            'path' => bp_resolve_path('private/migrations'),
        ],
    ];

    return $config;
}
