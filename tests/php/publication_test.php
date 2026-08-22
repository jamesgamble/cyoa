<?php
/**
 * tests/php/publication_test.php
 *
 * Version 0.17.0 — focused coverage for drafts, preview, publication,
 * authorization, read-only archives, and activity records.
 */

declare(strict_types=1);

use App\AdventureService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\PublicationService;
use App\PublicRepository;
use App\UserRepository;

final class BPPublicationTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private PublicationService $svc;
    private int $ownerId;
    private int $editorId;
    private int $strangerId;
    private int $adminId;
    private int $adventureId;
    private string $slug;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-pub-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo   = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();

        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $mk = static function (string $name) use ($repo, $hash): int {
            return (int) $repo->insert([
                'email' => $name . '@example.com', 'username' => $name,
                'display_name' => ucfirst($name), 'password_hash' => $hash['hash'],
                'password_algo' => $hash['algo'], 'status' => 'active',
                'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        };
        $this->ownerId    = $mk('owner');
        $this->editorId   = $mk('editor');
        $this->strangerId = $mk('stranger');
        $this->adminId    = $mk('admin');

        [$outcome, , $adv] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title' => 'The Lantern Road',
            'description' => 'A winter journey through a hollow kingdom.',
            'genre' => 'fantasy', 'content_rating' => 'teen',
            'content_warnings' => ['Peril'], 'visibility' => 'public',
            'opening_title' => 'The gate at dusk',
            'opening_body' => '<p>Snow gathers on the <strong>iron gate</strong>.</p>',
            'status' => 'draft', 'contribution_mode' => 'approval',
            'anonymous_contributions' => false, 'max_branches_per_scene' => 4,
        ]);
        assert_same(AdventureService::OK, $outcome, 'fixture adventure created');
        $this->adventureId = (int) $adv['id'];
        $this->slug        = (string) $adv['slug'];

        $this->svc = new PublicationService($this->pdo);
        $this->svc->addCollaborator($this->adventureId, PublicationService::ROLE_OWNER, $this->editorId, 'editor');
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    private function state(): string
    {
        $s = $this->pdo->query('SELECT state FROM adventures WHERE id = ' . $this->adventureId);
        return (string) $s->fetch()['state'];
    }

    /* ───────── Authorization ───────── */

    public function testAuthorIsOwner(): void
    {
        assert_same('owner', $this->svc->roleFor($this->adventureId, $this->ownerId));
    }

    public function testCollaboratorIsEditor(): void
    {
        assert_same('editor', $this->svc->roleFor($this->adventureId, $this->editorId));
    }

    public function testStrangerHasNoRole(): void
    {
        assert_same(null, $this->svc->roleFor($this->adventureId, $this->strangerId));
    }

    public function testAnonymousHasNoRole(): void
    {
        assert_same(null, $this->svc->roleFor($this->adventureId, null));
    }

    public function testAdministratorFlagGrantsRole(): void
    {
        assert_same('administrator', $this->svc->roleFor($this->adventureId, $this->adminId, true));
    }

    public function testNonOwnerCannotAddCollaborator(): void
    {
        assert_true(!$this->svc->addCollaborator($this->adventureId, 'editor', $this->strangerId));
    }

    /* ───────── Draft visibility ───────── */

    public function testDraftIsHiddenFromPublicDiscover(): void
    {
        $found = false;
        foreach ((new PublicRepository($this->pdo))->discover([]) as $a) {
            if (($a['slug'] ?? '') === $this->slug) $found = true;
        }
        assert_true(!$found, 'draft not listed publicly');
    }

    public function testDraftIsHiddenFromPublicSlugRead(): void
    {
        assert_same(null, (new PublicRepository($this->pdo))->adventureBySlug($this->slug));
    }

    public function testManagePayloadRequiresRole(): void
    {
        [$outcome, $data] = $this->svc->managePayload($this->slug, $this->strangerId);
        assert_same(PublicationService::FORBIDDEN, $outcome);
        assert_same(null, $data);
    }

    public function testManagePayloadForOwnerListsActions(): void
    {
        [$outcome, $data] = $this->svc->managePayload($this->slug, $this->ownerId);
        assert_same(PublicationService::OK, $outcome);
        assert_same('draft', $data['adventure']['state']);
        assert_true(in_array('publish', $data['available_actions'], true));
        assert_true(in_array('archive', $data['available_actions'], true));
    }

    public function testManagePayloadUnknownSlugIsNotFound(): void
    {
        [$outcome] = $this->svc->managePayload('no-such-adventure', $this->ownerId);
        assert_same(PublicationService::NOT_FOUND, $outcome);
    }

    /* ───────── Preview ───────── */

    public function testPreviewRequiresAuthorization(): void
    {
        [$outcome, $data] = $this->svc->previewPayload($this->slug, $this->strangerId);
        assert_same(PublicationService::FORBIDDEN, $outcome);
        assert_same(null, $data);
    }

    public function testPreviewIsRefusedToAnonymousReaders(): void
    {
        [$outcome] = $this->svc->previewPayload($this->slug, null);
        assert_same(PublicationService::FORBIDDEN, $outcome);
    }

    public function testPreviewShowsDraftScenesToEditor(): void
    {
        [$outcome, $data] = $this->svc->previewPayload($this->slug, $this->editorId);
        assert_same(PublicationService::OK, $outcome);
        assert_same(1, count($data['scenes']));
        assert_same('draft', $data['scenes'][0]['state']);
    }

    public function testPreviewIsMarkedNoIndex(): void
    {
        [, $data] = $this->svc->previewPayload($this->slug, $this->ownerId);
        assert_same(true, $data['noindex']);
    }

    public function testPreviewBodyIsSanitized(): void
    {
        $this->pdo->exec(
            "UPDATE scenes SET body = '<p>ok</p><script>alert(1)</script>' WHERE adventure_id = " . $this->adventureId
        );
        [, $data] = $this->svc->previewPayload($this->slug, $this->ownerId);
        assert_true(strpos($data['scenes'][0]['body'], '<script') === false);
    }

    /* ───────── Publication rules ───────── */

    public function testPublishRequiresValidOpeningScene(): void
    {
        $this->pdo->exec("UPDATE scenes SET body = '', body_plain = '' WHERE adventure_id = " . $this->adventureId);
        assert_true(!$this->svc->hasValidOpeningScene($this->adventureId));
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        assert_same(PublicationService::NO_OPENING, $outcome);
        assert_same('draft', $this->state());
    }

    public function testPublishSetsPublishedStateAndScene(): void
    {
        [$outcome, $data] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        assert_same(PublicationService::OK, $outcome);
        assert_same('published', $data['state']);
        assert_same('published', $this->state());
        $s = $this->pdo->query('SELECT state FROM scenes WHERE adventure_id = ' . $this->adventureId)->fetch();
        assert_same('published', (string) $s['state']);
    }

    public function testPublishedAdventureIsPubliclyReadable(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        $adv = (new PublicRepository($this->pdo))->adventureBySlug($this->slug);
        assert_true($adv !== null, 'published adventure readable');
    }

    public function testUnpublishReturnsToDraftWithoutDeletingContent(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'unpublish');
        assert_same(PublicationService::OK, $outcome);
        assert_same('draft', $this->state());
        $c = $this->pdo->query('SELECT COUNT(*) AS c FROM scenes WHERE adventure_id = ' . $this->adventureId)->fetch();
        assert_same(1, (int) $c['c'], 'scenes preserved');
        assert_same(null, (new PublicRepository($this->pdo))->adventureBySlug($this->slug));
    }

    public function testSetCompleteAndOnHoldAndInProgress(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        [$o1] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'set_complete');
        assert_same(PublicationService::OK, $o1);
        assert_same('complete', $this->state());
        [$o2] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'set_on_hold');
        assert_same(PublicationService::OK, $o2);
        assert_same('on-hold', $this->state());
        [$o3] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'set_in_progress');
        assert_same(PublicationService::OK, $o3);
        assert_same('published', $this->state());
    }

    public function testInvalidTransitionIsRejected(): void
    {
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'set_complete');
        assert_same(PublicationService::INVALID, $outcome);
        assert_same('draft', $this->state());
    }

    public function testUnknownActionIsRejected(): void
    {
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'delete_everything');
        assert_same(PublicationService::INVALID, $outcome);
    }

    public function testStrangerCannotChangeStatus(): void
    {
        $role = $this->svc->roleFor($this->adventureId, $this->strangerId);
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->strangerId, $role, 'publish');
        assert_same(PublicationService::FORBIDDEN, $outcome);
        assert_same('draft', $this->state());
    }

    public function testEditorMayPublish(): void
    {
        $role = $this->svc->roleFor($this->adventureId, $this->editorId);
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->editorId, $role, 'publish');
        assert_same(PublicationService::OK, $outcome);
    }

    /* ───────── Archive ───────── */

    public function testArchiveMakesAdventureReadOnly(): void
    {
        [$outcome] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'archive');
        assert_same(PublicationService::OK, $outcome);
        assert_same('archived', $this->state());
        assert_same([], $this->svc->availableActions('archived', 'owner', $this->adventureId));
        [$second] = $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        assert_same(PublicationService::READ_ONLY, $second);
    }

    public function testArchivedDraftSavesAreRejected(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'archive');
        [$outcome] = $this->svc->saveDraft($this->adventureId, 'owner', ['title' => 'Renamed after archive']);
        assert_same(PublicationService::READ_ONLY, $outcome);
        $row = $this->pdo->query('SELECT title FROM adventures WHERE id = ' . $this->adventureId)->fetch();
        assert_same('The Lantern Road', (string) $row['title']);
    }

    /* ───────── Draft saving ───────── */

    public function testOwnerCanSaveDraftEdits(): void
    {
        [$outcome] = $this->svc->saveDraft($this->adventureId, 'owner', [
            'title' => 'The Lantern Road, Revised',
            'description' => 'A revised winter journey.',
            'opening_body' => '<p>Ice on the <em>latch</em>.</p><script>x</script>',
        ]);
        assert_same(PublicationService::OK, $outcome);
        $row = $this->pdo->query('SELECT title FROM adventures WHERE id = ' . $this->adventureId)->fetch();
        assert_same('The Lantern Road, Revised', (string) $row['title']);
        $scene = $this->pdo->query('SELECT body, body_plain FROM scenes WHERE adventure_id = ' . $this->adventureId)->fetch();
        assert_true(strpos((string) $scene['body'], '<script') === false, 'draft body sanitized');
        assert_true(strpos((string) $scene['body_plain'], 'latch') !== false, 'plain text derived');
    }

    public function testStrangerCannotSaveDraft(): void
    {
        [$outcome] = $this->svc->saveDraft($this->adventureId, null, ['title' => 'Hijacked title']);
        assert_same(PublicationService::FORBIDDEN, $outcome);
    }

    public function testInvalidDraftIsRejected(): void
    {
        [$outcome, $fields] = $this->svc->saveDraft($this->adventureId, 'owner', ['title' => 'x']);
        assert_same(PublicationService::INVALID, $outcome);
        assert_same('invalid', $fields['title']);
    }

    /* ───────── Activity ───────── */

    public function testStatusChangeCreatesActivityRecord(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        $log = $this->svc->activity($this->adventureId);
        assert_same(1, count($log));
        assert_same('publish', $log[0]['action']);
        assert_same('draft', $log[0]['from_state']);
        assert_same('published', $log[0]['to_state']);
        assert_same('Owner', $log[0]['actor']);
    }

    public function testRejectedChangeCreatesNoActivityRecord(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->strangerId, null, 'publish');
        assert_same(0, count($this->svc->activity($this->adventureId)));
    }

    public function testActivityAccumulatesNewestFirst(): void
    {
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'publish');
        $this->svc->changeStatus($this->adventureId, $this->ownerId, 'owner', 'unpublish');
        $log = $this->svc->activity($this->adventureId);
        assert_same(2, count($log));
        assert_same('unpublish', $log[0]['action']);
        assert_same('publish', $log[1]['action']);
    }
}
