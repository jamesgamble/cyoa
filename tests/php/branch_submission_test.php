<?php
/**
 * tests/php/branch_submission_test.php
 *
 * Version 0.18.0 — focused coverage for branch submissions:
 * contribution modes, passcodes, rate limits, sanitisation,
 * attribution, validation, duplicates, and concurrency.
 */

declare(strict_types=1);

use App\AdventureService;
use App\BranchSubmissionService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\SettingsRepository;
use App\UserRepository;

final class BPBranchSubmissionTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private BranchSubmissionService $svc;
    private int $ownerId;
    private int $contributorId;
    private int $blockedId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-branch-' . bin2hex(random_bytes(6)) . '.sqlite';
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
        $this->ownerId       = $mk('owner');
        $this->contributorId = $mk('contributor');
        $this->blockedId     = $mk('blocked');

        $this->svc = new BranchSubmissionService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /**
     * Create a published adventure with a published opening scene.
     *
     * @param array<string,mixed> $over
     * @return array{slug:string,id:int,scene:string}
     */
    private function makeAdventure(array $over = []): array
    {
        static $n = 0;
        $n++;
        [$outcome, $errors, $adv] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title' => 'The Lantern Road ' . $n,
            'description' => 'A winter journey through a hollow kingdom.',
            'genre' => 'fantasy', 'content_rating' => 'teen',
            'content_warnings' => [], 'visibility' => 'public',
            'opening_title' => 'The gate at dusk ' . $n,
            'opening_body' => '<p>Snow gathers on the <strong>iron gate</strong>.</p>',
            'status' => 'published',
            'contribution_mode' => 'immediate',
            'anonymous_contributions' => false,
            'max_branches_per_scene' => 4,
        ] + $over);
        assert_same(AdventureService::OK, $outcome, 'fixture: ' . json_encode($errors));
        return [
            'slug'  => (string) $adv['slug'],
            'id'    => (int) $adv['id'],
            'scene' => (string) $adv['opening_scene']['slug'],
        ];
    }

    /** @return array<string,mixed> */
    private function payload(array $over = []): array
    {
        return $over + [
            'choice_text' => 'Follow the lantern north',
            'scene_title' => 'The frozen mile',
            'scene_body'  => '<p>The road narrows into <em>white silence</em>.</p>',
            'scene_type'  => 'story',
            'attribution' => 'username',
        ];
    }

    /* ───────── Modes ───────── */

    public function testImmediateModePublishesTransactionally(): void
    {
        $a = $this->makeAdventure();
        [$out, , $data] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1'
        );
        assert_same(BranchSubmissionService::OK, $out);
        assert_same('published', $data['state']);
        assert_true($data['published']);
        assert_true($data['scene_id'] !== null, 'a scene was created');
        assert_true($data['choice_id'] !== null, 'a choice was created');

        $scene = $this->pdo->query('SELECT * FROM scenes WHERE id = ' . (int) $data['scene_id'])->fetch();
        assert_same('published', (string) $scene['state']);
        assert_same(0, (int) $scene['is_start']);

        $choice = $this->pdo->query('SELECT * FROM choices WHERE id = ' . (int) $data['choice_id'])->fetch();
        assert_same((int) $data['scene_id'], (int) $choice['target_scene_id']);
        assert_same('Follow the lantern north', (string) $choice['label']);
    }

    public function testApprovalModeCreatesPendingSubmissionOnly(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $before = (int) $this->pdo->query('SELECT COUNT(*) c FROM scenes')->fetch()['c'];
        [$out, , $data] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1'
        );
        assert_same(BranchSubmissionService::OK, $out);
        assert_same('pending', $data['state']);
        assert_true(!$data['published']);
        assert_same(null, $data['scene_id']);
        $after = (int) $this->pdo->query('SELECT COUNT(*) c FROM scenes')->fetch()['c'];
        assert_same($before, $after, 'approval mode creates no scene');
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM choices')->fetch()['c']);
        assert_same(1, count($this->svc->pendingFor($a['id'])));
    }

    public function testClosedModeRejects(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'closed']);
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1'
        );
        assert_same(BranchSubmissionService::CLOSED, $out);
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM branch_submissions')->fetch()['c']);
    }

    public function testUnknownAdventureIsNotFound(): void
    {
        [$out] = $this->svc->submit('no-such-story', 'x', $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::NOT_FOUND, $out);
    }

    public function testDraftAdventureIsUnavailable(): void
    {
        $a = $this->makeAdventure(['status' => 'draft']);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::UNAVAILABLE, $out);
    }

    public function testArchivedAdventureIsUnavailable(): void
    {
        $a = $this->makeAdventure();
        $this->pdo->exec("UPDATE adventures SET state='archived' WHERE id=" . $a['id']);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::UNAVAILABLE, $out);
    }

    public function testCompleteAdventureAcceptsNoNewBranches(): void
    {
        $a = $this->makeAdventure();
        $this->pdo->exec("UPDATE adventures SET state='complete' WHERE id=" . $a['id']);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::CLOSED, $out);
    }

    /* ───────── Source scene ───────── */

    public function testUnpublishedSourceSceneIsRejected(): void
    {
        $a = $this->makeAdventure();
        $this->pdo->exec("UPDATE scenes SET state='draft' WHERE adventure_id=" . $a['id']);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::SOURCE_UNAVAILABLE, $out);
    }

    public function testLockedSceneIsRejected(): void
    {
        $a = $this->makeAdventure();
        $this->pdo->exec('UPDATE scenes SET is_locked=1 WHERE adventure_id=' . $a['id']);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::SCENE_LOCKED, $out);
    }

    public function testUnknownSceneIsNotFound(): void
    {
        $a = $this->makeAdventure();
        [$out] = $this->svc->submit($a['slug'], 'nowhere', $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::NOT_FOUND, $out);
    }

    /* ───────── Branch limit ───────── */

    public function testBranchLimitIsEnforced(): void
    {
        $a = $this->makeAdventure(['max_branches_per_scene' => 2]);
        foreach (['Go north', 'Go south'] as $i => $label) {
            [$out] = $this->svc->submit(
                $a['slug'], $a['scene'],
                $this->payload(['choice_text' => $label, 'scene_title' => 'Scene ' . $i]),
                $this->contributorId, '10.0.0.' . $i
            );
            assert_same(BranchSubmissionService::OK, $out, $label);
        }
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'],
            $this->payload(['choice_text' => 'Go east', 'scene_title' => 'Third']),
            $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::BRANCH_LIMIT, $out);
    }

    public function testPendingSubmissionsConsumeBranchSlots(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval', 'max_branches_per_scene' => 2]);
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(['choice_text' => 'Go north']), $this->contributorId, '1.1.1.1');
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(['choice_text' => 'Go south']), $this->contributorId, '1.1.1.2');
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['choice_text' => 'Go east']), $this->contributorId, '1.1.1.3'
        );
        assert_same(BranchSubmissionService::BRANCH_LIMIT, $out);
    }

    /* ───────── Blocking ───────── */

    public function testBlockedUserIsRejected(): void
    {
        $a = $this->makeAdventure();
        $this->pdo->prepare('INSERT INTO contribution_blocks (adventure_id, user_id) VALUES (:a,:u)')
            ->execute([':a' => $a['id'], ':u' => $this->blockedId]);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->blockedId, '10.0.0.1');
        assert_same(BranchSubmissionService::BLOCKED, $out);
    }

    public function testBlockedIpIsRejected(): void
    {
        $a = $this->makeAdventure(['anonymous_contributions' => true]);
        $this->pdo->prepare('INSERT INTO contribution_blocks (adventure_id, ip) VALUES (:a,:ip)')
            ->execute([':a' => $a['id'], ':ip' => '203.0.113.7']);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), null, '203.0.113.7');
        assert_same(BranchSubmissionService::BLOCKED, $out);
    }

    /* ───────── Passcode ───────── */

    public function testPasscodeIsRequiredWhenConfigured(): void
    {
        $a = $this->makeAdventure(['contribution_passcode' => 'winter-gate']);
        [$out, $fields] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId);
        assert_same(BranchSubmissionService::PASSCODE_REQUIRED, $out);
        assert_same('required', $fields['passcode']);
    }

    public function testWrongPasscodeIsRejected(): void
    {
        $a = $this->makeAdventure(['contribution_passcode' => 'winter-gate']);
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['passcode' => 'summer-gate']), $this->contributorId
        );
        assert_same(BranchSubmissionService::PASSCODE_INVALID, $out);
    }

    public function testCorrectPasscodeIsAccepted(): void
    {
        $a = $this->makeAdventure(['contribution_passcode' => 'winter-gate']);
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['passcode' => 'winter-gate']), $this->contributorId
        );
        assert_same(BranchSubmissionService::OK, $out);
    }

    public function testPasscodeIsNeverReturnedInContext(): void
    {
        $a = $this->makeAdventure(['contribution_passcode' => 'winter-gate']);
        [, $ctx] = $this->svc->context($a['slug'], $a['scene'], $this->contributorId, '');
        assert_true($ctx['requires_passcode'], 'context announces the requirement');
        assert_true(strpos(json_encode($ctx), 'winter-gate') === false, 'passcode never leaves the server');
    }

    /* ───────── Rate limits ───────── */

    public function testPerUserRateLimit(): void
    {
        (new SettingsRepository($this->pdo))->set('contributions_per_user_per_hour', '2');
        (new SettingsRepository($this->pdo))->set('contributions_per_ip_per_hour', '99');
        $a = $this->makeAdventure(['max_branches_per_scene' => 10]);
        for ($i = 0; $i < 2; $i++) {
            [$out] = $this->svc->submit(
                $a['slug'], $a['scene'],
                $this->payload(['choice_text' => 'Path ' . $i, 'scene_title' => 'Scene ' . $i]),
                $this->contributorId, '10.0.0.' . $i
            );
            assert_same(BranchSubmissionService::OK, $out);
        }
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'],
            $this->payload(['choice_text' => 'Path three', 'scene_title' => 'Scene three']),
            $this->contributorId, '10.0.0.55'
        );
        assert_same(BranchSubmissionService::RATE_LIMITED, $out);
    }

    public function testPerIpRateLimitAppliesToAnonymousVisitors(): void
    {
        (new SettingsRepository($this->pdo))->set('contributions_per_ip_per_hour', '1');
        $a = $this->makeAdventure(['anonymous_contributions' => true, 'max_branches_per_scene' => 10]);
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['choice_text' => 'First path']), null, '198.51.100.4'
        );
        assert_same(BranchSubmissionService::OK, $out);
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'],
            $this->payload(['choice_text' => 'Second path', 'scene_title' => 'Another']),
            null, '198.51.100.4'
        );
        assert_same(BranchSubmissionService::RATE_LIMITED, $out);
    }

    public function testRejectedSubmissionsDoNotConsumeRateBudget(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'closed']);
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        $usage = $this->svc->rateUsage($this->contributorId, '10.0.0.1');
        assert_same(0, $usage['user']);
        assert_same(0, $usage['ip']);
    }

    /* ───────── Honeypot ───────── */

    public function testHoneypotStopsTheSubmission(): void
    {
        $a = $this->makeAdventure();
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['website' => 'http://spam.example']),
            $this->contributorId, '10.0.0.1'
        );
        assert_same(BranchSubmissionService::HONEYPOT, $out);
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM branch_submissions')->fetch()['c']);
    }

    /* ───────── Validation ───────── */

    public function testShortChoiceTextIsInvalid(): void
    {
        $a = $this->makeAdventure();
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['choice_text' => 'no']), $this->contributorId
        );
        assert_same(BranchSubmissionService::INVALID, $out);
        assert_same('invalid', $fields['choice_text']);
    }

    public function testOverlongSceneTitleIsInvalid(): void
    {
        $a = $this->makeAdventure();
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['scene_title' => str_repeat('a', 200)]),
            $this->contributorId
        );
        assert_same(BranchSubmissionService::INVALID, $out);
        assert_same('invalid', $fields['scene_title']);
    }

    public function testEmptyBodyIsInvalid(): void
    {
        $a = $this->makeAdventure();
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['scene_body' => '<p>   </p>']),
            $this->contributorId
        );
        assert_same(BranchSubmissionService::INVALID, $out);
        assert_same('invalid', $fields['scene_body']);
    }

    public function testOverlongPrivateNoteIsInvalid(): void
    {
        $a = $this->makeAdventure();
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['private_note' => str_repeat('n', 1200)]),
            $this->contributorId
        );
        assert_same(BranchSubmissionService::INVALID, $out);
        assert_same('too_long', $fields['private_note']);
    }

    public function testEndingSceneTypeIsStored(): void
    {
        $a = $this->makeAdventure();
        [$out, , $data] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['scene_type' => 'ending']),
            $this->contributorId, '10.0.0.1'
        );
        assert_same(BranchSubmissionService::OK, $out);
        $scene = $this->pdo->query('SELECT scene_type FROM scenes WHERE id=' . (int) $data['scene_id'])->fetch();
        assert_same('ending', (string) $scene['scene_type']);
    }

    public function testUnknownSceneTypeIsInvalid(): void
    {
        $a = $this->makeAdventure();
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['scene_type' => 'cliffhanger']),
            $this->contributorId
        );
        assert_same(BranchSubmissionService::INVALID, $out);
        assert_same('invalid', $fields['scene_type']);
    }

    /* ───────── Sanitisation ───────── */

    public function testScriptsAndStylesAreStripped(): void
    {
        $a = $this->makeAdventure();
        [$out, , $data] = $this->svc->submit($a['slug'], $a['scene'], $this->payload([
            'scene_body' => '<p style="color:red">Ink</p><script>alert(1)</script>'
                . '<img src="x"><a href="http://evil.example">link</a>',
        ]), $this->contributorId, '10.0.0.1');
        assert_same(BranchSubmissionService::OK, $out);
        $body = (string) $this->pdo->query('SELECT body FROM scenes WHERE id=' . (int) $data['scene_id'])->fetch()['body'];
        assert_true(strpos($body, '<script') === false, 'no script');
        assert_true(strpos($body, 'style=') === false, 'no inline style');
        assert_true(strpos($body, '<img') === false, 'no image');
        assert_true(strpos($body, 'href') === false, 'no link destination');
        assert_true(strpos($body, 'Ink') !== false, 'text survives');
    }

    public function testPlainTextProjectionIsDerived(): void
    {
        $a = $this->makeAdventure();
        [, , $data] = $this->svc->submit($a['slug'], $a['scene'], $this->payload([
            'scene_body' => '<p>The <strong>road</strong> narrows.</p>',
        ]), $this->contributorId, '10.0.0.1');
        $row = $this->pdo->query('SELECT body_plain FROM scenes WHERE id=' . (int) $data['scene_id'])->fetch();
        assert_true(strpos((string) $row['body_plain'], 'The road narrows.') !== false);
        assert_true(strpos((string) $row['body_plain'], '<strong>') === false);
    }

    public function testChoiceTextIsStoredAsPlainText(): void
    {
        $a = $this->makeAdventure();
        [$out, , $data] = $this->svc->submit($a['slug'], $a['scene'], $this->payload([
            'choice_text' => '<b>Take the lantern</b>',
        ]), $this->contributorId, '10.0.0.1');
        assert_same(BranchSubmissionService::OK, $out);
        $label = (string) $this->pdo->query('SELECT label FROM choices WHERE id=' . (int) $data['choice_id'])->fetch()['label'];
        assert_true(strpos($label, '<b>') === false, 'markup is never rendered from a choice label');
    }

    /* ───────── Attribution ───────── */

    public function testUsernameAttribution(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(['attribution' => 'username']), $this->contributorId);
        $row = $this->svc->pendingFor($a['id'])[0];
        assert_same('contributor', $row['public_attribution']);
    }

    public function testDisplayNameAttribution(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(['attribution' => 'display_name']), $this->contributorId);
        $row = $this->svc->pendingFor($a['id'])[0];
        assert_same('Contributor Person', $row['public_attribution']);
    }

    public function testAnonymousAttributionKeepsInternalAttribution(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['attribution' => 'anonymous']),
            $this->contributorId, '192.0.2.44'
        );
        $row = $this->svc->pendingFor($a['id'])[0];
        assert_same('Anonymous', $row['public_attribution']);
        assert_same($this->contributorId, $row['internal_user_id']);
        assert_same('contributor', $row['internal_username']);
        assert_same('192.0.2.44', $row['internal_ip']);
    }

    public function testAnonymousVisitorCannotClaimAUsername(): void
    {
        $a = $this->makeAdventure(['anonymous_contributions' => true, 'contribution_mode' => 'approval']);
        $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['attribution' => 'username']), null, '198.51.100.9'
        );
        $row = $this->svc->pendingFor($a['id'])[0];
        assert_same('Anonymous', $row['public_attribution']);
        assert_same(null, $row['internal_user_id']);
    }

    public function testAnonymousVisitorRejectedWhenNotAllowed(): void
    {
        $a = $this->makeAdventure(['anonymous_contributions' => false]);
        [$out] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), null, '198.51.100.9');
        assert_same(BranchSubmissionService::UNAUTHENTICATED, $out);
    }

    public function testInvalidAttributionIsRejected(): void
    {
        $a = $this->makeAdventure();
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['attribution' => 'owner']), $this->contributorId
        );
        assert_same(BranchSubmissionService::INVALID, $out);
        assert_same('invalid', $fields['attribution']);
    }

    public function testPrivateNoteIsNotPublic(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['private_note' => 'I am new here.']),
            $this->contributorId
        );
        [, $ctx] = $this->svc->context($a['slug'], $a['scene'], null, '');
        assert_true(strpos(json_encode($ctx), 'I am new here.') === false);
    }

    /* ───────── Duplicates ───────── */

    public function testObviousDuplicateIsRejected(): void
    {
        $a = $this->makeAdventure();
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        [$out, $fields] = $this->svc->submit(
            $a['slug'], $a['scene'],
            $this->payload(['choice_text' => '  follow the LANTERN, north!  ', 'scene_title' => 'Elsewhere']),
            $this->contributorId, '10.0.0.2'
        );
        assert_same(BranchSubmissionService::DUPLICATE, $out);
        assert_same('duplicate', $fields['choice_text']);
    }

    public function testDuplicateDetectionIsPerScene(): void
    {
        $a = $this->makeAdventure();
        [, , $first] = $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        // Same wording, different source scene → allowed.
        [$out] = $this->svc->submit(
            $a['slug'], (string) $first['scene_id'], $this->payload(['scene_title' => 'Further on']),
            $this->contributorId, '10.0.0.2'
        );
        assert_same(BranchSubmissionService::OK, $out);
    }

    public function testDistinctWordingIsNotADuplicate(): void
    {
        $a = $this->makeAdventure();
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        [$out] = $this->svc->submit(
            $a['slug'], $a['scene'],
            $this->payload(['choice_text' => 'Turn back toward the town', 'scene_title' => 'The town']),
            $this->contributorId, '10.0.0.2'
        );
        assert_same(BranchSubmissionService::OK, $out);
    }

    /* ───────── Context ───────── */

    public function testContextDescribesAnOpenScene(): void
    {
        $a = $this->makeAdventure();
        [$out, $ctx] = $this->svc->context($a['slug'], $a['scene'], $this->contributorId, '10.0.0.1');
        assert_same(BranchSubmissionService::OK, $out);
        assert_true($ctx['contributions_enabled']);
        assert_true($ctx['can_submit']);
        assert_same('immediate', $ctx['contribution_mode']);
        assert_same(4, $ctx['branch_limit']['limit']);
        assert_same(4, $ctx['branch_limit']['remaining']);
        assert_same(['username', 'display_name', 'anonymous'], $ctx['attribution_options']);
    }

    public function testContextOffersOnlyAnonymousToSignedOutVisitors(): void
    {
        $a = $this->makeAdventure(['anonymous_contributions' => true]);
        [, $ctx] = $this->svc->context($a['slug'], $a['scene'], null, '10.0.0.1');
        assert_same(['anonymous'], $ctx['attribution_options']);
        assert_true($ctx['can_submit']);
    }

    public function testContextRefusesSignedOutVisitorsWhenAnonymousIsOff(): void
    {
        $a = $this->makeAdventure();
        [, $ctx] = $this->svc->context($a['slug'], $a['scene'], null, '10.0.0.1');
        assert_true(!$ctx['can_submit']);
    }

    public function testContextReportsClosedContributions(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'closed']);
        [, $ctx] = $this->svc->context($a['slug'], $a['scene'], $this->contributorId, '');
        assert_true(!$ctx['contributions_enabled']);
        assert_same('closed', $ctx['contribution_mode']);
    }

    /* ───────── History ───────── */

    public function testContributionHistoryListsOwnSubmissions(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        $rows = $this->svc->historyForUser($this->contributorId);
        assert_same(1, count($rows));
        assert_same('pending', $rows[0]['state']);
        assert_same('Follow the lantern north', $rows[0]['choice_text']);
        assert_same($a['slug'], $rows[0]['adventure_slug']);
        assert_same(0, count($this->svc->historyForUser($this->ownerId)));
    }

    public function testAnonymousPreferenceStillShowsInOwnHistory(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit(
            $a['slug'], $a['scene'], $this->payload(['attribution' => 'anonymous']),
            $this->contributorId, '10.0.0.1'
        );
        $rows = $this->svc->historyForUser($this->contributorId);
        assert_same(1, count($rows));
        assert_same('anonymous', $rows[0]['attribution']);
    }

    /* ───────── Concurrency ───────── */

    public function testConcurrentSubmissionsSerialiseUnderTheWriteLock(): void
    {
        $a = $this->makeAdventure(['max_branches_per_scene' => 2]);
        $lock = new \App\WriteLock();
        $svc  = $this->svc;
        $results = [];
        foreach (['Go north', 'Go south', 'Go east'] as $i => $label) {
            $results[] = $lock->withLock(static function () use ($svc, $a, $label, $i) {
                [$out] = $svc->submit($a['slug'], $a['scene'], [
                    'choice_text' => $label, 'scene_title' => 'Scene ' . $i,
                    'scene_body'  => '<p>Onward.</p>', 'scene_type' => 'story',
                    'attribution' => 'username',
                ], 1, '10.0.0.' . $i);
                return $out;
            });
        }
        assert_same(
            [BranchSubmissionService::OK, BranchSubmissionService::OK, BranchSubmissionService::BRANCH_LIMIT],
            $results,
            'the limit holds when submissions arrive back to back'
        );
        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) c FROM choices WHERE scene_id = (SELECT id FROM scenes WHERE adventure_id='
            . $a['id'] . ' AND is_start=1)'
        )->fetch()['c'];
        assert_same(2, $count);
    }

    public function testFailedTransactionLeavesNoOrphanScene(): void
    {
        $a = $this->makeAdventure();
        $before = (int) $this->pdo->query('SELECT COUNT(*) c FROM scenes')->fetch()['c'];
        // Force the choice insert to fail mid-transaction.
        $this->pdo->exec('DROP TABLE choices');
        $threw = false;
        try {
            $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        } catch (\Throwable $e) {
            $threw = true;
        }
        assert_true($threw, 'the failure propagates');
        assert_true(!$this->pdo->inTransaction(), 'the transaction is rolled back');
        $after = (int) $this->pdo->query('SELECT COUNT(*) c FROM scenes')->fetch()['c'];
        assert_same($before, $after, 'no orphan scene survives');
        assert_same(0, (int) $this->pdo->query('SELECT COUNT(*) c FROM branch_submissions')->fetch()['c']);
    }

    /* ───────── Activity ───────── */

    public function testSubmissionsAreRecordedInActivity(): void
    {
        $a = $this->makeAdventure();
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        $row = $this->pdo->query(
            'SELECT action, to_state FROM adventure_activity WHERE adventure_id=' . $a['id'] . ' ORDER BY id DESC LIMIT 1'
        )->fetch();
        assert_same('branch_published', (string) $row['action']);
        assert_same('published', (string) $row['to_state']);
    }

    public function testPendingSubmissionsAreRecordedInActivity(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'approval']);
        $this->svc->submit($a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.1');
        $row = $this->pdo->query(
            'SELECT action FROM adventure_activity WHERE adventure_id=' . $a['id'] . ' ORDER BY id DESC LIMIT 1'
        )->fetch();
        assert_same('branch_submitted', (string) $row['action']);
    }
}
