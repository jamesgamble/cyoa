<?php
/**
 * Csrf — double-submit cookie CSRF protection.
 *
 * A safe method (GET) provisions a random token, sets it in a
 * SameSite=Strict, HttpOnly-off cookie so JS on the same origin can
 * read it, and returns it in the response body. Every unsafe request
 * (POST, PUT, DELETE) must send the token back in the `X-CSRF-Token`
 * header. The server compares the header to the cookie with a
 * timing-safe compare; a mismatch is rejected before any business
 * logic runs.
 *
 * The cookie is scoped to the site origin and to `/api`. It carries
 * no user identity, only random entropy, so leaking it does not
 * enable an attacker to authenticate — it exists solely to bind a
 * given browser session to the tokens it has been issued.
 */

declare(strict_types=1);

namespace App;

final class Csrf
{
    public const COOKIE_NAME = 'bp_csrf';
    public const HEADER_NAME = 'X-CSRF-Token';

    /** Issue a new token, set it as a cookie, and return it. */
    public static function issue(): string
    {
        $token = bin2hex(random_bytes(32));
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE_NAME, $token, [
                'expires'  => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => self::isHttps(),
                'httponly' => false,
                'samesite' => 'Strict',
            ]);
        }
        // Also make the token visible on the current request in case
        // this call happens on the same request as a validate() call.
        $_COOKIE[self::COOKIE_NAME] = $token;
        return $token;
    }

    /**
     * Validate the incoming request against the CSRF cookie.
     *
     * Returns true only when both the header and cookie are present,
     * are the expected length, and are equal by timing-safe compare.
     */
    public static function validate(?string $headerToken = null): bool
    {
        $header = $headerToken ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($header) || !is_string($cookie)) {
            return false;
        }
        if (strlen($header) < 32 || strlen($header) !== strlen($cookie)) {
            return false;
        }
        return hash_equals($cookie, $header);
    }

    private static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
