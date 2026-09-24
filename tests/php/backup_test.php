<?php
/**
 * Version 0.27.0 — backups, restore, retention, integrity, maintenance
 * controls, and clean install.
 */
declare(strict_types=1);

use App\BackupService;
use App\Database;
use App\MasterService;
use App\Migrator;
use App\SettingsRepository;
use App\WriteLock;

final class BPBackupTest
{
    private string $dir;
    private string $db;
    private BackupService $svc;
    private WriteLock $lock;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bp-backup-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/data', 0777, true);
        $this->db = $this->dir . '/data/db.sqlite';
        $pdo = Database::open($this->db);
        (new Migrator($pdo))->migrate();
        $this->lock = new WriteLock($this->dir . '/write.lock', 1000);
        $this->svc = new BackupService($this->db, $this->dir . '/backups', $this->lock);
    }

    public function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($this->dir);
    }

    private function setting(string $k, string $v): void
    {
        (new SettingsRepository(Database::open($this->db)))->set($k, $v);
    }

    private function get(string $k): ?string
    {
        return (new SettingsRepository(Database::open($this->db)))->get($k);
    }

    public function testBackupIsConsistentSnapshotWithChecksum(): void
    {
        $this->setting('maintenance_message', 'snapshot-me');
        // Hold an open WAL reader to prove backup works on an active db.
        $reader = Database::open($this->db);
        $reader->beginTransaction();
        $reader->query('SELECT COUNT(*) FROM settings')->fetch();
        $name = $this->svc->backup('test');
        $reader->commit();
        $path = $this->dir . '/backups/' . $name;
        assert_true(is_file($path));
        assert_true(str_contains($name, '-test.sqlite'));
        assert_true(BackupService::integrityOf($path)['ok']);
        $sum = strtok((string) file_get_contents($path . '.sha256'), ' ');
        assert_same(hash_file('sha256', $path), $sum);
        $copy = new PDO('sqlite:' . $path);
        assert_same('snapshot-me', $copy->query("SELECT value FROM settings WHERE key='maintenance_message'")->fetchColumn());
        assert_true(!is_file($path . '-wal'), 'snapshot is self-contained');
    }

    public function testBackupWaitsForWriteLock(): void
    {
        $other = new WriteLock($this->dir . '/write.lock', 200);
        $other->acquire();
        try {
            assert_throws(fn () => $this->svc->backup(), 'write lock timeout');
        } finally {
            $other->release();
        }
        assert_same([], $this->svc->list());
    }

    public function testRestoreRoundTripAndSafetyBackup(): void
    {
        $this->setting('maintenance_message', 'before');
        $name = $this->svc->backup();
        $this->setting('maintenance_message', 'after');
        $safety = $this->svc->restore($name);
        assert_same('before', $this->get('maintenance_message'));
        assert_true(str_contains($safety, 'pre-restore'));
        $sp = new PDO('sqlite:' . $this->dir . '/backups/' . $safety);
        assert_same('after', $sp->query("SELECT value FROM settings WHERE key='maintenance_message'")->fetchColumn());
        assert_true($this->svc->integrity()['ok']);
    }

    public function testRestoreRejectsTamperedCorruptAndUnsafeNames(): void
    {
        $name = $this->svc->backup();
        $path = $this->dir . '/backups/' . $name;
        file_put_contents($path, 'x', FILE_APPEND);
        assert_throws(fn () => $this->svc->restore($name), 'checksum');
        $bad = BackupService::PREFIX . 'corrupt.sqlite';
        file_put_contents($this->dir . '/backups/' . $bad, str_repeat('garbage', 100));
        assert_throws(fn () => $this->svc->restore($bad), 'integrity');
        assert_throws(fn () => $this->svc->restore('../data/db.sqlite'), 'invalid backup name');
        assert_throws(fn () => $this->svc->restore(BackupService::PREFIX . 'missing.sqlite'), 'not found');
        assert_true($this->svc->integrity()['ok'], 'live db untouched');
    }

    public function testRetentionKeepsNewestAndDaily(): void
    {
        $now = time();
        $b = $this->dir . '/backups/';
        for ($i = 0; $i < 10; $i++) {
            $n = BackupService::PREFIX . sprintf('f%02d', $i) . '.sqlite';
            touch($b . $n, $now - $i * 43200); // two per day
            touch($b . $n . '.sha256');
        }
        $old = BackupService::PREFIX . 'ancient.sqlite';
        touch($b . $old, $now - 90 * 86400);
        $deleted = $this->svc->prune(3, 30, $now);
        assert_true(in_array($old, $deleted, true), 'outside window pruned');
        $left = array_column($this->svc->list(), 'name');
        foreach (['f00', 'f01', 'f02'] as $k) assert_true(in_array(BackupService::PREFIX . $k . '.sqlite', $left, true));
        $days = [];
        foreach ($this->svc->list() as $x) $days[] = gmdate('Y-m-d', $x['mtime']);
        assert_true(count($left) >= 5 && count($left) < 10);
        foreach ($deleted as $d) assert_true(!is_file($b . $d . '.sha256'));
    }

    public function testIntegrityDetectsForeignKeyViolation(): void
    {
        assert_true($this->svc->integrity()['ok']);
        $raw = new PDO('sqlite:' . $this->db);
        $raw->exec('PRAGMA foreign_keys = OFF');
        $raw->exec("INSERT INTO platform_activity (actor_id, action) VALUES (999999, 'x')");
        $r = $this->svc->integrity();
        assert_same(false, $r['ok']);
        assert_true(str_contains(implode(' ', $r['problems']), 'platform_activity'));
        assert_same(false, BackupService::integrityOf($this->dir . '/nope.sqlite')['ok']);
    }

    public function testMaintenanceControlsDefaultsAndStatus(): void
    {
        $pdo = Database::open($this->db);
        $s = MasterService::maintenanceStatus($pdo);
        assert_same(false, $s['read_only']);
        assert_same(true, $s['new_adventures_enabled']);
        assert_same(false, $s['contributions_paused']);
        foreach (['maintenance_mode', 'maintenance_message', 'registration_enabled', 'new_adventures_enabled', 'contributions_globally_paused'] as $k) {
            assert_true(array_key_exists($k, MasterService::SETTING_GROUPS['maintenance']), $k);
        }
        $this->setting('maintenance_mode', '1');
        $this->setting('maintenance_message', 'Back soon');
        $s = MasterService::maintenanceStatus($pdo);
        assert_same(true, $s['read_only']);
        assert_same('Back soon', $s['notice']);
        assert_same(false, isset($s['smtp']) || isset($s['path']));
    }

    public function testNewAdventuresDisabledBlocksCreation(): void
    {
        $pdo = Database::open($this->db);
        $repo = new App\UserRepository($pdo);
        $h = App\PasswordHasher::hash('correct horse battery staple');
        $uid = (int) $repo->insert(['email' => 'a@example.com', 'username' => 'aa', 'display_name' => 'A',
            'password_hash' => $h['hash'], 'password_algo' => $h['algo'], 'status' => 'active',
            'terms_accepted_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->setting('new_adventures_enabled', '0');
        [$status, $errors] = (new App\AdventureService($pdo))->create($uid, []);
        assert_same(App\AdventureService::FORBIDDEN, $status);
        assert_same('creation_disabled', $errors['account']);
    }

    public function testGlobalContributionPause(): void
    {
        $pdo = Database::open($this->db);
        $svc = new App\BranchSubmissionService($pdo);
        $adv = ['contribution_state' => 'immediate', 'allow_branching' => 1, 'contributions_paused' => 0, 'state' => 'published'];
        $open = $svc->contributionsEnabled($adv);
        $this->setting('contributions_globally_paused', '1');
        assert_same(false, $svc->contributionsEnabled($adv));
        $this->setting('contributions_globally_paused', '0');
        assert_same($open, $svc->contributionsEnabled($adv));
    }

    public function testReadOnlyGateAllowsReadsAndAdminPaths(): void
    {
        $src = (string) file_get_contents(BP_ROOT . '/public/api/index.php');
        assert_true(str_contains($src, "\$method !== 'GET'"), 'reads always pass');
        assert_true(str_contains($src, "strncmp(\$route, '/master', 7) !== 0"), 'admin maintenance passes');
        assert_true(str_contains($src, "strncmp(\$route, '/auth', 5) !== 0"), 'admin login passes');
        assert_true(str_contains($src, "\$route === '/status'"));
    }

    public function testCleanInstall(): void
    {
        $root = $this->dir . '/fresh';
        $env = 'DATABASE_PATH=' . escapeshellarg($root . '/data/x.sqlite') . ' WRITE_LOCK_PATH=' . escapeshellarg($root . '/locks/w.lock');
        exec($env . ' ' . PHP_BINARY . ' ' . escapeshellarg(BP_ROOT . '/scripts/initialize.php') . ' 2>&1', $o1, $c1);
        assert_same(0, $c1, implode("\n", $o1));
        exec($env . ' ' . PHP_BINARY . ' ' . escapeshellarg(BP_ROOT . '/scripts/migrate.php') . ' 2>&1', $o2, $c2);
        assert_same(0, $c2, implode("\n", $o2));
        exec($env . ' ' . PHP_BINARY . ' ' . escapeshellarg(BP_ROOT . '/scripts/integrity-check.php') . ' 2>&1', $o3, $c3);
        assert_same(0, $c3, implode("\n", $o3));
        exec($env . ' ' . PHP_BINARY . ' ' . escapeshellarg(BP_ROOT . '/scripts/system-check.php') . ' 2>&1', $o4, $c4);
        $out = implode("\n", $o4);
        assert_true(str_contains($out, 'no pending migrations'), $out);
        assert_true(str_contains($out, 'integrity_check ok'), $out);
        assert_true(str_contains($out, 'private/ is outside public/'), $out);
        $m = new Migrator(Database::open($root . '/data/x.sqlite'));
        assert_true(in_array('0015', array_map(fn ($v) => substr((string) $v, 0, 4), $m->appliedVersions()), true));
    }

    public function testHostingFilesProtectPrivateData(): void
    {
        foreach (['private', 'app', 'scripts'] as $d) {
            assert_true(str_contains((string) file_get_contents(BP_ROOT . "/$d/.htaccess"), 'Require all denied'), $d);
        }
        $pub = (string) file_get_contents(BP_ROOT . '/public/.htaccess');
        assert_true(str_contains($pub, 'sqlite') && str_contains($pub, 'api/index.php') && str_contains($pub, 'index.html'));
        foreach (['nginx.conf.example', 'apache.conf.example', 'crontab.example'] as $f) assert_true(is_file(BP_ROOT . "/docs/deploy/$f"), $f);
        $cron = (string) file_get_contents(BP_ROOT . '/docs/deploy/crontab.example');
        assert_true(str_contains($cron, 'process-email-queue.php') && str_contains($cron, 'backup.php'));
        $h = (string) file_get_contents(BP_ROOT . '/docs/HOSTING.md');
        foreach (['## Upgrade', '## Rollback', '## Writable directories', 'pdo_sqlite', 'fallback'] as $k) assert_true(str_contains($h, $k), $k);
    }
}
