<?php
/**
 * SettingsRepository — read/write the `settings` key/value table.
 *
 * Values are stored as text; typed accessors coerce them at the call
 * site so a stray non-integer never crashes the caller. Missing keys
 * fall back to the supplied default so the code is resilient to a
 * partial migration.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class SettingsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        if ($row === false || !isset($row['value'])) {
            return $default;
        }
        return (string) $row['value'];
    }

    public function getInt(string $key, int $default): int
    {
        $v = $this->get($key, (string) $default);
        if ($v === null || !is_numeric($v)) {
            return $default;
        }
        return (int) $v;
    }

    public function getBool(string $key, bool $default): bool
    {
        $v = $this->get($key, $default ? '1' : '0');
        if ($v === null) return $default;
        $v = strtolower(trim($v));
        return in_array($v, ['1','true','yes','on'], true);
    }

    public function set(string $key, string $value): void
    {
        $this->pdo->prepare(
            "INSERT INTO settings (key, value, updated_at)
             VALUES (:k, :v, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
             ON CONFLICT(key) DO UPDATE
                SET value = excluded.value,
                    updated_at = excluded.updated_at"
        )->execute([':k' => $key, ':v' => $value]);
    }

    /** Bulk read for the public registration form. */
    public function registrationSettings(): array
    {
        return [
            'registration_enabled'          => $this->getBool('registration_enabled', true),
            'minimum_password_length'       => max(8, $this->getInt('minimum_password_length', 12)),
            'registrations_per_ip_per_hour' => max(0, $this->getInt('registrations_per_ip_per_hour', 5)),
            'require_email_verification'    => $this->getBool('require_email_verification', true),
            'require_admin_approval'        => $this->getBool('require_admin_approval', false),
        ];
    }
}
