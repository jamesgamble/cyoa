<?php
/**
 * Version 0.24.0 — revision history: recording, retention, comparison,
 * restoration, authorization, and privacy.
 */
declare(strict_types=1);

use App\AdventureService;
use App\Database;
use App\Migrator;
use App\ModerationService;
use App\PasswordHasher;
use App\RevisionService;
use App\UserRepository;

final class BPRevisionTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private int $ownerId;
    private int $strangerId;
    private int $reviewerId;
    private int $adventureId;
    private string $slug;
    private int $rootId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-rev-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $mk = static fn (string $n): int => (int) $repo->insert([
            'email' => $n . '@example.com', 'username' => $n, 'display_name' => ucfirst($n),
            'password_hash' => $hash['hash'], 'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->ownerId = $mk('revowner');
        $this->strangerId = $mk('revstranger');
        $this->reviewerId = $mk('revreviewer');
        [$o, $e, $d] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title' => 'The Salt Road', 'description' => 'Three ways out of a drowned town.',
            'genre' => 'mystery', 'content_rating' => 'everyone', 'content_warnings' => [],
            'visibility' => 'public', 'opening_title' => 'The jetty',
            'opening_body' => '<p>The tide is out.</p>', 'status' => 'published',
            'contribution_mode' => 'approval', 'anonymous_contributions' => false,
            'max_branches_per_scene' => 4,
        ]);
        assert_same('ok', $o, json_encode($e));
        $this->slug = (string) $d['slug'];
        $this->adventureId = (int) $d['id'];
        $this->rootId = (int) $d['opening_scene']['id'];
        $this->pdo->prepare("INSERT INTO adventure_collaborators (adventure_id, user_id, role, created_at) VALUES (:a,:u,'reviewer',:t)")
            ->execute([':a' => $this->adventureId, ':u' => $this->reviewerId, ':t' => gmdate('c')]);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) if (is_file($f)) @unlink($f);
    }

    private function mod(): ModerationService { return new ModerationService($this->pdo); }
    private function revs(int $retain = RevisionService::RETAIN): RevisionService { return new RevisionService($this->pdo, $retain); }

    private function editScene(string $title, string $body, array $choices = []): void
    {
        [$o] = $this->mod()->updateScene($this->slug, $this->rootId, $this->ownerId, false,
            ['title' => $title, 'body' => $body, 'choices' => $choices]);
        assert_same('ok', $o);
    }

    private function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM content_revisions')->fetchColumn();
    }

    public function testSceneEditRecordsPreviousTitleAndBody(): void
    {
        $this->editScene('The long jetty', '<p>The tide is in.</p>');
        [$o, $d] = $this->revs()->list($this->slug, $this->ownerId, false);
        assert_same('ok', $o);
        $fields = array_column($d['revisions'], 'field');
        sort($fields);
        assert_same(['body', 'title'], $fields);
        assert_same('Revowner', $d['revisions'][0]['editor']);
        assert_true($d['revisions'][0]['created_at'] !== '');
    }

    public function testUnchangedFieldsAndDraftScenesAreNotRecorded(): void
    {
        $this->editScene('The jetty', '<p>The tide is out.</p>');
        assert_same(0, $this->count());
        $this->pdo->exec("UPDATE scenes SET state = 'draft' WHERE id = " . $this->rootId);
        $this->editScene('Draft title', '<p>Draft words.</p>');
        assert_same(0, $this->count());
    }

    public function testDescriptionGuidelinesAndChoiceAreRecorded(): void
    {
        [$o] = $this->mod()->updateDetails($this->slug, $this->ownerId, false,
            ['description' => 'A new description here.', 'writing_guidelines' => '<p>Be kind.</p>']);
        assert_same('ok', $o);
        $this->pdo->prepare("INSERT INTO scenes (adventure_id, slug, scene_number, title, body, body_plain, scene_type, state, is_start, created_at, updated_at) VALUES (:a,'s2',2,'Next','<p>x</p>','x','story','published',0,:t,:t)")
            ->execute([':a' => $this->adventureId, ':t' => gmdate('c')]);
        $to = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO choices (scene_id, target_scene_id, label, position) VALUES (:s,:t,:l,0)')
            ->execute([':s' => $this->rootId, ':t' => $to, ':l' => 'Walk on']);
        $cid = (int) $this->pdo->lastInsertId();
        $this->editScene('The jetty', '<p>The tide is out.</p>', [['id' => $cid, 'label' => 'Run on']]);
        $rows = $this->pdo->query('SELECT target_type, field, content FROM content_revisions ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        assert_same('description', $rows[0]['field']);
        assert_same('Three ways out of a drowned town.', $rows[0]['content']);
        assert_same('writing_guidelines', $rows[1]['field']);
        assert_same('choice', $rows[2]['target_type']);
        assert_same('Walk on', $rows[2]['content']);
    }

    public function testRetentionKeepsMostRecentTwenty(): void
    {
        for ($i = 1; $i <= 25; $i++) $this->editScene('Title number ' . $i, '<p>The tide is out.</p>');
        assert_same(20, $this->count());
        $oldest = $this->pdo->query("SELECT content FROM content_revisions ORDER BY id LIMIT 1")->fetchColumn();
        assert_same('Title number 5', $oldest); // 25 revisions: 'The jetty', 1..24
    }

    public function testRetentionLimitIsConfigurable(): void
    {
        $svc = $this->revs(3);
        for ($i = 1; $i <= 6; $i++) $svc->beforeSceneChange($this->rootId, $this->ownerId, ['title' => 'x' . $i]);
        assert_same(3, $this->count());
    }

    public function testCompareWithCurrentAndOtherRevision(): void
    {
        $this->editScene('The jetty', "<p>The tide is out.</p><p>Gulls cry.</p>");
        $this->editScene('The jetty', "<p>The tide is in.</p><p>Gulls cry.</p>");
        $ids = array_map('intval', $this->pdo->query("SELECT id FROM content_revisions WHERE field='body' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        [$o, $d] = $this->revs()->compare($this->slug, $ids[1], null, $this->ownerId, false);
        assert_same('ok', $o);
        $ops = array_map(fn ($l) => $l['op'] . ':' . $l['text'], $d['diff']);
        assert_true(in_array('removed:The tide is out.', $ops, true));
        assert_true(in_array('added:The tide is in.', $ops, true));
        assert_true(in_array('same:Gulls cry.', $ops, true));
        [$o2, $d2] = $this->revs()->compare($this->slug, $ids[0], $ids[1], $this->ownerId, false);
        assert_same('ok', $o2);
        assert_same('Revision #' . $ids[1], $d2['newer_label']);
    }

    public function testRestoreAppliesOldValueAndCreatesRevision(): void
    {
        $this->editScene('The long jetty', '<p>The tide is out.</p>');
        $rid = (int) $this->pdo->query("SELECT id FROM content_revisions WHERE field='title'")->fetchColumn();
        [$o, $d] = $this->revs()->restore($this->slug, $rid, $this->ownerId, false);
        assert_same('ok', $o);
        assert_same('The jetty', $this->pdo->query('SELECT title FROM scenes WHERE id=' . $this->rootId)->fetchColumn());
        $new = $this->pdo->query('SELECT content, reason FROM content_revisions WHERE id=' . (int) $d['revision_id'])->fetch(\PDO::FETCH_ASSOC);
        assert_same('The long jetty', $new['content']);
        assert_same('restore', $new['reason']);
    }

    public function testArchivedAdventureCannotRestore(): void
    {
        $this->editScene('The long jetty', '<p>The tide is out.</p>');
        $rid = (int) $this->pdo->query("SELECT id FROM content_revisions LIMIT 1")->fetchColumn();
        $this->pdo->exec("UPDATE adventures SET state='archived' WHERE id=" . $this->adventureId);
        [$o] = $this->revs()->restore($this->slug, $rid, $this->ownerId, false);
        assert_same('read_only', $o);
    }

    public function testAuthorization(): void
    {
        $this->editScene('The long jetty', '<p>The tide is out.</p>');
        $rid = (int) $this->pdo->query("SELECT id FROM content_revisions LIMIT 1")->fetchColumn();
        foreach ([null, $this->strangerId, $this->reviewerId] as $u) {
            assert_same('forbidden', $this->revs()->list($this->slug, $u, false)[0]);
            assert_same('forbidden', $this->revs()->compare($this->slug, $rid, null, $u, false)[0]);
            assert_same('forbidden', $this->revs()->restore($this->slug, $rid, $u, false)[0]);
        }
        assert_same('ok', $this->revs()->list($this->slug, $this->strangerId, true)[0]);
        assert_same('not_found', $this->revs()->compare($this->slug, 999999, null, $this->ownerId, false)[0]);
    }

    public function testListExposesNoPrivateData(): void
    {
        $this->editScene('The long jetty', '<p>The tide is out.</p>');
        [, $d] = $this->revs()->list($this->slug, $this->ownerId, false);
        $json = json_encode($d);
        foreach (['@example.com', 'password', 'session', 'smtp', 'editor_id', 'ip'] as $bad) {
            assert_true(stripos($json, '"' . $bad) === false && stripos($json, $bad . '"') === false || $bad === 'ip' , $bad);
        }
        assert_true(stripos($json, '@example.com') === false);
        assert_same(['id','target_type','target_id','target_label','field','reason','editor','created_at'], array_keys($d['revisions'][0]));
    }
}
