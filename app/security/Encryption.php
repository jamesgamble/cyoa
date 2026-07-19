<?php
/**
 * Encryption — symmetric encrypt/decrypt for at-rest secrets that must
 * be recoverable (SMTP password today; more later).
 *
 * The key lives in a plain file OUTSIDE the SQLite database, at
 * `private/keys/app.key` by default, so an attacker who obtains a
 * copy of the database file alone cannot read the encrypted values.
 * The file is 0600 and contains 32 raw bytes; it is created lazily
 * the first time this class is used.
 *
 * Ciphertext format: `v1:<base64(nonce)>:<base64(cipher)>`
 * Cipher: libsodium's `crypto_secretbox` when available (Argon2id-era
 * PHP), else AES-256-GCM via OpenSSL. Both are authenticated so a
 * tampered ciphertext fails decrypt.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Encryption
{
    private const V1 = 'v1';

    private string $keyPath;
    private ?string $key = null;

    public function __construct(?string $keyPath = null)
    {
        $this->keyPath = $keyPath ?? (BP_ROOT . '/private/keys/app.key');
    }

    /** Encrypt a plaintext string; returns the wire format. */
    public function encrypt(string $plaintext): string
    {
        $key = $this->loadKey();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } else {
            $nonce = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt(
                $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag
            );
            if ($cipher === false) {
                throw new RuntimeException('encryption failed');
            }
            $cipher .= $tag;
        }
        return self::V1 . ':' . base64_encode($nonce) . ':' . base64_encode($cipher);
    }

    /** Decrypt a value produced by encrypt(). Throws on tampering. */
    public function decrypt(string $wire): string
    {
        if ($wire === '') {
            return '';
        }
        $parts = explode(':', $wire, 3);
        if (count($parts) !== 3 || $parts[0] !== self::V1) {
            throw new RuntimeException('ciphertext format is not recognised');
        }
        $nonce  = base64_decode($parts[1], true);
        $cipher = base64_decode($parts[2], true);
        if ($nonce === false || $cipher === false) {
            throw new RuntimeException('ciphertext could not be decoded');
        }
        $key = $this->loadKey();
        if (function_exists('sodium_crypto_secretbox_open')) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain === false) {
                throw new RuntimeException('ciphertext failed authentication');
            }
            return $plain;
        }
        $tagLen = 16;
        if (strlen($cipher) < $tagLen) {
            throw new RuntimeException('ciphertext is truncated');
        }
        $tag  = substr($cipher, -$tagLen);
        $body = substr($cipher, 0, -$tagLen);
        $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('ciphertext failed authentication');
        }
        return $plain;
    }

    /** Sign an arbitrary payload with the app key (HMAC-SHA256). */
    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->loadKey());
    }

    /** Constant-time HMAC verification. */
    public function verify(string $data, string $signature): bool
    {
        return hash_equals($this->sign($data), $signature);
    }

    public function keyPath(): string
    {
        return $this->keyPath;
    }

    /**
     * Ensure the key file exists and return its raw 32 bytes.
     */
    private function loadKey(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $dir = dirname($this->keyPath);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('key directory is not writable');
        }
        if (!is_file($this->keyPath)) {
            $bytes = random_bytes(32);
            // Write atomically then chmod 0600.
            $tmp = $this->keyPath . '.new';
            if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
                throw new RuntimeException('key file could not be created');
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->keyPath)) {
                @unlink($tmp);
                throw new RuntimeException('key file could not be installed');
            }
        }
        $raw = @file_get_contents($this->keyPath);
        if ($raw === false || strlen($raw) < 32) {
            throw new RuntimeException('key file is unreadable or truncated');
        }
        $this->key = substr($raw, 0, 32);
        return $this->key;
    }
}
