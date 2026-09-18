<?php
/**
 * tests/php/notifications_test.php
 *
 * Version 0.22.0 — focused coverage for in-site notifications, email
 * preferences, follows, the follow/bookmark separation, and digest
 * aggregation.
 */

declare(strict_types=1);

use App\AccountService;
use App\AdventureService;
use App\Database;
use App\Migrator;
use App\NotificationService;
use App\PasswordHasher;
use App\UserRepository;

final class BPNotificationsTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private NotificationService $svc;

    private int $ownerId;
    private int $readerId;
    private int $otherId;
    private int $adventureId = 0;
    private string $slug = '';

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-notif-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();

        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $mk = static fn (string $n): int => (int) $repo->insert([
            'email' => $n . '@example.com', 'username' => $n,
            'display_name' => ucfirst($n), 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->ownerId  = $mk('nowner');
        $this->readerId = $mk('nreader');
        $this->otherId  = $mk('nother');

        [$outcome, $errors, $data] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title' => 'The Tidewatch Light',
            'description' => 'A keeper counts the ships that never arrive.',
            'genre' => 'mystery', 'content_rating' => 'everyone',
            'content_warnings' => [], 'visibility' => 'public',
            'opening_title' => 'The lamp room',
            'opening_body'  => '<p>The glass is cold.</p>',
            'status' => 'published',
            'contribution_mode' => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene' => 4,
        ]);
        assert_same('ok', $outcome, 'fixture adventure: ' . json_encode($errors));
        $this->slug        = (string) $data['slug'];
        $this->adventureId = (int) $data['id'];
        $this->svc = new NotificationService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /* ─────────────────────── Notifications ──────────────────────── */

    public function testEveryDocumentedKindReachesTheInbox(): void
    {
        $kinds = [
            'submission_received', 'submission_approved', 'submission_rejected',
            'changes_requested', 'submission_resubmitted', 'review_needed',
            'collaborator_invitation', 'ownership_transfer',
            'followed_adventure_updated', 'account_security',
        ];
        foreach ($kinds as $k) {
            $this->svc->emit($this->readerId, $k, 'Title for ' . $k, 'Body.', '/account', null);
        }
        $inbox = $this->svc->inbox($this->readerId);
        assert_same(count($kinds), count($inbox), 'one row per kind');
        assert_same(count($kinds), $this->svc->unreadCount($this->readerId), 'all unread');
        foreach ($inbox as $row) {
            assert_true(($row['label'] ?? '') !== '', 'each row carries a readable label');
        }
    }

    public function testUnknownKindIsRejected(): void
    {
        assert_throws(
            fn () => $this->svc->emit($this->readerId, 'not_a_kind', 'x', 'y', null, null),
            'unknown notification kind'
        );
    }

    public function testAUserIsNeverNotifiedAboutTheirOwnAction(): void
    {
        $this->svc->emitMany(
            [$this->ownerId, $this->readerId],
            'submission_received', 'A branch arrived', 'Body.', '/manage', $this->adventureId,
            ['actor_id' => $this->readerId]
        );
        assert_same(1, $this->svc->unreadCount($this->ownerId), 'the team hears about it');
        assert_same(0, $this->svc->unreadCount($this->readerId), 'the actor does not');
    }

    public function testMarkOneReadAndMarkAllRead(): void
    {
        foreach (['submission_received', 'review_needed', 'changes_requested'] as $k) {
            $this->svc->emit($this->readerId, $k, 'T', 'B', null, null);
        }
        $inbox = $this->svc->inbox($this->readerId);
        $this->svc->markRead($this->readerId, (int) $inbox[0]['id']);
        assert_same(2, $this->svc->unreadCount($this->readerId), 'one marked read');

        $this->svc->markRead($this->readerId, null);
        assert_same(0, $this->svc->unreadCount($this->readerId), 'all marked read');
    }

    public function testOneUserCannotReadOrDeleteAnothersNotifications(): void
    {
        $this->svc->emit($this->readerId, 'submission_received', 'T', 'B', null, null);
        $id = (int) $this->svc->inbox($this->readerId)[0]['id'];

        $this->svc->markRead($this->otherId, $id);
        assert_same(1, $this->svc->unreadCount($this->readerId), 'a stranger cannot mark it read');
        assert_same(NotificationService::NOT_FOUND, $this->svc->delete($this->otherId, $id));
        assert_same(1, count($this->svc->inbox($this->readerId)), 'the row survives');
    }

    public function testRoutineItemsDeleteAndSecurityNoticesDoNot(): void
    {
        $this->svc->emit($this->readerId, 'submission_received', 'Routine', 'B', null, null);
        $this->svc->emit($this->readerId, 'account_security', 'Security', 'B', null, null);

        $rows = $this->svc->inbox($this->readerId);
        $routine  = null;
        $security = null;
        foreach ($rows as $r) {
            if ((string) $r['kind'] === 'account_security') $security = (int) $r['id'];
            else $routine = (int) $r['id'];
        }

        assert_same(NotificationService::OK, $this->svc->delete($this->readerId, (int) $routine));
        assert_same(NotificationService::FORBIDDEN, $this->svc->delete($this->readerId, (int) $security));
        assert_same(1, count($this->svc->inbox($this->readerId)), 'only the security notice remains');
    }

    public function testDeleteReadOnlyRemovesReadRoutineItems(): void
    {
        $this->svc->emit($this->readerId, 'submission_received', 'One', 'B', null, null);
        $this->svc->emit($this->readerId, 'review_needed', 'Two', 'B', null, null);
        $this->svc->emit($this->readerId, 'account_security', 'Three', 'B', null, null);
        $this->svc->markRead($this->readerId, null);

        assert_same(2, $this->svc->deleteRead($this->readerId), 'both routine rows go');
        $left = $this->svc->inbox($this->readerId);
        assert_same(1, count($left));
        assert_same('account_security', (string) $left[0]['kind']);
    }

    /* ─────────────────────── Preferences ────────────────────────── */

    public function testPreferencesCoverEveryKindAndDefaultSensibly(): void
    {
        $prefs = $this->svc->preferences($this->readerId);
        assert_same(count(NotificationService::KINDS), count($prefs), 'one row per kind');
        foreach ($prefs as $p) {
            assert_true(is_bool($p['email']), 'email is a boolean');
            assert_true(is_bool($p['locked']), 'locked is a boolean');
        }
    }

    public function testEmailPreferencesCanBeTurnedOffAndBackOn(): void
    {
        assert_true($this->svc->emailEnabled($this->readerId, 'submission_received'), 'on by default');
        $this->svc->updatePreferences($this->readerId, ['submission_received' => false]);
        assert_true(!$this->svc->emailEnabled($this->readerId, 'submission_received'), 'switched off');
        $this->svc->updatePreferences($this->readerId, ['submission_received' => true]);
        assert_true($this->svc->emailEnabled($this->readerId, 'submission_received'), 'switched on');
    }

    public function testSecurityAndRecoveryEmailCannotBeDisabled(): void
    {
        $this->svc->updatePreferences($this->readerId, ['account_security' => false]);
        assert_true($this->svc->emailEnabled($this->readerId, 'account_security'), 'still enabled');

        foreach ($this->svc->preferences($this->readerId) as $p) {
            if ($p['kind'] === 'account_security') {
                assert_true((bool) $p['locked'], 'reported as locked');
                assert_true((bool) $p['email'], 'reported as on');
            }
        }
    }

    public function testDisabledEmailStillReachesTheInbox(): void
    {
        $this->svc->updatePreferences($this->readerId, ['submission_received' => false]);
        $this->svc->emit($this->readerId, 'submission_received', 'T', 'B', null, null);
        assert_same(1, $this->svc->unreadCount($this->readerId), 'in-site delivery is unconditional');

        $c = $this->pdo->query('SELECT COUNT(*) AS c FROM email_queue')->fetch();
        assert_same(0, (int) $c['c'], 'no email was queued');
    }

    public function testLockedKindQueuesEmailEvenWithoutAPreferenceRow(): void
    {
        $this->svc->emit($this->readerId, 'account_security', 'Sign-in', 'B', null, null);
        $c = $this->pdo->query('SELECT COUNT(*) AS c FROM email_queue')->fetch();
        assert_true((int) $c['c'] >= 1, 'security mail is always queued');
    }

    /* ───────────────────────── Follows ──────────────────────────── */

    public function testFollowAndUnfollowAreIdempotent(): void
    {
        [$o] = $this->svc->follow($this->slug, $this->readerId);
        assert_same(NotificationService::OK, $o);
        $this->svc->follow($this->slug, $this->readerId);
        assert_true($this->svc->isFollowing($this->adventureId, $this->readerId), 'following');
        assert_same(1, count($this->svc->following($this->readerId)), 'stored exactly once');

        $this->svc->unfollow($this->slug, $this->readerId);
        $this->svc->unfollow($this->slug, $this->readerId);
        assert_true(!$this->svc->isFollowing($this->adventureId, $this->readerId), 'unfollowed');
        assert_same(0, count($this->svc->following($this->readerId)), 'list is empty');
    }

    public function testFollowingAnUnknownAdventureIsNotFound(): void
    {
        [$o] = $this->svc->follow('no-such-adventure', $this->readerId);
        assert_same(NotificationService::NOT_FOUND, $o);
    }

    public function testFollowersHearAboutUpdatesAndTheActorDoesNot(): void
    {
        $this->svc->follow($this->slug, $this->readerId);
        $this->svc->follow($this->slug, $this->otherId);

        $this->svc->announceUpdate(
            $this->adventureId, 'New branch', 'A branch was published.',
            '/adventure/' . $this->slug, $this->otherId
        );
        assert_same(1, $this->svc->unreadCount($this->readerId), 'the follower is told');
        assert_same(0, $this->svc->unreadCount($this->otherId), 'the actor is not');
        assert_same(0, $this->svc->unreadCount($this->ownerId), 'a non-follower is not');
    }

    public function testFollowerCountsAreNeverReturned(): void
    {
        $this->svc->follow($this->slug, $this->readerId);
        $payload = json_encode([
            'following'  => $this->svc->following($this->readerId),
            'isFollowing'=> $this->svc->isFollowing($this->adventureId, $this->readerId),
        ]);
        assert_true(strpos((string) $payload, 'follower') === false, 'no follower key');
        assert_true(strpos((string) $payload, 'count') === false, 'no count key');
    }

    /* ──────────────── Follows are not bookmarks ─────────────────── */

    public function testABookmarkIsNotAFollow(): void
    {
        $account = new AccountService($this->pdo);
        $account->importLocalProgress($this->readerId, [
            ['slug' => $this->slug, 'bookmarks' => ['the-lamp-room'], 'history' => []],
        ]);

        assert_true(count($account->bookmarks($this->readerId)) > 0, 'the bookmark was stored');
        assert_true(
            !$this->svc->isFollowing($this->adventureId, $this->readerId),
            'bookmarking never subscribes'
        );
    }

    public function testAFollowIsNotABookmark(): void
    {
        $this->svc->follow($this->slug, $this->readerId);
        $account = new AccountService($this->pdo);
        assert_same(0, count($account->bookmarks($this->readerId)), 'following stores no position');
    }

    /* ───────────────────── Digest aggregation ───────────────────── */

    public function testRoutineFollowedUpdatesAreAggregatedNotEmailedOneByOne(): void
    {
        $this->svc->follow($this->slug, $this->readerId);
        for ($i = 0; $i < 3; $i++) {
            $this->svc->announceUpdate(
                $this->adventureId, 'Branch ' . $i, 'A branch was published.',
                '/adventure/' . $this->slug, $this->ownerId
            );
        }
        assert_same(3, $this->svc->unreadCount($this->readerId), 'three inbox rows');
        $c = $this->pdo->query('SELECT COUNT(*) AS c FROM email_queue')->fetch();
        assert_same(0, (int) $c['c'], 'nothing emailed yet');
        assert_same(3, $this->svc->pendingDigestCount(), 'three entries are waiting');

        $sent = $this->svc->flushDigests();
        assert_same(1, $sent, 'one email for the whole batch');
        $c2 = $this->pdo->query('SELECT COUNT(*) AS c FROM email_queue')->fetch();
        assert_same(1, (int) $c2['c'], 'exactly one queued message');
    }

    public function testFlushingTwiceNeverSendsTheSameUpdateAgain(): void
    {
        $this->svc->follow($this->slug, $this->readerId);
        $this->svc->announceUpdate(
            $this->adventureId, 'Branch', 'B.', '/adventure/' . $this->slug, $this->ownerId
        );
        assert_same(1, $this->svc->flushDigests());
        assert_same(0, $this->svc->pendingDigestCount(), 'queue drained');
        assert_same(0, $this->svc->flushDigests(), 'second flush sends nothing');
        $c = $this->pdo->query('SELECT COUNT(*) AS c FROM email_queue')->fetch();
        assert_same(1, (int) $c['c'], 'still one message');
    }

    public function testAFollowerWhoDisabledUpdateEmailGetsNoDigest(): void
    {
        $this->svc->follow($this->slug, $this->readerId);
        $this->svc->updatePreferences($this->readerId, ['followed_adventure_updated' => false]);
        $this->svc->announceUpdate(
            $this->adventureId, 'Branch', 'B.', '/adventure/' . $this->slug, $this->ownerId
        );
        assert_same(1, $this->svc->unreadCount($this->readerId), 'the inbox still shows it');
        assert_same(0, $this->svc->flushDigests(), 'no digest email');
    }
}
