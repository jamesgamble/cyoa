<?php
/**
 * tests/php/adventure_creation_test.php
 *
 * Version 0.16.0 — focused coverage for adventure creation:
 * validation, templates, sanitization, authorization, limits,
 * single-transaction commit, and rollback.
 */

declare(strict_types=1);

use App\AdventureService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\SettingsRepository;
use App\UserRepository;

final class BPAdventureCreationTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private AdventureService $svc;
    private int $userId;
    private int $suspendedId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-adv-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $this->userId = (int) $repo->insert([
            'email' => 'author@example.com', 'username' => 'author',
            'display_name' => 'Author', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->suspendedId = (int) $repo->insert([
            'email' => 'banned@example.com', 'username' => 'banned',
            'display_name' => 'Banned', 'password_hash' => $hash['hash'],
            'password_algo' => $hash['algo'], 'status' => 'suspended',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->svc = new AdventureService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /** @return array<string,mixed> */
    private function payload(array $over = []): array
    {
        return $over + [
            'title'                   => 'The Lantern Road',
            'description'             => 'A winter journey through a hollow kingdom.',
            'genre'                   => 'fantasy',
            'content_rating'          => 'teen',
            'content_warnings'        => ['Peril', 'Cold'],
            'visibility'              => 'public',
            'opening_title'           => 'The gate at dusk',
            'opening_body'            => '<p>Snow gathers on the <strong>iron gate</strong>.</p>',
            'status'                  => 'published',
            'contribution_mode'       => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene'  => 4,
            'contribution_passcode'   => '',
            'writing_guidelines'      => '<p>Keep it <em>brief</em>.</p>',
        ];
    }

    /* ── Creation ─────────────────────────────────────────────── */

    public function testCreateWritesAdventureAndOpeningScene(): void
    {
        [$outcome, $errors, $adv] = $this->svc->create($this->userId, $this->payload(), '127.0.0.1');
        assert_same(AdventureService::OK, $outcome, json_encode($errors));
        assert_same('the-lantern-road', $adv['slug']);

        $row = $this->pdo->query('SELECT * FROM adventures WHERE id = ' . (int) $adv['id'])->fetch();
        assert_same($this->userId, (int) $row['author_id']);
        assert_same('published', (string) $row['state']);
        assert_same('approval', (string) $row['contribution_state']);
        assert_same(4, (int) $row['max_branches_per_scene']);

        $scene = $this->pdo->query('SELECT * FROM scenes WHERE adventure_id = ' . (int) $adv['id'])->fetch();
        assert_same(1, (int) $scene['is_start']);
        assert_same('published', (string) $scene['state']);
        assert_same(1, (int) $scene['scene_number']);
        assert_true(str_contains((string) $scene['body_plain'], 'iron gate'));

        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) c FROM content_warnings WHERE adventure_id = ' . (int) $adv['id']
        )->fetch()['c'];
        assert_same(2, $count);
    }

    public function testDraftAdventureKeepsOpeningSceneUnpublished(): void
    {
        [, , $adv] = $this->svc->create($this->userId, $this->payload(['status' => 'draft']));
        $scene = $this->pdo->query('SELECT state FROM scenes WHERE adventure_id = ' . (int) $adv['id'])->fetch();
        assert_same('draft', (string) $scene['state']);
    }

    public function testSlugsAreUnique(): void
    {
        [, , $a] = $this->svc->create($this->userId, $this->payload());
        [, , $b] = $this->svc->create($this->userId, $this->payload());
        assert_same('the-lantern-road', $a['slug']);
        assert_same('the-lantern-road-2', $b['slug']);
    }

    public function testCreatorBecomesOwnerEvenIfBodySuppliesAuthor(): void
    {
        [, , $adv] = $this->svc->create(
            $this->userId,
            $this->payload(['author_id' => $this->suspendedId, 'owner_id' => 999])
        );
        $row = $this->pdo->query('SELECT author_id FROM adventures WHERE id = ' . (int) $adv['id'])->fetch();
        assert_same($this->userId, (int) $row['author_id']);
    }

    /* ── Authorization ────────────────────────────────────────── */

    public function testSuspendedUserCannotCreate(): void
    {
        [$outcome] = $this->svc->create($this->suspendedId, $this->payload());
        assert_same(AdventureService::FORBIDDEN, $outcome);
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM adventures')->fetch()['c']);
    }

    public function testUnknownUserCannotCreate(): void
    {
        [$outcome] = $this->svc->create(99999, $this->payload());
        assert_same(AdventureService::FORBIDDEN, $outcome);
    }

    /* ── Validation ───────────────────────────────────────────── */

    public function testShortTitleIsRejected(): void
    {
        [$outcome, $errors] = $this->svc->create($this->userId, $this->payload(['title' => 'ab']));
        assert_same(AdventureService::INVALID, $outcome);
        assert_true(isset($errors['title']));
    }

    public function testUnknownGenreAndRatingAreRejected(): void
    {
        [, $errors] = $this->svc->create($this->userId, $this->payload([
            'genre' => 'westerns', 'content_rating' => 'adults',
        ]));
        assert_true(isset($errors['genre']) && isset($errors['content_rating']));
    }

    public function testEmptyOpeningBodyIsRejected(): void
    {
        [, $errors] = $this->svc->create($this->userId, $this->payload(['opening_body' => '<p>   </p>']));
        assert_true(isset($errors['opening_body']));
    }

    public function testBranchLimitIsBounded(): void
    {
        [, $errors] = $this->svc->create($this->userId, $this->payload(['max_branches_per_scene' => 99]));
        assert_true(isset($errors['max_branches_per_scene']));
    }

    public function testShortPasscodeIsRejected(): void
    {
        [, $errors] = $this->svc->create($this->userId, $this->payload(['contribution_passcode' => '123']));
        assert_true(isset($errors['contribution_passcode']));
    }

    public function testInvalidContributionModeIsRejected(): void
    {
        [, $errors] = $this->svc->create($this->userId, $this->payload(['contribution_mode' => 'anything']));
        assert_true(isset($errors['contribution_mode']));
    }

    /* ── Sanitization ─────────────────────────────────────────── */

    public function testOpeningBodyIsSanitizedAndPlainTextDerived(): void
    {
        [$outcome, , $adv] = $this->svc->create($this->userId, $this->payload([
            'opening_body' =>
                '<p onclick="x()">Hello <script>alert(1)</script>'
                . '<a href="https://evil.test">world</a> '
                . '<img src="x"><em style="color:red">now</em></p>',
        ]));
        assert_same(AdventureService::OK, $outcome);
        $scene = $this->pdo->query('SELECT body, body_plain FROM scenes WHERE adventure_id = ' . (int) $adv['id'])->fetch();
        $body = (string) $scene['body'];
        assert_true(!str_contains($body, 'script'));
        assert_true(!str_contains($body, 'onclick'));
        assert_true(!str_contains($body, '<a'));
        assert_true(!str_contains($body, '<img'));
        assert_true(!str_contains($body, 'style'));
        assert_true(str_contains($body, '<em>now</em>'));
        assert_true(str_contains((string) $scene['body_plain'], 'world'));
    }

    public function testGuidelinesAreSanitizedAndStoredWithPlainText(): void
    {
        [, , $adv] = $this->svc->create($this->userId, $this->payload([
            'writing_guidelines' => '<h2>Rules</h2><table><tr><td>no</td></tr></table>',
        ]));
        $row = $this->pdo->query(
            'SELECT writing_guidelines, writing_guidelines_plain FROM adventures WHERE id = ' . (int) $adv['id']
        )->fetch();
        assert_true(!str_contains((string) $row['writing_guidelines'], '<table'));
        assert_true(str_contains((string) $row['writing_guidelines_plain'], 'Rules'));
    }

    public function testPasscodeIsStoredHashed(): void
    {
        [, , $adv] = $this->svc->create($this->userId, $this->payload([
            'contribution_passcode' => 'lantern-road',
        ]));
        $hash = (string) $this->pdo->query(
            'SELECT contribution_passcode_hash FROM adventures WHERE id = ' . (int) $adv['id']
        )->fetch()['contribution_passcode_hash'];
        assert_true($hash !== '' && $hash !== 'lantern-road');
        assert_true(PasswordHasher::verify('lantern-road', $hash));
    }

    /* ── Templates ────────────────────────────────────────────── */

    public function testTemplatesConfigureSettingsOnly(): void
    {
        $input = AdventureService::applyTemplate(['template' => 'open-community']);
        assert_same('immediate', $input['contribution_mode']);
        assert_same(true, $input['anonymous_contributions']);
        assert_same('public', $input['visibility']);

        $private = AdventureService::applyTemplate(['template' => 'private-group']);
        assert_same('unlisted', $private['visibility']);
        assert_same('approval', $private['contribution_mode']);

        $solo = AdventureService::applyTemplate(['template' => 'solo']);
        assert_same('closed', $solo['contribution_mode']);
    }

    public function testExplicitChoicesOverrideTemplate(): void
    {
        $input = AdventureService::applyTemplate([
            'template' => 'solo', 'contribution_mode' => 'immediate',
        ]);
        assert_same('immediate', $input['contribution_mode']);
    }

    public function testTemplateDoesNotSkipValidation(): void
    {
        [$outcome, $errors] = $this->svc->create($this->userId, [
            'template' => 'open-community', 'title' => '', 'opening_body' => '',
        ]);
        assert_same(AdventureService::INVALID, $outcome);
        assert_true(isset($errors['title']) && isset($errors['opening_body']));
    }

    public function testUnknownTemplateIsRejected(): void
    {
        [, $errors] = $this->svc->create($this->userId, $this->payload(['template' => 'nope']));
        assert_true(isset($errors['template']));
    }

    public function testTemplateKeyIsPersisted(): void
    {
        [, , $adv] = $this->svc->create($this->userId, $this->payload(['template' => 'moderated-community']));
        $row = $this->pdo->query('SELECT template_key FROM adventures WHERE id = ' . (int) $adv['id'])->fetch();
        assert_same('moderated-community', (string) $row['template_key']);
    }

    /* ── Limits ───────────────────────────────────────────────── */

    public function testPerHourRateLimitBlocksCreation(): void
    {
        (new SettingsRepository($this->pdo))->set('adventures_per_user_per_hour', '2');
        $this->svc->create($this->userId, $this->payload());
        $this->svc->create($this->userId, $this->payload());
        [$outcome] = $this->svc->create($this->userId, $this->payload());
        assert_same(AdventureService::RATE_LIMITED, $outcome);
        assert_same(2, (int) $this->pdo->query('SELECT COUNT(*) c FROM adventures')->fetch()['c']);
    }

    public function testMaximumAdventuresPerUserIsConfigurable(): void
    {
        $settings = new SettingsRepository($this->pdo);
        $settings->set('max_adventures_per_user', '1');
        $settings->set('adventures_per_user_per_hour', '10');
        $this->svc->create($this->userId, $this->payload());
        [$outcome] = $this->svc->create($this->userId, $this->payload());
        assert_same(AdventureService::LIMIT_REACHED, $outcome);
    }

    /* ── Transaction / rollback ───────────────────────────────── */

    public function testFailedSceneInsertRollsBackTheAdventure(): void
    {
        // Break the scenes table mid-flight so the second statement of
        // the transaction throws; the adventure row must not survive.
        $this->pdo->exec('DROP TABLE scenes');
        $threw = false;
        try {
            $this->svc->create($this->userId, $this->payload());
        } catch (\Throwable $e) {
            $threw = true;
        }
        assert_true($threw, 'expected the broken insert to throw');
        assert_true(!$this->pdo->inTransaction(), 'transaction must be rolled back');
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM adventures')->fetch()['c']);
        assert_same(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) c FROM adventure_creation_attempts')->fetch()['c']
        );
    }

    public function testCreationIsRecordedForRateLimiting(): void
    {
        $this->svc->create($this->userId, $this->payload(), '10.0.0.7');
        $row = $this->pdo->query('SELECT * FROM adventure_creation_attempts')->fetch();
        assert_same($this->userId, (int) $row['user_id']);
        assert_same('10.0.0.7', (string) $row['ip']);
    }

    public function testCreationLimitsReportRemainingBudget(): void
    {
        $limits = $this->svc->creationLimits($this->userId);
        assert_same(0, $limits['owned']);
        $this->svc->create($this->userId, $this->payload());
        $after = $this->svc->creationLimits($this->userId);
        assert_same(1, $after['owned']);
        assert_same(1, $after['recent']);
    }
}
