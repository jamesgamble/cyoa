<?php
/**
 * tests/php/auth_test.php
 *
 * Version 0.13.0 — focused coverage for verification, login, password
 * reset, and database-backed sessions.
 *
 * Every case starts from a fresh temporary SQLite database, runs the
 * full migration ladder, seeds one active user, and drives
 * AuthService / SessionRepository / TokenRepository directly. HTTP
 * routing in `public/api/index.php` is a thin switch over the same
 * outcome constants and does not need its own harness.
 */

declare(strict_types=1);

use App\AuthService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\SessionRepository;
use App\SettingsRepository;
use App\TokenRepository;
use App\UserRepository;

final class BPAuthTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private AuthService $auth;
    private int $activeUserId;
    private int $pendingUserId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();

        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $this->activeUserId = (int) $repo->insert([
            'email' => 'alice@example.com', 'username' => 'alice',
            'display_name' => 'Alice', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->pendingUserId = (int) $repo->insert([
            'email' => 'bob@example.com', 'username' => 'bob',
            'display_name' => 'Bob', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'pending_verification',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->auth = new AuthService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /* ─── Login ─── */

    public function testLoginHappyPathIssuesSessionCookie(): void
    {
        [$outcome, $cookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        assert_same('ok', $outcome);
        assert_true(is_string($cookie) && strpos($cookie, '.') !== false, 'cookie shape');
        $uid = (new SessionRepository($this->pdo))->authenticate($cookie);
        assert_same($this->activeUserId, $uid);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        [$o, $c] = $this->auth->login('alice@example.com', 'wrong');
        assert_same('invalid', $o); assert_same(null, $c);
    }

    public function testLoginRejectsUnknownAccountWithSameOutcome(): void
    {
        [$o] = $this->auth->login('nobody@example.com', 'anything');
        assert_same('invalid', $o);
    }

    public function testLoginRejectsPendingVerification(): void
    {
        [$o] = $this->auth->login('bob@example.com', 'correct horse battery staple');
        assert_same('pending_verification', $o);
    }

    public function testLoginRejectsSuspended(): void
    {
        $this->pdo->exec("UPDATE users SET status = 'suspended' WHERE id = {$this->activeUserId}");
        [$o] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        assert_same('suspended', $o);
    }

    public function testLoginRotatesPriorSessions(): void
    {
        [$_o1, $c1] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        [$_o2, $c2] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        $sessions = new SessionRepository($this->pdo);
        assert_same(null, $sessions->authenticate((string) $c1), 'old cookie revoked');
        assert_same($this->activeUserId, $sessions->authenticate((string) $c2));
    }

    /* ─── Sessions ─── */

    public function testSessionStoresOnlyHashedToken(): void
    {
        [, $cookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        [$_id, $raw] = explode('.', (string) $cookie, 2);
        $row = $this->pdo->query('SELECT token_hash FROM sessions ORDER BY id DESC LIMIT 1')->fetch();
        assert_true($row['token_hash'] !== $raw, 'row must not contain raw token');
        assert_same(hash('sha256', $raw), (string) $row['token_hash']);
    }

    public function testExpiredSessionIsRefused(): void
    {
        [, $cookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        $past = gmdate('Y-m-d\TH:i:s\Z', time() - 10);
        $this->pdo->exec("UPDATE sessions SET expires_at = '$past'");
        assert_same(null, (new SessionRepository($this->pdo))->authenticate((string) $cookie));
    }

    public function testLogoutRevokesSession(): void
    {
        [, $cookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        $this->auth->logout($cookie);
        assert_same(null, $this->auth->authenticate($cookie));
    }

    public function testAuthenticateRejectsTamperedCookie(): void
    {
        [, $cookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        [$id] = explode('.', (string) $cookie, 2);
        assert_same(null, $this->auth->authenticate($id . '.tampered'));
    }

    /* ─── Verification tokens ─── */

    public function testVerifyEmailHappyPath(): void
    {
        $repo = new TokenRepository($this->pdo, 'email_verification_tokens');
        $raw = $repo->issue($this->pendingUserId, AuthService::VERIFY_TTL);
        assert_same('ok', $this->auth->verifyEmail($raw));
        $row = $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->pendingUserId)->fetch();
        assert_same('active', $row['status']);
    }

    public function testVerifyTokenIsSingleUse(): void
    {
        $repo = new TokenRepository($this->pdo, 'email_verification_tokens');
        $raw = $repo->issue($this->pendingUserId, AuthService::VERIFY_TTL);
        assert_same('ok', $this->auth->verifyEmail($raw));
        assert_same('token_invalid', $this->auth->verifyEmail($raw));
    }

    public function testVerifyTokenExpiryIsEnforced(): void
    {
        $repo = new TokenRepository($this->pdo, 'email_verification_tokens');
        $raw = $repo->issue($this->pendingUserId, 60);
        $past = gmdate('Y-m-d\TH:i:s\Z', time() - 10);
        $this->pdo->exec("UPDATE email_verification_tokens SET expires_at = '$past'");
        assert_same('token_invalid', $this->auth->verifyEmail($raw));
    }

    public function testTokenIsStoredOnlyAsHash(): void
    {
        $repo = new TokenRepository($this->pdo, 'email_verification_tokens');
        $raw = $repo->issue($this->pendingUserId, 3600);
        $stored = (string) $this->pdo->query('SELECT token_hash FROM email_verification_tokens')->fetchColumn();
        assert_true($stored !== $raw, 'raw token must not be persisted');
        assert_same(TokenRepository::hash($raw), $stored);
    }

    /* ─── Password reset ─── */

    public function testForgotPasswordOpaqueForUnknownAndKnown(): void
    {
        assert_same('opaque', $this->auth->forgotPassword('nobody@example.com'));
        assert_same('opaque', $this->auth->forgotPassword('alice@example.com'));
        // Only the known+active user gets a token row.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
        assert_same(1, $count);
    }

    public function testResetPasswordConsumesTokenAndRevokesSessions(): void
    {
        // Set up a live session first.
        [, $oldCookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        $repo = new TokenRepository($this->pdo, 'password_reset_tokens');
        $raw = $repo->issue($this->activeUserId, AuthService::RESET_TTL);
        [$outcome, $errors] = $this->auth->resetPassword($raw, 'a new correct battery staple', 'a new correct battery staple');
        assert_same('ok', $outcome);
        assert_same([], $errors);
        // Old cookie is dead.
        assert_same(null, $this->auth->authenticate($oldCookie));
        // Token is now used.
        assert_same('token_invalid', $this->auth->resetPassword($raw, 'a new correct battery staple', 'a new correct battery staple')[0]);
        // New password works.
        [$o] = $this->auth->login('alice@example.com', 'a new correct battery staple');
        assert_same('ok', $o);
    }

    public function testResetPasswordEnforcesMinimumLength(): void
    {
        $repo = new TokenRepository($this->pdo, 'password_reset_tokens');
        $raw = $repo->issue($this->activeUserId, AuthService::RESET_TTL);
        [$outcome, $errors] = $this->auth->resetPassword($raw, 'short', 'short');
        assert_same('invalid', $outcome);
        assert_true(isset($errors['password']));
    }

    /* ─── Change password ─── */

    public function testChangePasswordVerifiesCurrentAndRotatesCookie(): void
    {
        [, $oldCookie] = $this->auth->login('alice@example.com', 'correct horse battery staple');
        [$outcome, $errors, $newCookie] = $this->auth->changePassword(
            $this->activeUserId,
            'correct horse battery staple',
            'a new correct battery staple',
            'a new correct battery staple'
        );
        assert_same('ok', $outcome);
        assert_same([], $errors);
        assert_true(is_string($newCookie) && $newCookie !== $oldCookie);
        assert_same(null, $this->auth->authenticate($oldCookie));
        assert_same($this->activeUserId, $this->auth->authenticate($newCookie));
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        [$outcome, $errors] = $this->auth->changePassword($this->activeUserId, 'wrong', 'a new correct battery staple', 'a new correct battery staple');
        assert_same('invalid', $outcome);
        assert_true(isset($errors['current_password']));
    }

    /* ─── Redirect safety ─── */

    public function testIsSafeRedirect(): void
    {
        assert_true(AuthService::isSafeRedirect('/discover'));
        assert_true(AuthService::isSafeRedirect('/adventure/x'));
        assert_true(!AuthService::isSafeRedirect(null));
        assert_true(!AuthService::isSafeRedirect(''));
        assert_true(!AuthService::isSafeRedirect('https://evil.example.com/'));
        assert_true(!AuthService::isSafeRedirect('//evil.example.com/'));
        assert_true(!AuthService::isSafeRedirect('/\\evil.example.com'));
        assert_true(!AuthService::isSafeRedirect('/javascript:alert(1)'));
        assert_true(!AuthService::isSafeRedirect('http://other/'));
    }

    /* ─── Registration hook queues verify_email ─── */

    public function testOnRegisteredQueuesVerificationEmail(): void
    {
        // Create a fresh pending user and run the hook.
        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $uid = (int) $repo->insert([
            'email' => 'carol@example.com', 'username' => 'carol',
            'display_name' => 'Carol', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'pending_verification',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM email_queue')->fetchColumn();
        $this->auth->onRegistered($uid, 'carol@example.com', 'Carol');
        $rows = $this->pdo->query('SELECT template_key, to_email, data_json FROM email_queue ORDER BY id DESC LIMIT 1')->fetch();
        assert_same('verify_email', $rows['template_key']);
        assert_same('carol@example.com', $rows['to_email']);
        $data = json_decode((string) $rows['data_json'], true);
        assert_true(strpos((string) $data['verify_url'], 'token=') !== false);
    }
}
