<?php
/**
 * RegistrationService — orchestrates a single POST /api/register.
 *
 * Layered so the HTTP entry point stays tiny and every branch is
 * covered by a focused unit test:
 *
 *   1. `checkGate` — registration_enabled, rate limit, honeypot, CSRF.
 *      Returns a code the caller maps to an HTTP status.
 *   2. `validate` — shape, length, and terms acceptance.
 *   3. `register` — case-insensitive duplicate check, hash, insert.
 *
 * The registration response is intentionally opaque: duplicate email,
 * duplicate username, and successful new signup all return the same
 * payload so an attacker cannot enumerate existing accounts.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class RegistrationService
{
    public const OUTCOME_ACCEPTED           = 'accepted';
    public const OUTCOME_DISABLED           = 'disabled';
    public const OUTCOME_RATE_LIMITED       = 'rate_limited';
    public const OUTCOME_CSRF_FAILED        = 'csrf_failed';
    public const OUTCOME_INVALID            = 'invalid';

    /** Field names that must contain non-empty strings. */
    private const REQUIRED = [
        'email','username','display_name','password','password_confirmation',
    ];

    private PDO $pdo;
    private UserRepository $users;
    private SettingsRepository $settings;
    private RegistrationRateLimiter $limiter;

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->users    = new UserRepository($pdo);
        $this->settings = new SettingsRepository($pdo);
        $this->limiter  = new RegistrationRateLimiter($pdo);
    }

    /**
     * Run a full registration cycle. Returns [outcome, errors]. The
     * outcome is one of the OUTCOME_* constants; the errors array is
     * populated only for OUTCOME_INVALID and never mentions email /
     * username uniqueness.
     *
     * @param array $payload  Raw decoded JSON body.
     * @param string $ip      Client IP.
     * @param bool $csrfOk    Result of Csrf::validate() at the boundary.
     * @return array{0:string, 1:array<string,string>}
     */
    public function handle(array $payload, string $ip, bool $csrfOk): array
    {
        $settings = $this->settings->registrationSettings();

        if (!$settings['registration_enabled']) {
            return [self::OUTCOME_DISABLED, []];
        }
        if (!$csrfOk) {
            $this->limiter->record($ip, 'rejected');
            return [self::OUTCOME_CSRF_FAILED, []];
        }
        if ($this->limiter->isBlocked($ip, $settings['registrations_per_ip_per_hour'])) {
            // Do not record — the ledger already reflects the block.
            return [self::OUTCOME_RATE_LIMITED, []];
        }

        // Honeypot: a hidden field named `nickname_url` must be empty.
        // A bot scraping the form will fill in every input. We record
        // the attempt and return the same accepted response so the bot
        // cannot detect the trap.
        if (isset($payload['nickname_url']) && trim((string) $payload['nickname_url']) !== '') {
            $this->limiter->record($ip, 'rejected');
            return [self::OUTCOME_ACCEPTED, []];
        }

        $errors = $this->validate($payload, $settings);
        if ($errors !== []) {
            $this->limiter->record($ip, 'rejected');
            return [self::OUTCOME_INVALID, $errors];
        }

        // Duplicate check happens under the exclusive write lock the
        // caller acquired around this method. Regardless of outcome we
        // return "accepted" so the response cannot be used as an
        // account enumeration oracle.
        $email    = (string) $payload['email'];
        $username = (string) $payload['username'];
        $isDuplicate = $this->users->emailExists($email)
                    || $this->users->usernameExists($username);

        if (!$isDuplicate) {
            $hash = PasswordHasher::hash((string) $payload['password']);
            $status = $this->initialStatus($settings);
            $this->users->insert([
                'email'             => $email,
                'username'          => $username,
                'display_name'      => (string) $payload['display_name'],
                'password_hash'     => $hash['hash'],
                'password_algo'     => $hash['algo'],
                'status'            => $status,
                'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            // A race that still produced a unique-index violation is
            // absorbed by UserRepository::insert() returning null.
        }

        $this->limiter->record($ip, 'accepted');
        return [self::OUTCOME_ACCEPTED, []];
    }

    /**
     * @return array<string,string>
     */
    public function validate(array $payload, array $settings): array
    {
        $errors = [];
        foreach (self::REQUIRED as $key) {
            if (!isset($payload[$key]) || trim((string) $payload[$key]) === '') {
                $errors[$key] = 'required';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $email = trim((string) $payload['email']);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalid';
        }

        $username = trim((string) $payload['username']);
        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $username)) {
            $errors['username'] = 'invalid';
        }

        $display = trim((string) $payload['display_name']);
        if (strlen($display) < 1 || strlen($display) > 64) {
            $errors['display_name'] = 'invalid';
        }

        $password = (string) $payload['password'];
        $min      = (int) $settings['minimum_password_length'];
        if (strlen($password) < $min) {
            $errors['password'] = 'too_short';
        }
        if ($password !== (string) $payload['password_confirmation']) {
            $errors['password_confirmation'] = 'mismatch';
        }

        $terms = $payload['terms_accepted'] ?? false;
        if ($terms !== true && $terms !== 1 && $terms !== '1' && $terms !== 'true') {
            $errors['terms_accepted'] = 'required';
        }

        return $errors;
    }

    private function initialStatus(array $settings): string
    {
        if ($settings['require_email_verification']) {
            return 'pending_verification';
        }
        if ($settings['require_admin_approval']) {
            return 'pending_verification';
        }
        return 'active';
    }
}
