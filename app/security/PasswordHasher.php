<?php
/**
 * PasswordHasher — Argon2id when available, bcrypt otherwise.
 *
 * Both algorithms are supported by PHP's built-in `password_hash()` /
 * `password_verify()`. Argon2id is preferred because it is memory-hard
 * and resistant to GPU cracking, but PHP builds compiled without
 * libsodium fall back to bcrypt so the site still runs on minimal
 * shared hosts.
 *
 * The chosen algorithm is stored per-row on `users.password_algo` so a
 * future prompt can rehash silently when the operator upgrades PHP.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class PasswordHasher
{
    /** @return array{hash:string, algo:string} */
    public static function hash(string $password): array
    {
        if ($password === '') {
            throw new RuntimeException('password must not be empty');
        }
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash($password, PASSWORD_ARGON2ID);
            if (is_string($hash) && $hash !== '') {
                return ['hash' => $hash, 'algo' => 'argon2id'];
            }
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('password hashing failed');
        }
        return ['hash' => $hash, 'algo' => 'bcrypt'];
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** True when Argon2id is available in this PHP build. */
    public static function argon2idAvailable(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }
}
