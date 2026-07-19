<?php
/**
 * tests/php/registration_test.php
 *
 * Version 0.11.0 — focused coverage for the registration pipeline.
 *
 * Each test builds a fresh temporary SQLite database, runs the full
 * migration ladder, and drives RegistrationService directly. The HTTP
 * layer is thin enough that exercising the service covers every real
 * branch — the entry point in public/api/index.php is a plain switch
 * over the outcome constants.
 */

declare(strict_types=1);

use App\Csrf;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\RegistrationRateLimiter;
use App\RegistrationService;
use App\SettingsRepository;
use App\UserRepository;

final class BPRegistrationTest
{
    private string $tmpDb;
    private \PDO $pdo;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-reg-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email'                 => 'new@example.com',
            'username'              => 'newreader',
            'display_name'          => 'New Reader',
            'password'              => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
            'terms_accepted'        => true,
            'nickname_url'          => '',
        ], $overrides);
    }

    // ── happy path ─────────────────────────────────────────────────

    public function testHappyPathInsertsUserAndReportsAccepted(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle($this->validPayload(), '10.0.0.1', true);

        assert_same(RegistrationService::OUTCOME_ACCEPTED, $outcome);
        assert_same([], $errors);

        $row = $this->pdo->query(
            "SELECT email, email_normalized, username, username_normalized, status, password_algo
               FROM users WHERE username = 'newreader'"
        )->fetch();
        assert_true(is_array($row), 'user row was written');
        assert_same('new@example.com', $row['email']);
        assert_same('new@example.com', $row['email_normalized']);
        assert_same('newreader', $row['username_normalized']);
        // require_email_verification defaults to 1 → pending_verification.
        assert_same('pending_verification', $row['status']);
        assert_true(in_array($row['password_algo'], ['argon2id','bcrypt'], true));
    }

    // ── case-insensitive uniqueness ────────────────────────────────

    public function testDuplicateEmailIsSilentlyAccepted(): void
    {
        $svc = new RegistrationService($this->pdo);
        $svc->handle($this->validPayload(), '10.0.0.1', true);
        [$outcome] = $svc->handle(
            $this->validPayload([
                'email'    => 'NEW@Example.COM',   // same email, different case
                'username' => 'otheruser',
            ]),
            '10.0.0.2',
            true,
        );
        // Enumeration-safe: caller sees the same outcome as a fresh insert.
        assert_same(RegistrationService::OUTCOME_ACCEPTED, $outcome);
        // But only one user actually exists.
        $count = (int) $this->pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        assert_same(1, $count);
    }

    public function testDuplicateUsernameCaseInsensitive(): void
    {
        $svc = new RegistrationService($this->pdo);
        $svc->handle($this->validPayload(), '10.0.0.1', true);
        $svc->handle(
            $this->validPayload([
                'email'    => 'other@example.com',
                'username' => 'NewReader',
            ]),
            '10.0.0.2',
            true,
        );
        $count = (int) $this->pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        assert_same(1, $count, 'case-different username does not create a second row');
    }

    // ── validation ─────────────────────────────────────────────────

    public function testMissingFieldsSurfaceIndividualErrors(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle([], '10.0.0.3', true);
        assert_same(RegistrationService::OUTCOME_INVALID, $outcome);
        assert_true(isset($errors['email']));
        assert_true(isset($errors['username']));
        assert_true(isset($errors['password']));
    }

    public function testShortPasswordRejected(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle(
            $this->validPayload(['password' => 'short', 'password_confirmation' => 'short']),
            '10.0.0.3',
            true,
        );
        assert_same(RegistrationService::OUTCOME_INVALID, $outcome);
        assert_same('too_short', $errors['password']);
    }

    public function testPasswordConfirmationMismatch(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle(
            $this->validPayload(['password_confirmation' => 'different but long enough']),
            '10.0.0.3',
            true,
        );
        assert_same(RegistrationService::OUTCOME_INVALID, $outcome);
        assert_same('mismatch', $errors['password_confirmation']);
    }

    public function testTermsAcceptanceIsRequired(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle(
            $this->validPayload(['terms_accepted' => false]),
            '10.0.0.3',
            true,
        );
        assert_same(RegistrationService::OUTCOME_INVALID, $outcome);
        assert_same('required', $errors['terms_accepted']);
    }

    public function testInvalidEmailShape(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle(
            $this->validPayload(['email' => 'not-an-email']),
            '10.0.0.3',
            true,
        );
        assert_same(RegistrationService::OUTCOME_INVALID, $outcome);
        assert_same('invalid', $errors['email']);
    }

    public function testInvalidUsernameCharacters(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome, $errors] = $svc->handle(
            $this->validPayload(['username' => 'has spaces!']),
            '10.0.0.3',
            true,
        );
        assert_same(RegistrationService::OUTCOME_INVALID, $outcome);
        assert_same('invalid', $errors['username']);
    }

    // ── security gates ─────────────────────────────────────────────

    public function testHoneypotSilentlyAcceptsAndDoesNotInsert(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome] = $svc->handle(
            $this->validPayload(['nickname_url' => 'http://spam.example']),
            '10.0.0.4',
            true,
        );
        assert_same(RegistrationService::OUTCOME_ACCEPTED, $outcome);
        $count = (int) $this->pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        assert_same(0, $count, 'honeypot submissions never create a user');
    }

    public function testMissingCsrfIsRejected(): void
    {
        $svc = new RegistrationService($this->pdo);
        [$outcome] = $svc->handle($this->validPayload(), '10.0.0.5', false);
        assert_same(RegistrationService::OUTCOME_CSRF_FAILED, $outcome);
        $count = (int) $this->pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        assert_same(0, $count);
    }

    public function testRegistrationDisabledSetting(): void
    {
        (new SettingsRepository($this->pdo))->set('registration_enabled', '0');
        $svc = new RegistrationService($this->pdo);
        [$outcome] = $svc->handle($this->validPayload(), '10.0.0.6', true);
        assert_same(RegistrationService::OUTCOME_DISABLED, $outcome);
    }

    public function testRateLimitBlocksAfterCeiling(): void
    {
        $settings = new SettingsRepository($this->pdo);
        $settings->set('registrations_per_ip_per_hour', '2');
        $svc = new RegistrationService($this->pdo);
        // Two accepted attempts fill the bucket.
        for ($i = 0; $i < 2; $i++) {
            $svc->handle(
                $this->validPayload([
                    'email'    => "u$i@example.com",
                    'username' => "user$i",
                ]),
                '10.0.0.7',
                true,
            );
        }
        [$outcome] = $svc->handle(
            $this->validPayload(['email' => 'u2@example.com', 'username' => 'user2']),
            '10.0.0.7',
            true,
        );
        assert_same(RegistrationService::OUTCOME_RATE_LIMITED, $outcome);
    }

    // ── infrastructure ─────────────────────────────────────────────

    public function testPasswordHasherRoundTrips(): void
    {
        $out = PasswordHasher::hash('correct horse battery staple');
        assert_true(in_array($out['algo'], ['argon2id','bcrypt'], true));
        assert_true(PasswordHasher::verify('correct horse battery staple', $out['hash']));
        assert_true(!PasswordHasher::verify('wrong password', $out['hash']));
    }

    public function testCsrfDoubleSubmitEqualityAndTimingSafe(): void
    {
        $token = Csrf::issue();
        // Simulate the browser echoing the cookie in the header.
        assert_true(Csrf::validate($token));
        assert_true(!Csrf::validate('nope' . str_repeat('0', 60)));
        // A short header fails length gate cleanly.
        assert_true(!Csrf::validate('short'));
    }

    public function testUserRepositoryNormalisation(): void
    {
        assert_same('alice@example.com', UserRepository::normaliseEmail('  Alice@Example.COM '));
        assert_same('alice', UserRepository::normaliseUsername('  Alice '));
    }

    public function testRateLimiterCountsOnlyRecentAttempts(): void
    {
        $limiter = new RegistrationRateLimiter($this->pdo);
        $limiter->record('10.9.9.9', 'rejected');
        $limiter->record('10.9.9.9', 'accepted');
        assert_same(2, $limiter->recentCount('10.9.9.9'));
        assert_same(0, $limiter->recentCount('10.9.9.8'));
    }
}
