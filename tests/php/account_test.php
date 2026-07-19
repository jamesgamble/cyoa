<?php
/**
 * tests/php/account_test.php
 *
 * Version 0.14.0 — focused coverage for the account dashboard.
 * Exercises AccountService end-to-end against a fresh SQLite database.
 */

declare(strict_types=1);

use App\AccountService;
use App\AuthService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\SessionRepository;
use App\TokenRepository;
use App\UserRepository;

final class BPAccountTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private AccountService $svc;
    private AuthService $auth;
    private int $userId;
    private int $otherUserId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-account-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $this->userId = (int) $repo->insert([
            'email' => 'alice@example.com', 'username' => 'alice',
            'display_name' => 'Alice', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->otherUserId = (int) $repo->insert([
            'email' => 'bob@example.com', 'username' => 'bob',
            'display_name' => 'Bob', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->svc  = new AccountService($this->pdo);
        $this->auth = new AuthService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    public function testProfileReturnsExpectedShape(): void
    {
        $p = $this->svc->profile($this->userId);
        assert_true($p !== null);
        assert_same('alice@example.com', $p['email']);
        assert_same('Alice', $p['display_name']);
        assert_true($p['public_profile'] === true);
        assert_true($p['notify_updates'] === false);
    }

    public function testUpdateProfileRejectsEmptyDisplayName(): void
    {
        [$outcome, $errors] = $this->svc->updateProfile($this->userId, [
            'display_name' => '', 'bio' => '', 'public_profile' => true,
        ]);
        assert_same(AccountService::INVALID, $outcome);
        assert_true(isset($errors['display_name']));
    }

    public function testUpdateProfileAcceptsValidInput(): void
    {
        [$outcome] = $this->svc->updateProfile($this->userId, [
            'display_name' => 'Alicia', 'bio' => 'A tale-teller.',
            'public_profile' => false,
        ]);
        assert_same(AccountService::OK, $outcome);
        $p = $this->svc->profile($this->userId);
        assert_same('Alicia', $p['display_name']);
        assert_same('A tale-teller.', $p['bio']);
        assert_true($p['public_profile'] === false);
    }

    public function testNotificationPreferencesRoundTrip(): void
    {
        $this->svc->updateNotifications($this->userId, [
            'notify_replies' => false,
            'notify_moderation' => true,
            'notify_updates' => true,
        ]);
        $p = $this->svc->profile($this->userId);
        assert_true($p['notify_replies'] === false);
        assert_true($p['notify_moderation'] === true);
        assert_true($p['notify_updates'] === true);
    }

    public function testRequestEmailChangeRejectsMalformedAddress(): void
    {
        [$outcome] = $this->svc->requestEmailChange($this->userId, 'not-an-email');
        assert_same(AccountService::INVALID, $outcome);
    }

    public function testRequestEmailChangeRejectsAddressInUse(): void
    {
        [$outcome] = $this->svc->requestEmailChange($this->userId, 'bob@example.com');
        assert_same(AccountService::EMAIL_IN_USE, $outcome);
    }

    public function testEmailChangeIsTwoStep(): void
    {
        // Sniff the raw token by intercepting the write: request it, then
        // grab the row and reproduce the pre-hash by cross-checking with
        // the queued email's placeholders.
        [$outcome] = $this->svc->requestEmailChange($this->userId, 'alice2@example.com');
        assert_same(AccountService::OK, $outcome);
        // Email must not have moved yet.
        $p = $this->svc->profile($this->userId);
        assert_same('alice@example.com', $p['email']);

        // Pull the raw token from the queued verification email.
        $stmt = $this->pdo->query(
            "SELECT payload FROM email_queue
              WHERE template_key = 'email_change_verify'
              ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        assert_true($row !== false);
        $payload = json_decode((string) $row['payload'], true);
        $url = (string) $payload['verify_url'];
        $qs = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($qs, $q);
        $raw = (string) ($q['email_change_token'] ?? '');
        assert_true($raw !== '', 'token missing from verify URL');

        [$outcome2] = $this->svc->confirmEmailChange($this->userId, $raw);
        assert_same(AccountService::OK, $outcome2);
        $p2 = $this->svc->profile($this->userId);
        assert_same('alice2@example.com', $p2['email']);

        // Token is single-use.
        [$outcome3] = $this->svc->confirmEmailChange($this->userId, $raw);
        assert_same(AccountService::TOKEN_INVALID, $outcome3);
    }

    public function testConfirmEmailChangeRejectsForeignUser(): void
    {
        $this->svc->requestEmailChange($this->userId, 'alice3@example.com');
        $stmt = $this->pdo->query(
            "SELECT payload FROM email_queue WHERE template_key='email_change_verify' ORDER BY id DESC LIMIT 1"
        );
        $payload = json_decode((string) $stmt->fetch()['payload'], true);
        parse_str((string) parse_url((string) $payload['verify_url'], PHP_URL_QUERY), $q);
        $raw = (string) $q['email_change_token'];
        // Bob tries to consume Alice's token.
        [$outcome] = $this->svc->confirmEmailChange($this->otherUserId, $raw);
        assert_same(AccountService::TOKEN_INVALID, $outcome);
    }

    public function testRevokeOtherSessionsKeepsCurrent(): void
    {
        $sessions = new SessionRepository($this->pdo);
        $a = $sessions->create($this->userId);
        $b = $sessions->create($this->userId);
        $c = $sessions->create($this->userId);
        // Keep session b live.
        $revoked = $this->svc->revokeOtherSessions($this->userId, $b['id']);
        assert_same(2, $revoked);
        $active = $this->svc->listSessions($this->userId, $b['id']);
        assert_same(1, count($active));
        assert_same($b['id'], $active[0]['id']);
        assert_true($active[0]['is_current']);
        // Sanity: the other cookies really refuse authentication.
        assert_same(null, $sessions->authenticate($a['id'] . '.' . $a['raw']));
        assert_same(null, $sessions->authenticate($c['id'] . '.' . $c['raw']));
    }

    public function testImportLocalProgressIsIdempotent(): void
    {
        // Seed an adventure + a scene.
        $this->pdo->exec("INSERT INTO adventures (slug, title, author_id, state, visibility)
                          VALUES ('sample','Sample Tale',{$this->userId},'published','public')");
        $advId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scenes (adventure_id, slug, scene_number, title, body, state, is_start)
                          VALUES ($advId, 'crossroads', 1, 'Crossroads', 'x', 'published', 1)");

        $entries = [[
            'slug' => 'sample',
            'bookmarks' => ['crossroads'],
            'history'   => ['crossroads', 'nowhere'],
        ]];
        $c = $this->svc->importLocalProgress($this->userId, $entries);
        assert_same(1, $c['imported_bookmarks']);
        assert_same(1, $c['imported_history']);
        assert_same(1, $c['skipped']); // 'nowhere' is unknown

        // Re-import is idempotent.
        $c2 = $this->svc->importLocalProgress($this->userId, $entries);
        assert_same(0, $c2['imported_bookmarks']);
        assert_same(0, $c2['imported_history']);

        $bm = $this->svc->listBookmarks($this->userId);
        assert_same(1, count($bm));
        assert_same('sample', $bm[0]['adventure_slug']);
    }

    public function testMyAdventuresListsOnlyOwn(): void
    {
        $this->pdo->exec("INSERT INTO adventures (slug, title, author_id, state, visibility)
                          VALUES ('mine','Mine',{$this->userId},'draft','public')");
        $this->pdo->exec("INSERT INTO adventures (slug, title, author_id, state, visibility)
                          VALUES ('theirs','Theirs',{$this->otherUserId},'draft','public')");
        $mine = $this->svc->myAdventures($this->userId);
        assert_same(1, count($mine));
        assert_same('mine', $mine[0]['slug']);
    }

    public function testSessionIdFromCookieParsesLeadingId(): void
    {
        assert_same(42, AccountService::sessionIdFromCookie('42.abcdef'));
        assert_same(0,  AccountService::sessionIdFromCookie(''));
        assert_same(0,  AccountService::sessionIdFromCookie(null));
        assert_same(0,  AccountService::sessionIdFromCookie('nope.token'));
    }
}
