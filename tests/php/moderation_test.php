<?php
/**
 * tests/php/moderation_test.php
 *
 * Version 0.19.0 — focused coverage for moderation and owner
 * controls: role derivation, submission decisions and their race
 * protection, reviewer notes, contributor edits and withdrawals,
 * story management, owner settings, per-user permissions, and
 * content reports.
 */

declare(strict_types=1);

use App\AdventureService;
use App\BranchSubmissionService;
use App\Database;
use App\Migrator;
use App\ModerationService;
use App\PasswordHasher;
use App\UserRepository;

final class BPModerationTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private ModerationService $svc;
    private BranchSubmissionService $branches;

    private int $ownerId;
    private int $editorId;
    private int $reviewerId;
    private int $contributorId;
    private int $strangerId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-moderation-' . bin2hex(random_bytes(6)) . '.sqlite';
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
        $this->editorId      = $mk('editor');
        $this->reviewerId    = $mk('reviewer');
        $this->contributorId = $mk('contributor');
        $this->strangerId    = $mk('stranger');

        $this->svc      = new ModerationService($this->pdo);
        $this->branches = new BranchSubmissionService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /**
     * A published adventure in approval mode, with the editor and
     * reviewer already on the roster.
     *
     * @return array{slug:string,id:int,scene:string}
     */
    private function makeAdventure(array $over = []): array
    {
        static $n = 0;
        $n++;
        [$outcome, $errors, $adv] = (new AdventureService($this->pdo))->create($this->ownerId, $over + [
            'title' => 'The Salt Archive ' . $n,
            'description' => 'A drowned library keeps its own counsel.',
            'genre' => 'mystery', 'content_rating' => 'teen',
            'content_warnings' => [], 'visibility' => 'public',
            'opening_title' => 'The reading room ' . $n,
            'opening_body' => '<p>Water laps at the <strong>lowest shelf</strong>.</p>',
            'status' => 'published',
            'contribution_mode' => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene' => 4,
        ]);
        assert_same(AdventureService::OK, $outcome, 'fixture: ' . json_encode($errors));

        foreach ([[$this->editorId, 'editor'], [$this->reviewerId, 'reviewer']] as [$uid, $role]) {
            $this->pdo->prepare(
                'INSERT INTO adventure_collaborators (adventure_id, user_id, role) VALUES (:a,:u,:r)'
            )->execute([':a' => (int) $adv['id'], ':u' => $uid, ':r' => $role]);
        }

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
            'choice_text' => 'Wade towards the index',
            'scene_title' => 'The index drawer',
            'scene_body'  => '<p>Cards swell with <em>brackish ink</em>.</p>',
            'scene_type'  => 'story',
            'attribution' => 'username',
        ];
    }

    /** Submit a pending branch and return its id. */
    private function pending(array $a, array $over = [], ?int $userId = null): int
    {
        [$out, , $data] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload($over), $userId ?? $this->contributorId, '10.0.0.7'
        );
        assert_same(BranchSubmissionService::OK, $out, 'fixture submission');
        assert_same('pending', $data['state']);
        return (int) $data['submission_id'];
    }

    /* ───────── Roles ───────── */

    public function testRolesAreDerivedServerSide(): void
    {
        $a = $this->makeAdventure();
        assert_same('owner', $this->svc->roleFor($a['id'], $this->ownerId));
        assert_same('editor', $this->svc->roleFor($a['id'], $this->editorId));
        assert_same('reviewer', $this->svc->roleFor($a['id'], $this->reviewerId));
        assert_same(null, $this->svc->roleFor($a['id'], $this->strangerId));
        assert_same('administrator', $this->svc->roleFor($a['id'], $this->strangerId, true));
    }

    public function testCapabilitiesFollowTheRole(): void
    {
        $owner    = $this->svc->capabilities('owner', 'published');
        $reviewer = $this->svc->capabilities('reviewer', 'published');
        assert_true($owner['decide'], 'owner may decide');
        assert_true($owner['configure'], 'owner may configure');
        assert_true(!$reviewer['decide'], 'reviewer may not decide');
        assert_true(!$reviewer['configure'], 'reviewer may not configure');
        assert_true($reviewer['review'], 'reviewer may review');
    }

    public function testStrangerCannotOpenAnySection(): void
    {
        $a = $this->makeAdventure();
        foreach (['overview', 'story', 'permissions'] as $section) {
            [$o] = $this->svc->{$section}($a['slug'], $this->strangerId, false);
            assert_same(ModerationService::FORBIDDEN, $o, $section);
        }
        [$o] = $this->svc->submissions($a['slug'], $this->strangerId, false, 'pending');
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    public function testOverviewReportsEverySubmissionTab(): void
    {
        $a = $this->makeAdventure();
        $this->pending($a);
        [$o, $d] = $this->svc->overview($a['slug'], $this->ownerId, false);
        assert_same(ModerationService::OK, $o);
        assert_same(1, $d['counts']['pending']);
        foreach (ModerationService::STATES as $state) {
            assert_true(array_key_exists($state, $d['counts']), 'tab ' . $state);
        }
        assert_same(ModerationService::SECTIONS, $d['sections']);
    }

    /* ───────── Decisions ───────── */

    public function testApprovalPublishesSceneAndChoiceTransactionally(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);

        [$o, $d] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::OK, $o);
        assert_same('approved', $d['state']);
        assert_true($d['scene_id'] !== null && $d['choice_id'] !== null);

        $scene = $this->pdo->query('SELECT * FROM scenes WHERE id=' . (int) $d['scene_id'])->fetch();
        assert_same('published', (string) $scene['state']);
        $choice = $this->pdo->query('SELECT * FROM choices WHERE id=' . (int) $d['choice_id'])->fetch();
        assert_same((int) $d['scene_id'], (int) $choice['target_scene_id']);
    }

    public function testDoubleApprovalIsRejected(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$first] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::OK, $first);
        [$second] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::CONFLICT, $second);
        assert_same(1, (int) $this->pdo->query(
            'SELECT COUNT(*) c FROM choices WHERE scene_id=(SELECT id FROM scenes WHERE is_start=1 AND adventure_id='
            . $a['id'] . ')'
        )->fetch()['c']);
    }

    public function testApprovalAfterRejectionIsRejected(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'reject', ['feedback' => 'Off-tone.']);
        assert_same(ModerationService::OK, $o);
        [$again] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::CONFLICT, $again);
    }

    public function testApprovalAfterWithdrawalIsRejected(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->withdraw($id, $this->contributorId);
        assert_same(ModerationService::OK, $o);
        [$again] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::CONFLICT, $again);
    }

    public function testBranchLimitCannotBeBypassedByApproval(): void
    {
        $a = $this->makeAdventure(['max_branches_per_scene' => 2]);
        $one = $this->pending($a, ['choice_text' => 'Take the first stair']);
        $two = $this->pending($a, ['choice_text' => 'Take the second stair']);
        [$o1] = $this->svc->decide($a['slug'], $one, $this->ownerId, false, 'approve');
        [$o2] = $this->svc->decide($a['slug'], $two, $this->ownerId, false, 'approve');
        assert_same(ModerationService::OK, $o1);
        assert_same(ModerationService::OK, $o2);

        // A third submission cannot even be created; force one in and
        // check the approval path refuses it as well.
        $this->pdo->prepare(
            "INSERT INTO branch_submissions
               (adventure_id, source_scene_id, user_id, choice_text, choice_text_key,
                scene_title, scene_body, scene_body_plain, scene_type, attribution, state)
             VALUES (:a,(SELECT id FROM scenes WHERE adventure_id=:a AND is_start=1),:u,
                     'Third stair','third stair','Third','<p>x</p>','x','story','username','pending')"
        )->execute([':a' => $a['id'], ':u' => $this->contributorId]);
        $third = (int) $this->pdo->lastInsertId();
        [$o3] = $this->svc->decide($a['slug'], $third, $this->ownerId, false, 'approve');
        assert_same(ModerationService::LIMIT, $o3);
    }

    public function testRejectionRequiresFeedback(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o, $d] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'reject');
        assert_same(ModerationService::INVALID, $o);
        assert_same('required', $d['feedback']);
    }

    public function testRequestChangesStoresFeedbackAndKeepsSubmissionEditable(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->decide(
            $a['slug'], $id, $this->editorId, false, 'request_changes',
            ['feedback' => 'Please shorten the opening paragraph.']
        );
        assert_same(ModerationService::OK, $o);
        $row = $this->pdo->query('SELECT * FROM branch_submissions WHERE id=' . $id)->fetch();
        assert_same('changes_requested', (string) $row['state']);
        assert_same('Please shorten the opening paragraph.', (string) $row['feedback']);
    }

    public function testEditAndApproveStoresTheEditedContent(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o, $d] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'edit_approve', [
            'choice_text' => 'Wade towards the catalogue',
            'scene_title' => 'The catalogue drawer',
            'scene_body'  => '<p>Cards swell, then <script>alert(1)</script>settle.</p>',
            'scene_type'  => 'story',
        ]);
        assert_same(ModerationService::OK, $o);
        $scene = $this->pdo->query('SELECT * FROM scenes WHERE id=' . (int) $d['scene_id'])->fetch();
        assert_same('The catalogue drawer', (string) $scene['title']);
        assert_true(!str_contains((string) $scene['body'], '<script'), 'edited body is sanitised');
        $row = $this->pdo->query('SELECT * FROM branch_submissions WHERE id=' . $id)->fetch();
        assert_same($this->ownerId, (int) $row['edited_by']);
    }

    public function testReviewerCannotDecide(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->decide($a['slug'], $id, $this->reviewerId, false, 'approve');
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    public function testEditorMayDecide(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->decide($a['slug'], $id, $this->editorId, false, 'approve');
        assert_same(ModerationService::OK, $o);
    }

    public function testUnknownDecisionIsRejected(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'obliterate');
        assert_same(ModerationService::INVALID, $o);
    }

    public function testSubmissionFromAnotherAdventureIsNotFound(): void
    {
        $a = $this->makeAdventure();
        $b = $this->makeAdventure();
        $id = $this->pending($b);
        [$o] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::NOT_FOUND, $o);
    }

    public function testArchivedAdventureIsReadOnlyForDecisions(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        $this->pdo->exec("UPDATE adventures SET state='archived' WHERE id=" . $a['id']);
        [$o] = $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'approve');
        assert_same(ModerationService::READ_ONLY, $o);
    }

    /* ───────── Reviewers ───────── */

    public function testReviewerNoteAndRecommendationAreRecorded(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o, $d] = $this->svc->addReview(
            $a['slug'], $id, $this->reviewerId, false, 'Reads well; matches the tone.', 'approve'
        );
        assert_same(ModerationService::OK, $o);
        assert_same(1, count($d['reviews']));
        assert_same('approve', (string) $d['reviews'][0]['recommendation']);
        // A recommendation never changes state.
        $row = $this->pdo->query('SELECT state FROM branch_submissions WHERE id=' . $id)->fetch();
        assert_same('pending', (string) $row['state']);
    }

    public function testStrangerCannotAddReviewNotes(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->addReview($a['slug'], $id, $this->strangerId, false, 'Let me in', null);
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    /* ───────── Contributors ───────── */

    public function testContributorMayEditAndResubmitChangesRequested(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'request_changes', ['feedback' => 'Trim it.']);

        [$o, $d] = $this->svc->contributorUpdate($id, $this->contributorId, $this->payload([
            'scene_body' => '<p>A shorter, drier note.</p>',
        ]));
        assert_same(ModerationService::OK, $o);
        assert_same('pending', $d['state']);
        $row = $this->pdo->query('SELECT * FROM branch_submissions WHERE id=' . $id)->fetch();
        assert_same(1, (int) $row['revision']);
    }

    public function testContributorCannotEditAnotherContributorsSubmission(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'request_changes', ['feedback' => 'Trim it.']);
        [$o] = $this->svc->contributorUpdate($id, $this->strangerId, $this->payload());
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    public function testContributorCannotEditAPendingSubmission(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        [$o] = $this->svc->contributorUpdate($id, $this->contributorId, $this->payload());
        assert_same(ModerationService::CONFLICT, $o);
    }

    public function testWithdrawalIsTerminalAndReleasesTheSlot(): void
    {
        $a  = $this->makeAdventure(['max_branches_per_scene' => 1]);
        $id = $this->pending($a);
        [$o] = $this->svc->withdraw($id, $this->contributorId);
        assert_same(ModerationService::OK, $o);
        [$twice] = $this->svc->withdraw($id, $this->contributorId);
        assert_same(ModerationService::CONFLICT, $twice);

        $adv = $this->svc->adventureById($a['id']);
        $sceneId = (int) $this->pdo->query(
            'SELECT id FROM scenes WHERE adventure_id=' . $a['id'] . ' AND is_start=1'
        )->fetch()['id'];
        assert_true($this->svc->hasFreeSlot($adv, $sceneId), 'withdrawn slot is free again');
    }

    public function testContributionHistoryExposesFeedbackAndActions(): void
    {
        $a  = $this->makeAdventure();
        $id = $this->pending($a);
        $this->svc->decide($a['slug'], $id, $this->ownerId, false, 'request_changes', ['feedback' => 'Trim it.']);
        $history = $this->branches->historyForUser($this->contributorId);
        assert_same(1, count($history));
        assert_same('changes_requested', (string) $history[0]['state']);
        assert_same('Trim it.', (string) $history[0]['feedback']);
        assert_true($history[0]['can_edit']);
        assert_true($history[0]['can_withdraw']);
    }

    /* ───────── Story management ───────── */

    public function testOwnerCanEditSceneAndChoiceLabels(): void
    {
        $a  = $this->makeAdventure();
        [$o, $d] = $this->svc->story($a['slug'], $this->ownerId, false);
        assert_same(ModerationService::OK, $o);
        $sceneId = (int) $d['scenes'][0]['id'];

        [$o2] = $this->svc->updateScene($a['slug'], $sceneId, $this->ownerId, false, [
            'title' => 'The flooded reading room',
            'body'  => '<p>Water laps at the <strong>lowest shelf</strong>, patiently.</p>',
        ]);
        assert_same(ModerationService::OK, $o2);
        $row = $this->pdo->query('SELECT * FROM scenes WHERE id=' . $sceneId)->fetch();
        assert_same('The flooded reading room', (string) $row['title']);
    }

    public function testSceneEditRejectsAnEmptyBody(): void
    {
        $a  = $this->makeAdventure();
        [, $d] = $this->svc->story($a['slug'], $this->ownerId, false);
        $sceneId = (int) $d['scenes'][0]['id'];
        [$o] = $this->svc->updateScene($a['slug'], $sceneId, $this->ownerId, false, ['body' => '   ']);
        assert_same(ModerationService::INVALID, $o);
    }

    public function testLockUnlockHideAndRestore(): void
    {
        $a  = $this->makeAdventure();
        [, $d] = $this->svc->story($a['slug'], $this->ownerId, false);
        $sceneId = (int) $d['scenes'][0]['id'];

        [$o, $r] = $this->svc->sceneAction($a['slug'], $sceneId, $this->ownerId, false, 'lock');
        assert_same(ModerationService::OK, $o);
        assert_true($r['locked']);
        [, $r] = $this->svc->sceneAction($a['slug'], $sceneId, $this->ownerId, false, 'unlock');
        assert_true(!$r['locked']);

        // The opening scene may never be hidden.
        [$hidden] = $this->svc->sceneAction($a['slug'], $sceneId, $this->ownerId, false, 'hide');
        assert_same(ModerationService::CONFLICT, $hidden);

        // A branch scene can be hidden and restored.
        [$ok, $branch] = $this->svc->createOwnerBranch($a['slug'], $sceneId, $this->ownerId, false, $this->payload());
        assert_same(ModerationService::OK, $ok);
        [$o2, $r2] = $this->svc->sceneAction($a['slug'], (int) $branch['scene_id'], $this->ownerId, false, 'hide');
        assert_same(ModerationService::OK, $o2);
        assert_same('hidden', $r2['state']);
        [, $r3] = $this->svc->sceneAction($a['slug'], (int) $branch['scene_id'], $this->ownerId, false, 'restore');
        assert_same('published', $r3['state']);
    }

    public function testOwnerBranchRespectsTheBranchLimit(): void
    {
        $a  = $this->makeAdventure(['max_branches_per_scene' => 1]);
        [, $d] = $this->svc->story($a['slug'], $this->ownerId, false);
        $sceneId = (int) $d['scenes'][0]['id'];
        [$first] = $this->svc->createOwnerBranch($a['slug'], $sceneId, $this->ownerId, false, $this->payload());
        assert_same(ModerationService::OK, $first);
        [$second] = $this->svc->createOwnerBranch($a['slug'], $sceneId, $this->ownerId, false, $this->payload([
            'choice_text' => 'Turn back to the stairs',
        ]));
        assert_same(ModerationService::LIMIT, $second);
    }

    public function testReviewerCannotEditTheStory(): void
    {
        $a  = $this->makeAdventure();
        [, $d] = $this->svc->story($a['slug'], $this->reviewerId, false);
        $sceneId = (int) $d['scenes'][0]['id'];
        [$o] = $this->svc->updateScene($a['slug'], $sceneId, $this->reviewerId, false, ['title' => 'Hijacked']);
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    public function testDetailsAndGuidelinesAreSanitised(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->updateDetails($a['slug'], $this->ownerId, false, [
            'title' => 'The Salt Archive, revised',
            'writing_guidelines' => '<p>Keep it <strong>brief</strong>.</p><script>bad()</script>',
        ]);
        assert_same(ModerationService::OK, $o);
        $row = $this->pdo->query('SELECT * FROM adventures WHERE id=' . $a['id'])->fetch();
        assert_true(!str_contains((string) $row['writing_guidelines'], '<script'));
    }

    /* ───────── Settings ───────── */

    public function testOwnerSettingsRoundTrip(): void
    {
        $a = $this->makeAdventure();
        [$o, $d] = $this->svc->updateSettings($a['slug'], $this->ownerId, false, [
            'contribution_mode'       => 'immediate',
            'anonymous_contributions' => true,
            'max_branches_per_scene'  => 6,
            'contributions_paused'    => true,
            'allow_branching'         => false,
            'notify_on_submission'    => false,
            'notify_on_report'        => true,
            'contribution_passcode'   => 'quiet-shelf',
        ]);
        assert_same(ModerationService::OK, $o);
        $s = $d['settings'];
        assert_same('immediate', $s['contribution_mode']);
        assert_same(6, $s['max_branches_per_scene']);
        assert_true($s['anonymous_contributions']);
        assert_true($s['contributions_paused']);
        assert_true(!$s['allow_branching']);
        assert_true($s['requires_passcode']);

        // The passcode is stored hashed, never in the clear.
        $row = $this->pdo->query('SELECT * FROM adventures WHERE id=' . $a['id'])->fetch();
        assert_true(!str_contains((string) $row['contribution_passcode_hash'], 'quiet-shelf'));
    }

    public function testEditorCannotChangeOwnerSettings(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->updateSettings($a['slug'], $this->editorId, false, ['contribution_mode' => 'closed']);
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    public function testInvalidBranchLimitIsRejected(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->updateSettings($a['slug'], $this->ownerId, false, ['max_branches_per_scene' => 99]);
        assert_same(ModerationService::INVALID, $o);
    }

    public function testPausingSubmissionsClosesTheContributionPath(): void
    {
        $a = $this->makeAdventure();
        $this->svc->updateSettings($a['slug'], $this->ownerId, false, ['contributions_paused' => true]);
        [$out] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::CLOSED, $out);
    }

    public function testDisallowingBranchingClosesTheContributionPath(): void
    {
        $a = $this->makeAdventure();
        $this->svc->updateSettings($a['slug'], $this->ownerId, false, ['allow_branching' => false]);
        [$out] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::CLOSED, $out);
    }

    /* ───────── Per-user permissions ───────── */

    public function testTrustedContributorSkipsTheQueue(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->setPermission($a['slug'], $this->ownerId, false, $this->contributorId, 'trusted');
        assert_same(ModerationService::OK, $o);
        [$out, , $d] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::OK, $out);
        assert_same('approved', $d['state']);
        assert_true($d['published']);
    }

    public function testApprovalRequiredOverridesImmediateMode(): void
    {
        $a = $this->makeAdventure(['contribution_mode' => 'immediate']);
        $this->svc->setPermission($a['slug'], $this->ownerId, false, $this->contributorId, 'approval_required');
        [$out, , $d] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::OK, $out);
        assert_same('pending', $d['state']);
    }

    public function testBlockedContributorCannotSubmit(): void
    {
        $a = $this->makeAdventure();
        $this->svc->setPermission($a['slug'], $this->ownerId, false, $this->contributorId, 'blocked', 'Repeated spam');
        [$out] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::BLOCKED, $out);
    }

    public function testClearingAPermissionRemovesTheBlock(): void
    {
        $a = $this->makeAdventure();
        $this->svc->setPermission($a['slug'], $this->ownerId, false, $this->contributorId, 'blocked');
        $this->svc->setPermission($a['slug'], $this->ownerId, false, $this->contributorId, null);
        assert_same(null, $this->branches->permissionLevel($a['id'], $this->contributorId));
        [$out] = $this->branches->submit(
            $a['slug'], $a['scene'], $this->payload(), $this->contributorId, '10.0.0.9'
        );
        assert_same(BranchSubmissionService::OK, $out);
    }

    public function testTheOwnerCannotBeGivenAPermissionOrRole(): void
    {
        $a = $this->makeAdventure();
        [$p] = $this->svc->setPermission($a['slug'], $this->ownerId, false, $this->ownerId, 'blocked');
        assert_same(ModerationService::INVALID, $p);
        [$c] = $this->svc->setCollaborator($a['slug'], $this->ownerId, false, $this->ownerId, 'editor');
        assert_same(ModerationService::INVALID, $c);
    }

    public function testCollaboratorRosterCanBeChangedAndCleared(): void
    {
        $a = $this->makeAdventure();
        [$o, $d] = $this->svc->setCollaborator($a['slug'], $this->ownerId, false, $this->strangerId, 'reviewer');
        assert_same(ModerationService::OK, $o);
        assert_same('reviewer', $this->svc->roleFor($a['id'], $this->strangerId));
        assert_same(3, count($d['collaborators']));

        [, $d2] = $this->svc->setCollaborator($a['slug'], $this->ownerId, false, $this->strangerId, null);
        assert_same(null, $this->svc->roleFor($a['id'], $this->strangerId));
        assert_same(2, count($d2['collaborators']));
    }

    public function testEditorCannotChangePermissions(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->setPermission($a['slug'], $this->editorId, false, $this->contributorId, 'blocked');
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    /* ───────── Reports ───────── */

    public function testAnonymousReaderCanReportAndOwnersResolveIt(): void
    {
        $a = $this->makeAdventure();
        [$o, $d] = $this->svc->createReport($a['slug'], null, '10.0.0.4', 'rating', 'Stronger than teen.');
        assert_same(ModerationService::OK, $o);
        $reportId = (int) $d['report_id'];

        [$lo, $ld] = $this->svc->reports($a['slug'], $this->ownerId, false, 'open');
        assert_same(ModerationService::OK, $lo);
        assert_same(1, count($ld['reports']));

        [$ro] = $this->svc->resolveReport($a['slug'], $reportId, $this->ownerId, false, 'resolve', 'Rating raised.');
        assert_same(ModerationService::OK, $ro);
        [, $after] = $this->svc->reports($a['slug'], $this->ownerId, false, 'open');
        assert_same(0, count($after['reports']));
        [, $resolved] = $this->svc->reports($a['slug'], $this->ownerId, false, 'resolved');
        assert_same(1, count($resolved['reports']));
    }

    public function testUnknownReportReasonIsRejected(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->createReport($a['slug'], null, '10.0.0.4', 'vibes', '');
        assert_same(ModerationService::INVALID, $o);
    }

    public function testStrangerCannotReadTheReportQueue(): void
    {
        $a = $this->makeAdventure();
        $this->svc->createReport($a['slug'], null, '10.0.0.4', 'spam', '');
        [$o] = $this->svc->reports($a['slug'], $this->strangerId, false, 'open');
        assert_same(ModerationService::FORBIDDEN, $o);
    }

    /* ───────── Queue tabs ───────── */

    public function testEachSubmissionTabReturnsOnlyItsOwnState(): void
    {
        $a = $this->makeAdventure();
        $pending  = $this->pending($a, ['choice_text' => 'Open the ledger']);
        $rejected = $this->pending($a, ['choice_text' => 'Burn the ledger']);
        $changes  = $this->pending($a, ['choice_text' => 'Copy the ledger']);
        $withdrawn = $this->pending($a, ['choice_text' => 'Hide the ledger']);

        $this->svc->decide($a['slug'], $rejected, $this->ownerId, false, 'reject', ['feedback' => 'No.']);
        $this->svc->decide($a['slug'], $changes, $this->ownerId, false, 'request_changes', ['feedback' => 'Trim.']);
        $this->svc->withdraw($withdrawn, $this->contributorId);

        $expect = [
            'pending' => 1, 'rejected' => 1, 'changes_requested' => 1,
            'withdrawn' => 1, 'approved' => 0,
        ];
        foreach ($expect as $state => $count) {
            [$o, $d] = $this->svc->submissions($a['slug'], $this->ownerId, false, $state);
            assert_same(ModerationService::OK, $o, $state);
            assert_same($count, count($d['submissions']), $state . ' tab');
        }
        assert_true($pending > 0);
    }

    public function testUnknownSubmissionTabIsRejected(): void
    {
        $a = $this->makeAdventure();
        [$o] = $this->svc->submissions($a['slug'], $this->ownerId, false, 'everything');
        assert_same(ModerationService::INVALID, $o);
    }
}
