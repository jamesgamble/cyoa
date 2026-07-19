<?php
/**
 * SmtpSettingsRepository — a single-row config table with encrypted
 * password storage.
 *
 * `load()` returns a decrypted array for server-side use.
 * `loadForApi()` returns a copy safe to send to the browser: the
 * password field is replaced with an opaque marker (`__unchanged__`
 * when a password is on file, empty string otherwise), so the raw
 * password never leaves the server.
 * `save()` accepts a payload from the admin API, only rewrites the
 * password when the marker is missing, and encrypts before insert.
 */

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class SmtpSettingsRepository
{
    public const PASSWORD_UNCHANGED_MARKER = '__unchanged__';

    private PDO $pdo;
    private Encryption $crypto;

    public function __construct(PDO $pdo, ?Encryption $crypto = null)
    {
        $this->pdo    = $pdo;
        $this->crypto = $crypto ?? new Encryption();
    }

    /** @return array<string,mixed> */
    public function load(): array
    {
        $row = $this->pdo->query(
            'SELECT host, port, encryption, username, password_ciphertext,
                    from_email, from_name, reply_to, enabled, retry_limit,
                    batch_size, updated_at
             FROM smtp_settings WHERE id = 1'
        )->fetch();

        if ($row === false) {
            // Insert a default row if for some reason it is missing.
            $this->pdo->exec('INSERT INTO smtp_settings (id) VALUES (1)');
            return $this->load();
        }

        $password = '';
        $cipher = (string) ($row['password_ciphertext'] ?? '');
        if ($cipher !== '') {
            try { $password = $this->crypto->decrypt($cipher); }
            catch (\Throwable $_) { $password = ''; }
        }

        return [
            'host'        => (string) $row['host'],
            'port'        => (int) $row['port'],
            'encryption'  => (string) $row['encryption'],
            'username'    => (string) $row['username'],
            'password'    => $password,
            'from_email'  => (string) $row['from_email'],
            'from_name'   => (string) $row['from_name'],
            'reply_to'    => (string) $row['reply_to'],
            'enabled'     => ((int) $row['enabled']) === 1,
            'retry_limit' => (int) $row['retry_limit'],
            'batch_size'  => (int) $row['batch_size'],
            'has_password' => $cipher !== '',
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    /**
     * Same as load() but with the password redacted for API use.
     * The API layer must never call load() when returning to the
     * browser.
     *
     * @return array<string,mixed>
     */
    public function loadForApi(): array
    {
        $s = $this->load();
        $s['password'] = $s['has_password'] ? self::PASSWORD_UNCHANGED_MARKER : '';
        return $s;
    }

    /**
     * Validate and persist an update from the admin API.
     *
     * @param array<string,mixed> $input
     * @return array{0:bool,1:array<string,string>} [ok, errors]
     */
    public function save(array $input): array
    {
        $errors = [];
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0);
        $enc  = strtolower(trim((string) ($input['encryption'] ?? 'starttls')));
        $user = trim((string) ($input['username'] ?? ''));
        $pass = (string) ($input['password'] ?? '');
        $from = trim((string) ($input['from_email'] ?? ''));
        $fromName = trim((string) ($input['from_name'] ?? ''));
        $replyTo  = trim((string) ($input['reply_to'] ?? ''));
        $enabled  = !empty($input['enabled']);
        $retry    = (int) ($input['retry_limit'] ?? 5);
        $batch    = (int) ($input['batch_size'] ?? 25);

        if ($host === '' || strlen($host) > 255) {
            $errors['host'] = 'invalid';
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'invalid';
        }
        if (!in_array($enc, ['none','starttls','tls'], true)) {
            $errors['encryption'] = 'invalid';
        }
        if (strlen($user) > 255) {
            $errors['username'] = 'too_long';
        }
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = 'invalid';
        }
        if (strlen($fromName) > 128) {
            $errors['from_name'] = 'too_long';
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $errors['reply_to'] = 'invalid';
        }
        if ($retry < 0 || $retry > 20) {
            $errors['retry_limit'] = 'invalid';
        }
        if ($batch < 1 || $batch > 500) {
            $errors['batch_size'] = 'invalid';
        }
        if ($errors !== []) {
            return [false, $errors];
        }

        // Only overwrite the password if the caller supplied a new one.
        // The API returns PASSWORD_UNCHANGED_MARKER when a value is on
        // file, so echoing it back means "leave it alone".
        $rewritePassword = ($pass !== self::PASSWORD_UNCHANGED_MARKER);
        $cipher = null;
        if ($rewritePassword) {
            $cipher = $pass === '' ? '' : $this->crypto->encrypt($pass);
        }

        if ($rewritePassword) {
            $this->pdo->prepare(
                'UPDATE smtp_settings SET
                    host = :host, port = :port, encryption = :enc,
                    username = :user, password_ciphertext = :cipher,
                    from_email = :from, from_name = :from_name,
                    reply_to = :reply_to, enabled = :enabled,
                    retry_limit = :retry, batch_size = :batch,
                    updated_at = strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\')
                 WHERE id = 1'
            )->execute([
                ':host'      => $host, ':port' => $port, ':enc' => $enc,
                ':user'      => $user, ':cipher' => $cipher,
                ':from'      => $from, ':from_name' => $fromName,
                ':reply_to'  => $replyTo, ':enabled' => $enabled ? 1 : 0,
                ':retry'     => $retry, ':batch' => $batch,
            ]);
        } else {
            $this->pdo->prepare(
                'UPDATE smtp_settings SET
                    host = :host, port = :port, encryption = :enc,
                    username = :user,
                    from_email = :from, from_name = :from_name,
                    reply_to = :reply_to, enabled = :enabled,
                    retry_limit = :retry, batch_size = :batch,
                    updated_at = strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\')
                 WHERE id = 1'
            )->execute([
                ':host'      => $host, ':port' => $port, ':enc' => $enc,
                ':user'      => $user,
                ':from'      => $from, ':from_name' => $fromName,
                ':reply_to'  => $replyTo, ':enabled' => $enabled ? 1 : 0,
                ':retry'     => $retry, ':batch' => $batch,
            ]);
        }
        return [true, []];
    }
}
