<?php
/**
 * tests/e2e/router.php — PHP built-in server router mirroring
 * public/.htaccess: /api/* -> api/index.php, existing files served
 * as-is, everything else gets the single-page shell (index.html).
 * Test-only; production uses Apache or Nginx (docs/deploy/).
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/api(/|$)#', $path)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/api/index.php';
    return true;
}
if (preg_match('#(^|/)\.|\.(sqlite|sqlite-wal|sqlite-shm|lock|log|sql|key)$#', $path)) {
    http_response_code(403);
    return true;
}
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
header('Content-Type: text/html; charset=utf-8');
readfile($_SERVER['DOCUMENT_ROOT'] . '/index.html');
return true;
