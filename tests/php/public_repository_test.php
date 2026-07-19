<?php
/**
 * tests/php/public_repository_test.php
 *
 * Migration, visibility, endpoint, and integration coverage for the
 * v0.10.0 public adventure data model.
 */

declare(strict_types=1);

use App\Database;
use App\Migrator;
use App\PublicRepository;

final class BPPublicRepositoryTest
{
    private string $tmpDb;
    private \PDO $pdo;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-pubrepo-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
        $this->seed();
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'a', 'Author A'), (2, 'b', 'Author B')");
        // Various visibility combinations.
        $rows = [
            // slug, state, visibility, contribution, genre, rating, updated_at
            ['pub-open',       'published','public',   'immediate','fantasy','everyone','2026-01-05T00:00Z'],
            ['pub-approval',   'published','public',   'approval', 'mystery','teen',    '2026-01-04T00:00Z'],
            ['pub-closed',     'complete', 'public',   'closed',   'fantasy','everyone','2026-01-03T00:00Z'],
            ['on-hold-adv',    'on-hold',  'public',   'closed',   'horror', 'mature',  '2026-01-02T00:00Z'],
            ['archived-adv',   'archived', 'public',   'closed',   'fantasy','everyone','2026-01-01T00:00Z'],
            ['unlisted-adv',   'published','unlisted', 'closed',   'fantasy','everyone','2026-01-06T00:00Z'],
            ['draft-adv',      'draft',    'public',   'closed',   'fantasy','everyone','2026-01-07T00:00Z'],
            ['suspended-adv',  'suspended','public',   'closed',   'fantasy','everyone','2026-01-08T00:00Z'],
        ];
        $ins = $this->pdo->prepare(
            'INSERT INTO adventures (slug, title, author_id, synopsis, description, genre, content_rating, state, visibility, contribution_state, updated_at)
             VALUES (:slug, :title, 1, :syn, :desc, :genre, :rating, :state, :vis, :contrib, :ts)'
        );
        foreach ($rows as [$slug, $state, $vis, $contrib, $genre, $rating, $ts]) {
            $ins->execute([
                ':slug' => $slug, ':title' => ucfirst($slug),
                ':syn'  => 'syn ' . $slug, ':desc' => 'desc ' . $slug,
                ':genre' => $genre, ':rating' => $rating,
                ':state' => $state, ':vis' => $vis,
                ':contrib' => $contrib, ':ts' => $ts,
            ]);
        }

        // Scenes for pub-open: published start, published mid,
        // published ending, hidden scene, draft scene. Choice from
        // start points to all four other scenes.
        $aid = (int) $this->pdo->query("SELECT id FROM adventures WHERE slug='pub-open'")->fetch()['id'];
        $insScene = $this->pdo->prepare(
            'INSERT INTO scenes (adventure_id, slug, scene_number, title, body, scene_type, state, is_start, ending_title, ending_kind, ending_body)
             VALUES (:aid, :slug, :n, :t, :b, :type, :state, :start, :et, :ek, :eb)'
        );
        $mk = static function (string $slug, int $n, string $type, string $state, int $start = 0) use ($insScene, $aid) {
            $insScene->execute([
                ':aid' => $aid, ':slug' => $slug, ':n' => $n,
                ':t' => 'Title ' . $slug, ':b' => 'Body ' . $slug,
                ':type' => $type, ':state' => $state, ':start' => $start,
                ':et' => $type === 'ending' ? 'End ' . $slug : null,
                ':ek' => $type === 'ending' ? 'kind' : null,
                ':eb' => $type === 'ending' ? 'End body' : null,
            ]);
        };
        $mk('start',   1, 'story',   'published', 1);
        $mk('mid',     2, 'story',   'published');
        $mk('finale',  3, 'ending',  'published');
        $mk('secret',  4, 'story',   'hidden');
        $mk('rough',   5, 'story',   'draft');

        $ids = [];
        foreach ($this->pdo->query("SELECT id, slug FROM scenes WHERE adventure_id=$aid") as $r) {
            $ids[$r['slug']] = (int) $r['id'];
        }
        $insChoice = $this->pdo->prepare(
            'INSERT INTO choices (scene_id, target_scene_id, label, position) VALUES (:s, :t, :l, :p)'
        );
        $insChoice->execute([':s' => $ids['start'], ':t' => $ids['mid'],    ':l' => 'to mid',    ':p' => 0]);
        $insChoice->execute([':s' => $ids['start'], ':t' => $ids['finale'], ':l' => 'to finale', ':p' => 1]);
        $insChoice->execute([':s' => $ids['start'], ':t' => $ids['secret'], ':l' => 'to hidden', ':p' => 2]);
        $insChoice->execute([':s' => $ids['start'], ':t' => $ids['rough'],  ':l' => 'to draft',  ':p' => 3]);
    }

    private function repo(): PublicRepository
    {
        return new PublicRepository($this->pdo);
    }

    // ── migration & schema ────────────────────────────────────

    public function testMigrationCreatesAllTables(): void
    {
        $names = array_column(
            $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(),
            'name'
        );
        foreach (['adventures','choices','content_warnings','scenes','users','schema_migrations'] as $t) {
            assert_true(in_array($t, $names, true), "table $t must exist");
        }
    }

    public function testCheckConstraintsRejectInvalidEnums(): void
    {
        assert_throws(function () {
            $this->pdo->exec("INSERT INTO adventures (slug, title, author_id, state) VALUES ('bad','Bad',1,'whatever')");
        });
        assert_throws(function () {
            $this->pdo->exec("INSERT INTO adventures (slug, title, author_id, visibility) VALUES ('bad2','Bad',1,'secret')");
        });
    }

    // ── discover visibility ───────────────────────────────────

    public function testDiscoverExcludesDraftSuspendedAndUnlisted(): void
    {
        $out = $this->repo()->discover([]);
        $slugs = array_column($out, 'slug');
        assert_true(in_array('pub-open', $slugs, true));
        assert_true(in_array('on-hold-adv', $slugs, true));
        assert_true(in_array('archived-adv', $slugs, true));
        assert_true(!in_array('unlisted-adv', $slugs, true), 'unlisted must not appear');
        assert_true(!in_array('draft-adv', $slugs, true), 'draft must not appear');
        assert_true(!in_array('suspended-adv', $slugs, true), 'suspended must not appear');
    }

    public function testDiscoverFiltersAndSort(): void
    {
        $repo = $this->repo();

        $byGenre = $repo->discover(['genre' => 'fantasy']);
        foreach ($byGenre as $a) { assert_same('fantasy', $a['genre']); }

        $byRating = $repo->discover(['rating' => 'teen']);
        assert_same(1, count($byRating));
        assert_same('pub-approval', $byRating[0]['slug']);

        $open = $repo->discover(['contributions' => 'open']);
        foreach ($open as $a) { assert_true($a['contributionsOpen']); }
        $closed = $repo->discover(['contributions' => 'closed']);
        foreach ($closed as $a) { assert_true(!$a['contributionsOpen']); }

        $q = $repo->discover(['q' => 'archived']);
        assert_same(1, count($q));
        assert_same('archived-adv', $q[0]['slug']);

        $title = $repo->discover(['sort' => 'title']);
        $titles = array_column($title, 'title');
        $copy = $titles; sort($copy, SORT_NATURAL | SORT_FLAG_CASE);
        assert_same($copy, $titles);

        $oldest = $repo->discover(['sort' => 'oldest']);
        assert_same('archived-adv', $oldest[0]['slug']);
    }

    // ── adventure lookup ─────────────────────────────────────

    public function testAdventureBySlugRejectsPrivateStates(): void
    {
        $repo = $this->repo();
        assert_same(null, $repo->adventureBySlug('draft-adv'));
        assert_same(null, $repo->adventureBySlug('suspended-adv'));
        assert_same(null, $repo->adventureBySlug('does-not-exist'));
    }

    public function testAdventureBySlugAllowsUnlisted(): void
    {
        $adv = $this->repo()->adventureBySlug('unlisted-adv');
        assert_true(is_array($adv));
        assert_same('unlisted-adv', $adv['slug']);
    }

    public function testAdventureBySlugCountsPublishedScenes(): void
    {
        $adv = $this->repo()->adventureBySlug('pub-open');
        assert_same(3, $adv['sceneCount']);  // start, mid, finale
        assert_same(1, $adv['endingCount']);
    }

    // ── scene lookup ─────────────────────────────────────────

    public function testSceneHidesUnpublishedDestinations(): void
    {
        $scene = $this->repo()->scene('pub-open', 'start');
        assert_true(is_array($scene));
        $targets = array_column($scene['choices'], 'target');
        assert_true(in_array('mid', $targets, true));
        assert_true(in_array('finale', $targets, true));
        assert_true(!in_array('secret', $targets, true), 'hidden destination must be filtered');
        assert_true(!in_array('rough', $targets, true), 'draft destination must be filtered');
    }

    public function testSceneReturnsNullForHiddenOrDraftOrPrivate(): void
    {
        $repo = $this->repo();
        assert_same(null, $repo->scene('pub-open', 'secret'));
        assert_same(null, $repo->scene('pub-open', 'rough'));
        assert_same(null, $repo->scene('draft-adv', 'start'));
        assert_same(null, $repo->scene('pub-open', 'does-not-exist'));
    }

    public function testEndingSceneCarriesEndingPayload(): void
    {
        $scene = $this->repo()->scene('pub-open', 'finale');
        assert_true(isset($scene['ending']));
        assert_same([], $scene['choices']);
        assert_same('End finale', $scene['ending']['title']);
    }

    // ── outline ──────────────────────────────────────────────

    public function testOutlineHidesUnpublishedScenesAndEdges(): void
    {
        $outline = $this->repo()->outline('pub-open');
        $slugs = array_column($outline['scenes'], 'id');
        assert_same(['start','mid','finale'], $slugs);
        // start's edges list only published targets
        $start = $outline['scenes'][0];
        $targets = array_column($start['choices'], 'target');
        sort($targets);
        assert_same(['finale','mid'], $targets);
    }

    public function testOutlineReturnsNullForPrivateAdventure(): void
    {
        assert_same(null, $this->repo()->outline('draft-adv'));
    }
}

