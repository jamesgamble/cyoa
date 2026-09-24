<?php
/**
 * Release regressions found during the 1.0.0 end-to-end check.
 */
declare(strict_types=1);

use App\Database;
use App\Migrator;
use App\SettingsRepository;
use App\CollaborationService;

final class BPReleaseTest
{
    private string $db;

    public function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/bp-release-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    public function tearDown(): void
    {
        foreach ([$this->db, $this->db . '-wal', $this->db . '-shm'] as $f) @unlink($f);
    }

    /** Every App class the front controller references must be imported or fully qualified. */
    public function testFrontControllerImportsEveryClassItUses(): void
    {
        $src = (string) file_get_contents(BP_ROOT . '/public/api/index.php');
        preg_match_all('/(?<![\\\\\w])(?:new\s+([A-Z]\w+)\s*\(|([A-Z]\w+)::)/', $src, $m);
        $used = array_unique(array_filter(array_merge($m[1], $m[2])));
        foreach ($used as $class) {
            if (in_array($class, ['PDO', 'PDOException', 'RuntimeException', 'Throwable', 'DateTimeImmutable'], true)) continue;
            assert_true(
                preg_match('/^use App\\\\' . $class . ';/m', $src) === 1,
                "public/api/index.php uses $class without importing it"
            );
        }
    }

    /** A fresh install must build email links from APP_URL, not a development address. */
    public function testFreshInstallEmailLinksUseAppUrl(): void
    {
        $pdo = Database::open($this->db);
        (new Migrator($pdo))->migrate();
        assert_same(null, (new SettingsRepository($pdo))->get('canonical_url'));
        $url = (new CollaborationService($pdo))->canonicalUrl();
        assert_same(rtrim(bp_config()['app']['url'], '/'), $url);
        assert_true(!str_contains((new CollaborationService($pdo))->inviteUrl('x'), 'localhost:8000') || bp_config()['app']['url'] === 'http://localhost:8000');
    }

    /** An operator-chosen canonical address survives the 1.0.0 migration. */
    public function testOperatorCanonicalUrlIsKept(): void
    {
        $pdo = Database::open($this->db);
        $sql = (string) file_get_contents(BP_ROOT . '/private/migrations/0016_canonical_url_default.sql');
        $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO settings VALUES ('canonical_url', 'https://stories.example.org')");
        $pdo->exec($sql);
        assert_same('https://stories.example.org', $pdo->query("SELECT value FROM settings WHERE key='canonical_url'")->fetchColumn());
    }

    /** Both web-server configurations send a same-origin CSP and the standard hardening headers. */
    public function testWebServerConfigsSendSecurityHeaders(): void
    {
        foreach (['public/.htaccess', 'docs/deploy/nginx.conf.example'] as $f) {
            $src = (string) file_get_contents(BP_ROOT . '/' . $f);
            foreach (['Content-Security-Policy', "default-src 'self'", "frame-ancestors 'none'", "object-src 'none'", 'Permissions-Policy', 'X-Frame-Options', 'Referrer-Policy', 'nosniff'] as $needle) {
                assert_true(str_contains($src, $needle), "$f missing $needle");
            }
            assert_true(!preg_match('/https?:\/\/[^\s"]*/', (string) preg_replace('/^.*(Content-Security-Policy[^\n]*).*$/s', '$1', $src)), "$f CSP must not allow external origins");
        }
    }
}
