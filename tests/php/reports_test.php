<?php
/**
 * tests/php/reports_test.php
 *
 * Version 0.21.0 — focused coverage for reports and content warnings:
 * every target and reason, the honeypot, rate limiting, duplicate
 * detection, reporter privacy, platform-private reports, owner
 * actions, authorization, and the warning list readers consent to.
 */

declare(strict_types=1);

use App\AdventureService;
use App\BranchSubmissionService;
use App\Database;
use App\Migrator;
use App\ModerationService;
use App\PasswordHasher;
use App\ReportService;
use App\UserRepository;

final class BPReportsTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private ReportService $svc;
    private ModerationService $moderation;

    private int $ownerId;
    private int $strangerId;
    private int $adminId;
    private string $slug;
    private int $adventureId;
    private int $startSceneId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-reports-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();

        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $mk = static function (string $name) use ($repo, $hash): int {
            return (int) $repo->insert([
                'email' => $name . '@example.com', 'username' => $name,
                'display_name' => ucfirst($name) . ' Person', 'password_hash' => $hash['hash'],
                'password_algo' => $hash['algo'], 'status' => 'active',
                'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        };
        $this->ownerId    = $mk('rowner');
        $this->strangerId = $mk('rstranger');
        $this->adminId    = $mk('radmin');

        $this->svc        = new ReportService($this->pdo);
        $this->moderation = new ModerationService($this->pdo);

        [$outcome, $e2, $created] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title'          => 'The Salt Road',
            'description'    => 'A caravan crosses a drying sea bed and finds a door.',
            'genre'          => 'fantasy',
            'content_rating' => 'teen',
            'visibility'     => 'public',
            'opening_title'  => 'The first mile',
            'opening_body'   => '<p>The wagons roll onto cracked white ground.</p>',
            'status'             => 'published',
            'contribution_mode'  => 'immediate',
            'max_branches_per_scene' => 4,
        ], '127.0.0.1');
        assert_same(AdventureService::OK, $outcome, json_encode($e2 ?? []));
        $this->slug        = (string) $created['slug'];
        $this->adventureId = (int) $created['id'];

        $s = $this->pdo->prepare('SELECT id FROM scenes WHERE adventure_id = :a AND is_start = 1');
        $s->execute([':a' => $this->adventureId]);
        $this->startSceneId = (int) $s->fetch()['id'];
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    private function makeAdmin(): void
    {
        $this->pdo->prepare(
            "INSERT OR IGNORE INTO user_roles (user_id, role) VALUES (:u, 'admin')"
        )->execute([':u' => $this->adminId]);
    }

    private function extraScene(string $title = 'The salt door'): int
    {
        $this->pdo->prepare(
            "INSERT INTO scenes (adventure_id, slug, title, body, body_plain, scene_type, state, scene_number)
             VALUES (:a, :g, :t, '<p>A door in the salt.</p>', 'A door in the salt.', 'story', 'published', :n)"
        )->execute([
            ':a' => $this->adventureId,
            ':g' => 'scene-' . bin2hex(random_bytes(4)),
            ':t' => $title,
            ':n' => 2 + (int) $this->pdo->query(
                'SELECT COUNT(*) c FROM scenes WHERE adventure_id = ' . $this->adventureId
            )->fetch()['c'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /* ───────────────────────── Reporting ───────────────────────── */

    public function testEveryReasonIsAccepted(): void
    {
        // Each reason from a distinct reporter, so the rate limit and the
        // duplicate rule stay out of the way.
        foreach (ReportService::REASONS as $i => $reason) {
            [$o, $d] = $this->svc->create($this->slug, null, "10.0.0.$i", [
                'target_type' => 'adventure', 'reason' => $reason, 'details' => 'Please look at this.',
            ]);
            assert_same(ReportService::OK, $o, $reason);
            assert_true(is_int($d['report_id']), $reason);
        }
    }

    public function testUnknownReasonIsRejected(): void
    {
        [$o] = $this->svc->create($this->slug, null, '10.1.0.1', [
            'target_type' => 'adventure', 'reason' => 'vibes',
        ]);
        assert_same(ReportService::INVALID, $o);
    }

    public function testSceneChoiceAndSubmissionTargets(): void
    {
        [$o] = $this->svc->create($this->slug, null, '10.2.0.1', [
            'target_type' => 'scene', 'reason' => 'spam', 'scene_id' => $this->startSceneId,
        ]);
        assert_same(ReportService::OK, $o);

        $target = $this->extraScene();
        $this->pdo->prepare(
            "INSERT INTO choices (scene_id, target_scene_id, label, position)
             VALUES (:s, :t, 'Walk to the door', 0)"
        )->execute([':s' => $this->startSceneId, ':t' => $target]);
        $choiceId = (int) $this->pdo->lastInsertId();

        [$o, $d] = $this->svc->create($this->slug, null, '10.2.0.2', [
            'target_type' => 'choice', 'reason' => 'broken', 'choice_id' => $choiceId,
        ]);
        assert_same(ReportService::OK, $o);
        // A choice report records the scene it lives on, for context.
        $row = $this->pdo->query('SELECT * FROM content_reports WHERE id = ' . (int) $d['report_id'])->fetch();
        assert_same($this->startSceneId, (int) $row['scene_id']);

        [$so, , $sub] = (new BranchSubmissionService($this->pdo))->submit(
            $this->slug, (string) $this->startSceneId,
            [
                'choice_text' => 'Turn back to the wagons',
                'scene_title' => 'Back among the wheels',
                'scene_body'  => '<p>The caravan master frowns.</p>',
                'scene_type'  => 'story',
                'attribution' => 'username',
            ],
            $this->strangerId, '10.2.0.3'
        );
        assert_same(BranchSubmissionService::OK, $so);

        [$o] = $this->svc->create($this->slug, null, '10.2.0.4', [
            'target_type' => 'submission', 'reason' => 'harassment',
            'submission_id' => (int) $sub['submission_id'],
        ]);
        assert_same(ReportService::OK, $o);
    }

    public function testTargetFromAnotherAdventureIsNotFound(): void
    {
        [, , $other] = (new AdventureService($this->pdo))->create($this->strangerId, [
            'title' => 'Another Country', 'description' => 'A second story entirely, elsewhere.',
            'genre' => 'fantasy', 'content_rating' => 'teen', 'visibility' => 'public',
            'opening_title' => 'Elsewhere', 'opening_body' => '<p>Elsewhere entirely.</p>',
            'status' => 'published', 'contribution_mode' => 'immediate',
            'max_branches_per_scene' => 4,
        ], '10.3.0.1');
        $s = $this->pdo->prepare('SELECT id FROM scenes WHERE adventure_id = :a AND is_start = 1');
        $s->execute([':a' => (int) $other['id']]);
        $foreignScene = (int) $s->fetch()['id'];

        [$o] = $this->svc->create($this->slug, null, '10.3.0.2', [
            'target_type' => 'scene', 'reason' => 'spam', 'scene_id' => $foreignScene,
        ]);
        assert_same(ReportService::NOT_FOUND, $o);
    }

    public function testHoneypotIsAcceptedAndDiscarded(): void
    {
        [$o, $d] = $this->svc->create($this->slug, null, '10.4.0.1', [
            'target_type' => 'adventure', 'reason' => 'spam', 'website' => 'http://bot.example',
        ]);
        assert_same(ReportService::OK, $o);
        assert_same(null, $d['report_id']);
        assert_same(true, $d['discarded']);
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM content_reports')->fetch()['c']);
    }

    public function testDuplicateReportIsFolded(): void
    {
        $in = ['target_type' => 'scene', 'reason' => 'spam', 'scene_id' => $this->startSceneId];
        [$o, $first] = $this->svc->create($this->slug, $this->strangerId, '10.5.0.1', $in);
        assert_same(ReportService::OK, $o);
        $dbg = $this->pdo->query('SELECT id, reporter_key, target_type, reason, scene_id, created_at FROM content_reports')->fetchAll(\PDO::FETCH_ASSOC);
        [$o2, $again] = $this->svc->create($this->slug, $this->strangerId, '10.5.0.1', $in);
        assert_same(ReportService::DUPLICATE, $o2, json_encode($dbg));
        assert_same(ReportService::DUPLICATE, $o2);
        assert_same($first['report_id'], $again['report_id']);
        assert_same(1, (int) $this->pdo->query('SELECT COUNT(*) c FROM content_reports')->fetch()['c']);

        // A different reason from the same reporter is a new report.
        [$o3] = $this->svc->create($this->slug, $this->strangerId, '10.5.0.1',
            ['target_type' => 'scene', 'reason' => 'broken', 'scene_id' => $this->startSceneId]);
        assert_same(ReportService::OK, $o3);
    }

    public function testRateLimitStopsAFlood(): void
    {
        for ($i = 0; $i < ReportService::RATE_PER_HOUR; $i++) {
            $scene = $this->extraScene('Scene ' . $i);
            [$o] = $this->svc->create($this->slug, null, '10.6.0.1', [
                'target_type' => 'scene', 'reason' => 'spam', 'scene_id' => $scene,
            ]);
            assert_same(ReportService::OK, $o, 'report ' . $i);
        }
        [$o] = $this->svc->create($this->slug, null, '10.6.0.1', [
            'target_type' => 'adventure', 'reason' => 'spam',
        ]);
        assert_same(ReportService::RATE_LIMITED, $o);

        // A different reporter is unaffected.
        [$other] = $this->svc->create($this->slug, null, '10.6.0.2', [
            'target_type' => 'adventure', 'reason' => 'spam',
        ]);
        assert_same(ReportService::OK, $other);
    }

    public function testReportCountNeverRemovesContent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->create($this->slug, null, '10.7.0.' . $i, [
                'target_type' => 'scene', 'reason' => 'hate', 'scene_id' => $this->startSceneId,
            ]);
        }
        $scene = $this->pdo->query('SELECT * FROM scenes WHERE id = ' . $this->startSceneId)->fetch();
        assert_same('published', (string) $scene['state']);
        assert_same(0, (int) $scene['is_locked']);
    }

    /* ───────────────────────── Privacy ─────────────────────────── */

    public function testQueueNeverExposesTheReporterFingerprint(): void
    {
        $this->svc->create($this->slug, null, '198.51.100.9', [
            'target_type' => 'adventure', 'reason' => 'spam', 'details' => 'Bulk links.',
        ]);
        [$o, $d] = $this->svc->queue($this->slug, $this->ownerId, false);
        assert_same(ReportService::OK, $o);
        assert_same(1, count($d['reports']));
        $r = $d['reports'][0];
        assert_same(null, $r['reporter']);
        assert_true(!array_key_exists('reporter_ip', $r), 'ip must not be exposed');
        assert_true(!array_key_exists('reporter_key', $r), 'fingerprint must not be exposed');
    }

    public function testPlatformPrivateReportsAreHiddenFromTheTeam(): void
    {
        $this->makeAdmin();
        [, $d] = $this->svc->create($this->slug, null, '10.8.0.1', [
            'target_type' => 'adventure', 'reason' => 'copyright',
        ]);
        $id = (int) $d['report_id'];

        [$o] = $this->svc->setPlatformPrivate($id, false, true);
        assert_same(ReportService::FORBIDDEN, $o, 'only administrators may hide a report');

        [$o2] = $this->svc->setPlatformPrivate($id, true, true);
        assert_same(ReportService::OK, $o2);

        [, $team] = $this->svc->queue($this->slug, $this->ownerId, false);
        assert_same(0, count($team['reports']));

        [, $admin] = $this->svc->queue($this->slug, $this->adminId, true);
        assert_same(1, count($admin['reports']));
        assert_same(true, $admin['reports'][0]['platform_private']);

        // The team cannot act on it either.
        [$ao] = $this->svc->act($this->slug, $id, $this->ownerId, false, 'dismiss');
        assert_same(ReportService::FORBIDDEN, $ao);
    }

    /* ─────────────────────── Authorization ─────────────────────── */

    public function testStrangersCannotSeeOrActOnTheQueue(): void
    {
        $this->svc->create($this->slug, null, '10.9.0.1', ['target_type' => 'adventure', 'reason' => 'spam']);
        [$o] = $this->svc->queue($this->slug, $this->strangerId, false);
        assert_same(ReportService::FORBIDDEN, $o);
        [$o2] = $this->svc->queue($this->slug, null, false);
        assert_same(ReportService::FORBIDDEN, $o2);
    }

    public function testReviewersMayReadButNotAct(): void
    {
        $this->pdo->prepare(
            "INSERT INTO adventure_collaborators (adventure_id, user_id, role)
             VALUES (:a, :u, 'reviewer')"
        )->execute([':a' => $this->adventureId, ':u' => $this->strangerId]);

        [, $d] = $this->svc->create($this->slug, null, '10.10.0.1', ['target_type' => 'adventure', 'reason' => 'spam']);
        [$o, $q] = $this->svc->queue($this->slug, $this->strangerId, false);
        assert_same(ReportService::OK, $o);
        assert_same(false, $q['can_act']);

        [$ao] = $this->svc->act($this->slug, (int) $d['report_id'], $this->strangerId, false, 'dismiss');
        assert_same(ReportService::FORBIDDEN, $ao);
    }

    /* ────────────────────── Owner handling ─────────────────────── */

    public function testDismissHideLockAndEscalate(): void
    {
        $scene = $this->extraScene();

        $mk = function (string $reason, ?int $sceneId = null, string $ip = '10.11.0.1'): int {
            [$o, $d] = $this->svc->create($this->slug, null, $ip, [
                'target_type' => $sceneId === null ? 'adventure' : 'scene',
                'reason' => $reason, 'scene_id' => $sceneId,
            ]);
            assert_same(ReportService::OK, $o);
            return (int) $d['report_id'];
        };

        $dismissId  = $mk('spam');
        $hideId     = $mk('hate', $scene, '10.11.0.2');
        $lockId     = $mk('broken', $scene, '10.11.0.3');
        $escalateId = $mk('copyright', null, '10.11.0.4');

        [$o] = $this->svc->act($this->slug, $dismissId, $this->ownerId, false, 'dismiss', 'Not a problem.');
        assert_same(ReportService::OK, $o);

        [$o] = $this->svc->act($this->slug, $hideId, $this->ownerId, false, 'hide_scene');
        assert_same(ReportService::OK, $o);
        assert_same('hidden', (string) $this->pdo->query('SELECT state FROM scenes WHERE id = ' . $scene)->fetch()['state']);

        [$o] = $this->svc->act($this->slug, $lockId, $this->ownerId, false, 'lock_scene');
        assert_same(ReportService::OK, $o);
        assert_same(1, (int) $this->pdo->query('SELECT is_locked FROM scenes WHERE id = ' . $scene)->fetch()['is_locked']);

        [$o, $d] = $this->svc->act($this->slug, $escalateId, $this->ownerId, false, 'escalate', 'Legal claim.');
        assert_same(ReportService::OK, $o);
        assert_same('escalated', $d['state']);

        [, $esc] = $this->svc->queue($this->slug, $this->ownerId, false, 'escalated');
        assert_same(1, count($esc['reports']));
        assert_same('escalate', $esc['reports'][0]['action_taken']);
    }

    public function testAReportCanOnlyBeHandledOnce(): void
    {
        [, $d] = $this->svc->create($this->slug, null, '10.12.0.1', ['target_type' => 'adventure', 'reason' => 'spam']);
        $id = (int) $d['report_id'];
        [$first] = $this->svc->act($this->slug, $id, $this->ownerId, false, 'dismiss');
        assert_same(ReportService::OK, $first);
        [$second] = $this->svc->act($this->slug, $id, $this->ownerId, false, 'escalate');
        assert_same(ReportService::CONFLICT, $second);
    }

    public function testTheOpeningSceneIsNeverHidden(): void
    {
        [, $d] = $this->svc->create($this->slug, null, '10.13.0.1', [
            'target_type' => 'scene', 'reason' => 'hate', 'scene_id' => $this->startSceneId,
        ]);
        [$o, $info] = $this->svc->act($this->slug, (int) $d['report_id'], $this->ownerId, false, 'hide_scene');
        assert_same(ReportService::CONFLICT, $o);
        assert_same('opening_scene', $info['reason']);
        // The failed action leaves the report open for another decision.
        [, $q] = $this->svc->queue($this->slug, $this->ownerId, false);
        assert_same(1, count($q['reports']));
    }

    /* ────────────────────── Content warnings ───────────────────── */

    public function testOwnerSetsWarningsAndReadersSeeThem(): void
    {
        [$o, $d] = $this->svc->setWarnings($this->slug, $this->ownerId, false, [
            ['code' => 'violence'],
            ['code' => 'self_harm', 'details' => 'One scene depicts self-harm.'],
            ['code' => 'other', 'details' => 'Depictions of drowning.'],
        ]);
        assert_same(ReportService::OK, $o);
        assert_same(3, count($d['warnings']));
        assert_same('Violence', $d['warnings'][0]['label']);
        assert_same('One scene depicts self-harm.', $d['warnings'][1]['details']);

        $public = (new \App\PublicRepository($this->pdo))->adventureBySlug($this->slug);
        assert_same(3, count($public['contentWarningDetails']));
        assert_same('violence', $public['contentWarningDetails'][0]['code']);
    }

    public function testWarningValidationAndAuthorization(): void
    {
        [$bad] = $this->svc->setWarnings($this->slug, $this->ownerId, false, [['code' => 'aliens']]);
        assert_same(ReportService::INVALID, $bad);

        [$needsDetail] = $this->svc->setWarnings($this->slug, $this->ownerId, false, [['code' => 'other']]);
        assert_same(ReportService::INVALID, $needsDetail);

        [$forbidden] = $this->svc->setWarnings($this->slug, $this->strangerId, false, [['code' => 'horror']]);
        assert_same(ReportService::FORBIDDEN, $forbidden);

        // Duplicated codes collapse to one entry.
        [$o, $d] = $this->svc->setWarnings($this->slug, $this->ownerId, false, [
            ['code' => 'horror'], ['code' => 'horror', 'details' => 'ignored'],
        ]);
        assert_same(ReportService::OK, $o);
        assert_same(1, count($d['warnings']));
    }

    public function testAllSevenWarningCodesRoundTrip(): void
    {
        $items = [];
        foreach (ReportService::WARNING_CODES as $code) {
            $items[] = ['code' => $code, 'details' => $code === 'other' ? 'Flashing imagery.' : ''];
        }
        [$o, $d] = $this->svc->setWarnings($this->slug, $this->ownerId, false, $items);
        assert_same(ReportService::OK, $o);
        assert_same(count(ReportService::WARNING_CODES), count($d['warnings']));
    }

    public function testWarningTextCannotCarryMarkup(): void
    {
        [$o, $d] = $this->svc->setWarnings($this->slug, $this->ownerId, false, [
            ['code' => 'horror', 'label' => '<b>Horror</b>', 'details' => '<script>alert(1)</script>Ghosts.'],
        ]);
        assert_same(ReportService::OK, $o);
        assert_true(strpos($d['warnings'][0]['label'], '<') === false, 'label must be plain text');
        assert_true(strpos((string) $d['warnings'][0]['details'], '<') === false, 'details must be plain text');
    }
}
