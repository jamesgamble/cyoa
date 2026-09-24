<?php
/**
 * Version 0.26.0 — exports: authorization, JSON contents, redaction,
 * plain-text formatting, safe HTML, and offline standalone HTML.
 */
declare(strict_types=1);

use App\AdventureService;
use App\Database;
use App\ExportService;
use App\Migrator;
use App\PasswordHasher;
use App\UserRepository;

final class BPExportTest
{
    private string $tmpDb;
    private \PDO $pdo;
    private int $ownerId;
    private int $editorId;
    private int $reviewerId;
    private int $strangerId;
    private int $namedId;
    private int $anonId;
    private int $advId;
    private string $slug;
    private int $rootId;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-exp-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = Database::open($this->tmpDb);
        (new Migrator($this->pdo))->migrate();
        $repo = new UserRepository($this->pdo);
        $hash = PasswordHasher::hash('correct horse battery staple');
        $mk = static fn (string $n): int => (int) $repo->insert([
            'email' => $n . '@secret-mail.test', 'username' => $n, 'display_name' => ucfirst($n) . ' Name',
            'password_hash' => $hash['hash'], 'password_algo' => $hash['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->ownerId = $mk('expowner');
        $this->editorId = $mk('expeditor');
        $this->reviewerId = $mk('expreviewer');
        $this->strangerId = $mk('expstranger');
        $this->namedId = $mk('expnamed');
        $this->anonId = $mk('exphidden');
        [$o, $e, $d] = (new AdventureService($this->pdo))->create($this->ownerId, [
            'title' => 'The Salt Road', 'description' => '<p>Three ways out of a <strong>drowned</strong> town.</p>',
            'genre' => 'mystery', 'content_rating' => 'everyone', 'content_warnings' => [],
            'visibility' => 'public', 'opening_title' => 'The jetty',
            'opening_body' => '<p>The tide is <em>out</em>.</p>', 'status' => 'published',
            'contribution_mode' => 'approval', 'anonymous_contributions' => true,
            'max_branches_per_scene' => 4,
        ]);
        assert_same('ok', $o, json_encode($e));
        $this->slug = (string) $d['slug'];
        $this->advId = (int) $d['id'];
        $this->rootId = (int) $d['opening_scene']['id'];
        $t = gmdate('c');
        $p = $this->pdo;
        $p->prepare("UPDATE adventures SET writing_guidelines = '<p>Keep it <u>kind</u>.</p><script>alert(1)</script>' WHERE id = :a")->execute([':a' => $this->advId]);
        foreach ([[$this->editorId, 'editor'], [$this->reviewerId, 'reviewer']] as [$u, $r]) {
            $p->prepare('INSERT INTO adventure_collaborators (adventure_id, user_id, role, created_at) VALUES (:a,:u,:r,:t)')
              ->execute([':a' => $this->advId, ':u' => $u, ':r' => $r, ':t' => $t]);
        }
        $p->prepare("INSERT INTO content_warnings (adventure_id, label, code, details) VALUES (:a, 'Horror', 'horror', 'Drowning imagery')")->execute([':a' => $this->advId]);
        $ins = $p->prepare("INSERT INTO scenes (adventure_id, slug, scene_number, title, body, scene_type, state, ending_title)
                            VALUES (:a, :s, :n, :t, :b, :ty, :st, :et)");
        $scene = function (string $slug, int $n, string $title, string $body, string $type, string $state, ?string $et = null) use ($ins, $p): int {
            $ins->execute([':a' => $this->advId, ':s' => $slug, ':n' => $n, ':t' => $title, ':b' => $body, ':ty' => $type, ':st' => $state, ':et' => $et]);
            return (int) $p->lastInsertId();
        };
        $cliff = $scene('cliff', 2, 'The cliff', '<p>Wind <a href="https://evil.test">link</a><img src="https://track.test/p.gif"></p>', 'story', 'published');
        $end = $scene('home', 3, 'Home', '<p>You are dry.</p>', 'ending', 'published', 'Safe harbour');
        $draft = $scene('secret-draft', 4, 'Draft cave', '<p>Unfinished DRAFTBODY</p>', 'story', 'draft');
        $hidden = $scene('hidden', 5, 'Hidden grotto', '<p>HIDDENBODY</p>', 'story', 'hidden');
        $ch = $p->prepare('INSERT INTO choices (scene_id, target_scene_id, label, position) VALUES (:s,:t,:l,:p)');
        $ch->execute([':s' => $this->rootId, ':t' => $cliff, ':l' => 'Climb the cliff', ':p' => 0]);
        $ch->execute([':s' => $this->rootId, ':t' => $draft, ':l' => 'Enter DRAFTCHOICE', ':p' => 1]);
        $ch->execute([':s' => $cliff, ':t' => $end, ':l' => 'Go home', ':p' => 0]);
        $ch->execute([':s' => $cliff, ':t' => $hidden, ':l' => 'HIDDENCHOICE', ':p' => 1]);
        $sub = $p->prepare("INSERT INTO branch_submissions (adventure_id, source_scene_id, user_id, submitted_ip, attribution,
            choice_text, choice_text_key, scene_title, scene_body, state, created_scene_id, private_note, moderator_note)
            VALUES (:a, :src, :u, '203.0.113.9', :at, 'x', :k, 'x', 'x', 'approved', :cs, 'PRIVATENOTE', 'MODNOTE')");
        $sub->execute([':a' => $this->advId, ':src' => $this->rootId, ':u' => $this->namedId, ':at' => 'display_name', ':k' => 'a', ':cs' => $cliff]);
        $sub->execute([':a' => $this->advId, ':src' => $cliff, ':u' => $this->anonId, ':at' => 'anonymous', ':k' => 'b', ':cs' => $end]);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) if (is_file($f)) @unlink($f);
    }

    private function run(string $fmt, ?int $uid = null, bool $admin = false): array
    {
        [$o, $d] = (new ExportService($this->pdo))->export($this->slug, $fmt, $uid ?? $this->ownerId, $admin);
        assert_same('ok', $o);
        return $d;
    }

    public function testOwnerEditorAndAdminMayExportOthersMayNot(): void
    {
        $svc = new ExportService($this->pdo);
        foreach (ExportService::FORMATS as $f) {
            assert_same('ok', $svc->export($this->slug, $f, $this->ownerId, false)[0]);
            assert_same('ok', $svc->export($this->slug, $f, $this->editorId, false)[0]);
            assert_same('ok', $svc->export($this->slug, $f, null, true)[0]);
            assert_same('forbidden', $svc->export($this->slug, $f, $this->reviewerId, false)[0]);
            assert_same('forbidden', $svc->export($this->slug, $f, $this->strangerId, false)[0]);
            assert_same('forbidden', $svc->export($this->slug, $f, null, false)[0]);
        }
        assert_same('bad_format', $svc->export($this->slug, 'pdf', $this->ownerId, false)[0]);
        assert_same('not_found', $svc->export('nope', 'json', $this->ownerId, false)[0]);
    }

    public function testJsonHasRequiredSectionsAndOnlyPublishedContent(): void
    {
        $d = $this->run('json');
        assert_same('The-Salt-Road', 'The-Salt-Road');
        $j = json_decode($d['body'], true);
        foreach (['schema_version','exported_at','metadata','content_warnings','writing_guidelines_html','scenes','choices','endings','attribution'] as $k) {
            assert_true(array_key_exists($k, $j), "missing $k");
        }
        assert_same(1, $j['schema_version']);
        assert_same('The Salt Road', $j['metadata']['title']);
        assert_same('horror', $j['content_warnings'][0]['code']);
        assert_same(3, count($j['scenes']));
        assert_same(2, count($j['choices']));
        assert_same('Safe harbour', $j['endings'][0]['title']);
        assert_same('Expowner Name', $j['attribution']['owner']);
        assert_same(['Expnamed Name'], $j['attribution']['contributors']);
        assert_true(!array_key_exists('_graph', $j));
    }

    public function testEveryFormatRedactsPrivateData(): void
    {
        $this->pdo->prepare("INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (:u, 'SESSIONTOKEN', :t)")
            ->execute([':u' => $this->ownerId, ':t' => gmdate('c')]);
        foreach (ExportService::FORMATS as $f) {
            $b = $this->run($f)['body'];
            foreach (['secret-mail.test', '$2y$', 'argon', 'SESSIONTOKEN', '203.0.113.9', 'PRIVATENOTE', 'MODNOTE',
                      'DRAFTBODY', 'DRAFTCHOICE', 'HIDDENBODY', 'HIDDENCHOICE', 'Exphidden', 'exphidden',
                      'smtp', 'password', 'private/', '.sqlite', 'reporter', 'ip_hash'] as $needle) {
                assert_true(stripos($b, $needle) === false, "$f leaked $needle");
            }
        }
    }

    public function testPlainTextContainsNoHtml(): void
    {
        $b = $this->run('text')['body'];
        assert_true(!preg_match('/<[a-z\/!][^>]*>/i', $b), 'tags in text');
        assert_true(strpos($b, '&lt;') === false && strpos($b, '&amp;') === false);
        assert_true(strpos($b, 'The tide is out.') !== false);
        assert_true(strpos($b, 'Climb the cliff — turn to 2') !== false);
        assert_true(strpos($b, 'THE END — Safe harbour') !== false);
    }

    public function testHtmlFormatsKeepOnlySafeFormatting(): void
    {
        foreach (['print', 'play'] as $f) {
            $b = $this->run($f)['body'];
            assert_true(strpos($b, '<em>out</em>') !== false || strpos($b, '\u003Cem\u003Eout') !== false, "$f lost formatting");
            foreach (['<a ', '<img', 'evil.test', 'track.test', 'alert(1)', '<iframe', 'onerror'] as $bad) {
                assert_true(stripos($b, $bad) === false, "$f contains $bad");
            }
        }
        $print = $this->run('print')['body'];
        assert_true(stripos($print, '<script') === false);
        assert_true(strpos($print, '@media print') !== false);
    }

    public function testStandaloneWorksOfflineWithoutPrivilegedUrls(): void
    {
        $b = $this->run('play')['body'];
        assert_true(!preg_match('/<script[^>]+src=/i', $b), 'external script');
        assert_true(!preg_match('/<link[^>]+href=/i', $b), 'external stylesheet');
        assert_true(!preg_match('#https?://#i', $b), 'absolute URL present');
        foreach (['/api', '/manage', '/master', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'analytics', 'gtag', 'approve', 'reject', 'moderat', 'contenteditable', '<form'] as $bad) {
            assert_true(stripos($b, $bad) === false, "play contains $bad");
        }
        assert_true(strpos($b, "connect-src 'none'") !== false);
        preg_match('#<script type="application/json" id="bp-data">(.*?)</script>#s', $b, $m);
        $data = json_decode($m[1] ?? '', true);
        assert_same($this->rootId, $data['start']);
        assert_same(3, count($data['scenes']));
        assert_same('Climb the cliff', $data['scenes'][(string) $this->rootId]['c'][0]['text']);
    }
}