// ── endpoint integration ────────────────────────────────────
//
// Exercise the routing layer in public/api/index.php against the
// same seeded database by invoking it via PHP's built-in server.

final class BPPublicApiIntegrationTest
{
    private string $tmpDb;
    private string $lock;
    /** @var resource|null */
    private $proc = null;
    private array $pipes = [];
    private int $port;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-api-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->lock  = sys_get_temp_dir() . '/bp-api-' . bin2hex(random_bytes(6)) . '.lock';
        // Seed the DB the same way BPPublicRepositoryTest does.
        $pdo = Database::open($this->tmpDb);
        (new Migrator($pdo))->migrate();
        $pdo->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'a', 'Author A')");
        $pdo->exec("INSERT INTO adventures (slug, title, author_id, state, visibility, contribution_state, updated_at)
                    VALUES ('pub','Pub',1,'published','public','closed','2026-01-01T00:00Z'),
                           ('drf','Drf',1,'draft','public','closed','2026-01-02T00:00Z')");
        $aid = (int) $pdo->query("SELECT id FROM adventures WHERE slug='pub'")->fetch()['id'];
        $pdo->exec("INSERT INTO scenes (adventure_id, slug, scene_number, title, body, state, is_start) VALUES
                    ($aid,'start',1,'Start','body','published',1),
                    ($aid,'secret',2,'Secret','body','hidden',0)");

        // Boot PHP's built-in server on a free port.
        $this->port = 18000 + random_int(0, 900);
        $env = array_merge($_ENV, [
            'DATABASE_PATH'   => $this->tmpDb,
            'WRITE_LOCK_PATH' => $this->lock,
            'APP_ENV'         => 'test',
        ]);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d -t %s %s',
            PHP_BINARY,
            $this->port,
            escapeshellarg(BP_ROOT . '/public'),
            escapeshellarg(BP_ROOT . '/public/api/index.php')
        );
        $this->proc = proc_open($cmd, $descriptors, $this->pipes, BP_ROOT, $env);
        // Wait for the server to start.
        for ($i = 0; $i < 40; $i++) {
            $ok = @file_get_contents("http://127.0.0.1:{$this->port}/api/health");
            if ($ok !== false) return;
            usleep(50_000);
        }
        throw new RuntimeException('built-in PHP server did not start');
    }

    public function tearDown(): void
    {
        if (is_resource($this->proc)) {
            foreach ($this->pipes as $p) { if (is_resource($p)) @fclose($p); }
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm', $this->lock] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    private function get(string $path): array
    {
        $url = "http://127.0.0.1:{$this->port}{$path}";
        $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; }
        }
        return [$status, $body === false ? null : json_decode($body, true)];
    }

    public function testDiscoverEndpointReturnsPublicOnly(): void
    {
        [$status, $body] = $this->get('/api/adventures');
        assert_same(200, $status);
        $slugs = array_column($body['adventures'], 'slug');
        assert_true(in_array('pub', $slugs, true));
        assert_true(!in_array('drf', $slugs, true));
    }

    public function testAdventureEndpoint404sForDraft(): void
    {
        [$status] = $this->get('/api/adventures/drf');
        assert_same(404, $status);
    }

    public function testSceneEndpointHidesUnpublished(): void
    {
        [$status1] = $this->get('/api/adventures/pub/scenes/secret');
        assert_same(404, $status1);
        [$status2, $body] = $this->get('/api/adventures/pub/scenes/start');
        assert_same(200, $status2);
        assert_same('start', $body['scene']['id']);
    }

    public function testOutlineEndpointExcludesHidden(): void
    {
        [$status, $body] = $this->get('/api/adventures/pub/outline');
        assert_same(200, $status);
        $ids = array_column($body['scenes'], 'id');
        assert_same(['start'], $ids);
    }
}
