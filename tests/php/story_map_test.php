<?php
/**
 * tests/php/story_map_test.php
 *
 * Version 0.23.0 — focused coverage for the story map: what each
 * audience may see, lazy loading of large stories, search, and the
 * integrity checks behind the MVP tree rules.
 */

declare(strict_types=1);

use App\AdventureService;
use App\Database;
use App\Migrator;
use App\PasswordHasher;
use App\StoryMapService;
use App\UserRepository;

final class BPStoryMapTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private StoryMapService $svc;

    private int $ownerId;
    private int $strangerId;
    private int $reviewerId;
    private int $adventureId = 0;
    private string $slug = '';
    private int $rootId = 0;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-map-' . bin2hex(random_bytes(6)) . '.sqlite';
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
        $this->ownerId    = $mk('mapowner');
        $this->strangerId = $mk('mapstranger');
        $this->reviewerId = $mk('mapreviewer');

        [$outcome, $errors, $data] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title' => 'The Salt Road',
            'description' => 'Three ways out of a drowned town.',
            'genre' => 'mystery', 'content_rating' => 'everyone',
            'content_warnings' => [], 'visibility' => 'public',
            'opening_title' => 'The jetty',
            'opening_body'  => '<p>The tide is out.</p>',
            'status' => 'published',
            'contribution_mode' => 'approval',
            'anonymous_contributions' => false,
            'max_branches_per_scene' => 4,
        ]);
        assert_same('ok', $outcome, 'fixture adventure: ' . json_encode($errors));
        $this->slug        = (string) $data['slug'];
        $this->adventureId = (int) $data['id'];
        $this->rootId      = (int) $data['opening_scene']['id'];

        $this->pdo->prepare(
            'INSERT INTO adventure_collaborators (adventure_id, user_id, role, created_at)
             VALUES (:a, :u, :r, :t)'
        )->execute([
            ':a' => $this->adventureId, ':u' => $this->reviewerId,
            ':r' => 'reviewer', ':t' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        $this->svc = new StoryMapService($this->pdo);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private int $sceneCounter = 1;

    private function addScene(
        string $title,
        string $state = 'published',
        string $type = 'story',
        string $body = 'Something happens.',
    ): int {
        $this->sceneCounter++;
        $slug = 'scene-' . $this->sceneCounter . '-' . bin2hex(random_bytes(2));
        $this->pdo->prepare(
            'INSERT INTO scenes
               (adventure_id, slug, scene_number, title, body, body_plain,
                scene_type, state, is_start, created_at, updated_at)
             VALUES (:a, :s, :n, :t, :b, :bp, :ty, :st, 0, :c, :c)'
        )->execute([
            ':a' => $this->adventureId, ':s' => $slug, ':n' => $this->sceneCounter,
            ':t' => $title, ':b' => '<p>' . $body . '</p>', ':bp' => $body,
            ':ty' => $type, ':st' => $state, ':c' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function link(int $from, int $to, string $label, int $position = 0): void
    {
        $this->pdo->prepare(
            'INSERT INTO choices (scene_id, target_scene_id, label, position)
             VALUES (:f, :t, :l, :p)'
        )->execute([':f' => $from, ':t' => $to, ':l' => $label, ':p' => $position]);
    }

    private function sceneSlug(int $id): string
    {
        $s = $this->pdo->prepare('SELECT slug FROM scenes WHERE id = :i');
        $s->execute([':i' => $id]);
        return (string) $s->fetch(\PDO::FETCH_ASSOC)['slug'];
    }

    /** Flatten a tree payload into a list of scene ids. */
    private function flatten(array $nodes, array &$out = []): array
    {
        foreach ($nodes as $n) {
            $out[] = $n['id'];
            $this->flatten($n['children'], $out);
        }
        return $out;
    }

    private function codes(array $issues): array
    {
        return array_values(array_unique(array_map(
            static fn (array $i): string => (string) $i['code'],
            $issues,
        )));
    }

    private function issuesFor(): array
    {
        [$o, $d] = $this->svc->validation($this->slug, $this->ownerId, false);
        assert_same(StoryMapService::OK, $o, 'validation runs for the owner');
        return $d['issues'];
    }

    /* ─────────────────────────── the map ─────────────────────────── */

    public function testPublicMapNestsChoicesUnderTheirParentScene(): void
    {
        $left  = $this->addScene('The salt flats');
        $right = $this->addScene('The chapel');
        $this->link($this->rootId, $left, 'Walk out along the flats');
        $this->link($this->rootId, $right, 'Shelter in the chapel');

        [$o, $d] = $this->svc->publicMap($this->slug);
        assert_same(StoryMapService::OK, $o);
        assert_same(1, count($d['tree']), 'one root');
        $root = $d['tree'][0];
        assert_true($root['is_start'], 'the opening scene is the root');
        assert_same(2, count($root['children']), 'both branches nested under it');
        assert_same('Walk out along the flats', $root['children'][0]['choice_label']);
        assert_same(0, $root['depth']);
        assert_same(1, $root['children'][0]['depth']);
    }

    public function testAnEndingIsMarkedAsOne(): void
    {
        $end = $this->addScene('Swallowed by the tide', 'published', 'ending');
        $this->link($this->rootId, $end, 'Stay where you are');
        [, $d] = $this->svc->publicMap($this->slug);
        assert_same('ending', $d['tree'][0]['children'][0]['type']);
    }

    public function testAnUnknownAdventureIsNotFound(): void
    {
        [$o, $d] = $this->svc->publicMap('no-such-adventure');
        assert_same(StoryMapService::NOT_FOUND, $o);
        assert_same(null, $d);
    }

    public function testADraftAdventureHasNoPublicMap(): void
    {
        $this->pdo->prepare("UPDATE adventures SET state = 'draft' WHERE id = :a")
            ->execute([':a' => $this->adventureId]);
        [$o] = $this->svc->publicMap($this->slug);
        assert_same(StoryMapService::NOT_FOUND, $o, 'an unpublished adventure is not outlined');
    }

    /* ───────────────────────── visibility ────────────────────────── */

    public function testPublicReadersNeverSeeDraftOrHiddenScenes(): void
    {
        $draft  = $this->addScene('An unfinished turn', 'draft');
        $hidden = $this->addScene('A retired ending', 'hidden', 'ending');
        $this->link($this->rootId, $draft, 'Take the unfinished turn');
        $this->link($this->rootId, $hidden, 'Take the retired path');

        [, $d] = $this->svc->publicMap($this->slug);
        $ids = $this->flatten($d['tree']);
        assert_same(1, count($ids), 'only the opening scene is public');
        $json = json_encode($d);
        assert_true(!str_contains($json, 'An unfinished turn'), 'draft title never leaks');
        assert_true(!str_contains($json, 'A retired ending'), 'hidden title never leaks');
        assert_true(!str_contains($json, 'Take the unfinished turn'), 'the choice label is gone too');
    }

    public function testManagersSeeDraftAndHiddenScenesWithLabels(): void
    {
        $draft  = $this->addScene('An unfinished turn', 'draft');
        $hidden = $this->addScene('A retired ending', 'hidden', 'ending');
        $this->link($this->rootId, $draft, 'Take the unfinished turn');
        $this->link($this->rootId, $hidden, 'Take the retired path');

        [$o, $d] = $this->svc->manageMap($this->slug, $this->ownerId, false);
        assert_same(StoryMapService::OK, $o);
        $labels = [];
        foreach ($d['tree'][0]['children'] as $c) $labels[$c['title']] = $c['label'];
        assert_same('Draft', $labels['An unfinished turn'] ?? null);
        assert_same('Hidden', $labels['A retired ending'] ?? null);
        assert_same('Published', $d['tree'][0]['label']);
        assert_same('manage', $d['scope']);
    }

    public function testASignedOutVisitorCannotOpenTheManageMap(): void
    {
        [$o] = $this->svc->manageMap($this->slug, null, false);
        assert_same(StoryMapService::FORBIDDEN, $o);
    }

    public function testAStrangerCannotOpenTheManageMap(): void
    {
        [$o] = $this->svc->manageMap($this->slug, $this->strangerId, false);
        assert_same(StoryMapService::FORBIDDEN, $o);
    }

    public function testAReviewerCanReadTheMapButTheRoleIsReported(): void
    {
        [$o, $d] = $this->svc->manageMap($this->slug, $this->reviewerId, false);
        assert_same(StoryMapService::OK, $o);
        assert_same('reviewer', $d['role']);
    }

    public function testValidationIsManageOnly(): void
    {
        [, $pub] = $this->svc->publicMap($this->slug);
        assert_true(!isset($pub['issues']), 'readers are not shown validation findings');
        [$o] = $this->svc->validation($this->slug, $this->strangerId, false);
        assert_same(StoryMapService::FORBIDDEN, $o);
    }

    /* ──────────────────────── lazy loading ───────────────────────── */

    private function buildChain(int $length): array
    {
        $ids = [$this->rootId];
        $prev = $this->rootId;
        for ($i = 1; $i <= $length; $i++) {
            $next = $this->addScene('Mile ' . $i);
            $this->link($prev, $next, 'Walk on to mile ' . $i);
            $ids[] = $next;
            $prev = $next;
        }
        return $ids;
    }

    public function testADeepStoryIsReturnedOneSliceAtATime(): void
    {
        $this->buildChain(12);
        [, $d] = $this->svc->publicMap($this->slug, ['depth' => 3]);
        assert_same(3, count($this->flatten($d['tree'])), 'three levels only');
        assert_same(13, $d['total_scenes'], 'the total is still reported');

        // The deepest returned node advertises that more is waiting.
        $node = $d['tree'][0];
        while ($node['children'] !== []) $node = $node['children'][0];
        assert_true($node['has_more'], 'the cut-off node offers its branch');
        assert_same(1, $node['child_count'], 'and says how many children it has');
    }

    public function testABranchCanBeLoadedOnItsOwn(): void
    {
        $ids = $this->buildChain(6);
        $fourth = $this->sceneSlug($ids[3]);
        [, $d] = $this->svc->publicMap($this->slug, ['root' => $fourth, 'depth' => 2]);
        assert_same($fourth, $d['root'], 'the slice starts where asked');
        assert_same($fourth, $d['tree'][0]['id']);
        assert_same(2, count($this->flatten($d['tree'])));
        assert_same(0, $d['tree'][0]['depth'], 'depth is relative to the slice');
    }

    public function testDepthIsClampedSoOneCallIsNeverUnbounded(): void
    {
        $this->buildChain(30);
        [, $d] = $this->svc->publicMap($this->slug, ['depth' => 9999]);
        assert_same(StoryMapService::MAX_DEPTH, $d['depth'], 'depth is clamped');
        assert_true(
            count($this->flatten($d['tree'])) <= StoryMapService::MAX_NODES,
            'and the node count stays bounded',
        );
    }

    public function testALeafNeverClaimsThereIsMore(): void
    {
        $end = $this->addScene('The last light', 'published', 'ending');
        $this->link($this->rootId, $end, 'Climb the tower');
        [, $d] = $this->svc->publicMap($this->slug, ['depth' => 5]);
        $leaf = $d['tree'][0]['children'][0];
        assert_same(false, $leaf['has_more']);
        assert_same(0, $leaf['child_count']);
    }

    /* ─────────────────────────── search ──────────────────────────── */

    public function testSearchFindsASceneAndThePathToIt(): void
    {
        $ids = $this->buildChain(4);
        [, $d] = $this->svc->publicMap($this->slug, ['q' => 'mile 3']);
        assert_same(1, count($d['matches']), 'one hit');
        assert_same($this->sceneSlug($ids[3]), $d['matches'][0]['id']);
        assert_same(3, count($d['matches'][0]['path']), 'the path back to the opening scene');
    }

    public function testSearchIsCaseInsensitiveAndCanMissCleanly(): void
    {
        $this->addScene('The Harbour Bell');
        [, $a] = $this->svc->manageMap($this->slug, $this->ownerId, false, ['q' => 'HARBOUR']);
        assert_same(1, count($a['matches']));
        [, $b] = $this->svc->manageMap($this->slug, $this->ownerId, false, ['q' => 'submarine']);
        assert_same(0, count($b['matches']));
    }

    public function testSearchObeysTheSameVisibilityRulesAsTheMap(): void
    {
        $this->addScene('A secret vault', 'hidden');
        [, $pub] = $this->svc->publicMap($this->slug, ['q' => 'secret vault']);
        assert_same(0, count($pub['matches']), 'readers cannot find hidden scenes by name');
        [, $mng] = $this->svc->manageMap($this->slug, $this->ownerId, false, ['q' => 'secret vault']);
        assert_same(1, count($mng['matches']));
        assert_same('Hidden', $mng['matches'][0]['label']);
    }

    /* ───────────────────────── integrity ─────────────────────────── */

    public function testAMissingDestinationIsReported(): void
    {
        $gone = $this->addScene('A deleted turn');
        $this->link($this->rootId, $gone, 'Turn back');
        // Point the choice at a scene that is not there any more, the way a
        // rough hand-edit of the database would leave it.
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->prepare('UPDATE choices SET target_scene_id = 999999 WHERE scene_id = :s')
            ->execute([':s' => $this->rootId]);
        $this->pdo->prepare('DELETE FROM scenes WHERE id = :i')->execute([':i' => $gone]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        assert_true(in_array('missing_destination', $this->codes($this->issuesFor()), true));
    }

    public function testAPublishedChoiceIntoAHiddenSceneIsReported(): void
    {
        $hidden = $this->addScene('A retired room', 'hidden');
        $this->link($this->rootId, $hidden, 'Open the door');
        $issues = $this->issuesFor();
        assert_true(in_array('hidden_destination', $this->codes($issues), true));
        $found = null;
        foreach ($issues as $i) if ($i['code'] === 'hidden_destination') $found = $i;
        assert_same('Open the door', $found['choice'], 'the finding names the choice');
    }

    public function testAnEmptySceneIsReported(): void
    {
        $blank = $this->addScene('Untitled turn', 'draft', 'story', '');
        $this->link($this->rootId, $blank, 'Step through');
        assert_true(in_array('empty_scene', $this->codes($this->issuesFor()), true));
    }

    public function testDuplicateSiblingChoicesAreReported(): void
    {
        $a = $this->addScene('The left path');
        $b = $this->addScene('The right path');
        $this->link($this->rootId, $a, 'Take the path', 0);
        $this->link($this->rootId, $b, 'take  the path!', 1);
        assert_true(in_array('duplicate_sibling_choice', $this->codes($this->issuesFor()), true));
    }

    public function testTheSameLabelOnDifferentScenesIsFine(): void
    {
        $a = $this->addScene('The left path');
        $b = $this->addScene('Further on');
        $this->link($this->rootId, $a, 'Keep going');
        $this->link($a, $b, 'Keep going');
        assert_true(!in_array('duplicate_sibling_choice', $this->codes($this->issuesFor()), true));
    }

    public function testAPublishedNonEndingSceneWithNoChoicesIsReported(): void
    {
        // The opening scene itself has no choices yet.
        assert_true(in_array('dead_end', $this->codes($this->issuesFor()), true));
        $end = $this->addScene('A proper ending', 'published', 'ending');
        $this->link($this->rootId, $end, 'Finish');
        assert_true(
            !in_array('dead_end', $this->codes($this->issuesFor()), true),
            'an ending is allowed to have no choices',
        );
    }

    public function testAnUnreachableSceneIsReported(): void
    {
        $this->addScene('An orphaned room');
        $issues = $this->issuesFor();
        assert_true(in_array('unreachable_scene', $this->codes($issues), true));
        [, $map] = $this->svc->manageMap($this->slug, $this->ownerId, false);
        assert_same(1, count($map['unreachable']), 'and listed on the map itself');
    }

    public function testASharedDestinationBreaksTheOneParentRule(): void
    {
        $a = $this->addScene('The left path');
        $b = $this->addScene('The right path');
        $shared = $this->addScene('The same clearing');
        $this->link($this->rootId, $a, 'Go left');
        $this->link($this->rootId, $b, 'Go right');
        $this->link($a, $shared, 'Push on');
        $this->link($b, $shared, 'Push on');
        assert_true(in_array('invalid_parent', $this->codes($this->issuesFor()), true));
    }

    public function testALoopIsReportedAndNeverExpandedIntoTheTree(): void
    {
        $a = $this->addScene('The mirrored hall');
        $this->link($this->rootId, $a, 'Enter the hall');
        $this->link($a, $this->rootId, 'Go back to the jetty');

        assert_true(in_array('invalid_parent', $this->codes($this->issuesFor()), true));
        // The tree must still terminate and must not repeat a scene.
        [, $d] = $this->svc->manageMap($this->slug, $this->ownerId, false, ['depth' => 8]);
        $ids = $this->flatten($d['tree']);
        assert_same(count($ids), count(array_unique($ids)), 'every scene appears once');
        assert_same(2, count($ids));
    }

    public function testACrossLinkBetweenBranchesIsReported(): void
    {
        $a = $this->addScene('The dunes');
        $b = $this->addScene('The chapel');
        $deep = $this->addScene('The bell tower');
        $this->link($this->rootId, $a, 'Go left');
        $this->link($this->rootId, $b, 'Go right');
        $this->link($b, $deep, 'Climb');
        $this->link($a, $deep, 'Cut across');
        assert_true(in_array('invalid_parent', $this->codes($this->issuesFor()), true));
    }

    public function testAChoiceBackIntoTheOpeningSceneIsAnInvalidParent(): void
    {
        $a = $this->addScene('The dunes');
        $this->link($this->rootId, $a, 'Go left');
        $this->link($a, $this->rootId, 'Start over');
        $found = false;
        foreach ($this->issuesFor() as $i) {
            if ($i['code'] === 'invalid_parent' && str_contains($i['message'], 'opening scene')) {
                $found = true;
            }
        }
        assert_true($found, 'the opening scene must have no parent');
    }

    public function testAnExcessivelyDeepStoryIsReported(): void
    {
        $this->buildChain(StoryMapService::DEPTH_LIMIT + 2);
        assert_true(in_array('excessive_depth', $this->codes($this->issuesFor()), true));
    }

    public function testATidyStoryReportsNoErrors(): void
    {
        $mid = $this->addScene('The salt flats');
        $end = $this->addScene('Dry land', 'published', 'ending');
        $this->link($this->rootId, $mid, 'Walk out');
        $this->link($mid, $end, 'Keep walking');
        [$o, $d] = $this->svc->validation($this->slug, $this->ownerId, false);
        assert_same(StoryMapService::OK, $o);
        assert_same(0, $d['summary']['errors'], 'no errors: ' . json_encode($d['issues']));
    }

    public function testTheSummaryCountsErrorsAndWarningsSeparately(): void
    {
        $this->addScene('An orphaned room');        // warning
        $blank = $this->addScene('Blank', 'published', 'story', ''); // error(s)
        $this->link($this->rootId, $blank, 'Step through');
        [, $d] = $this->svc->validation($this->slug, $this->ownerId, false);
        assert_true($d['summary']['errors'] > 0, 'errors counted');
        assert_true($d['summary']['warnings'] > 0, 'warnings counted');
        assert_same(
            $d['summary']['total'],
            $d['summary']['errors'] + $d['summary']['warnings'],
            'the totals agree',
        );
    }
}
