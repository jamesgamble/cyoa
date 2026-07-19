<?php

declare(strict_types=1);

use App\Database;
use App\Migrator;

final class BPDatabaseTest
{
    private string $tmpDb;

    public function setUp(): void
    {
        $this->tmpDb = sys_get_temp_dir() . '/bp-test-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    public function testOpensAndAppliesPragmas(): void
    {
        $pdo = Database::open($this->tmpDb);
        assert_same('wal', Database::journalMode($pdo));
        assert_true(Database::foreignKeysEnabled($pdo));
        $busy = (int) $pdo->query('PRAGMA busy_timeout')->fetch()['timeout'];
        assert_true($busy >= 10000, 'busy_timeout should be >= 10000ms');
        $sync = (int) $pdo->query('PRAGMA synchronous')->fetch()['synchronous'];
        assert_same(1, $sync, 'synchronous should be NORMAL (1)');
    }

    public function testForeignKeysAreEnforced(): void
    {
        $pdo = Database::open($this->tmpDb);
        $pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent(id))');
        assert_throws(function () use ($pdo) {
            $pdo->exec('INSERT INTO child (parent_id) VALUES (999)');
        });
    }

    public function testSurvivesReopenWithWal(): void
    {
        $pdo = Database::open($this->tmpDb);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('hello')");
        unset($pdo);

        $pdo2 = Database::open($this->tmpDb);
        $row = $pdo2->query('SELECT v FROM t')->fetch();
        assert_same('hello', $row['v']);
        assert_same('wal', Database::journalMode($pdo2));
    }
}

final class BPMigratorTest
{
    private string $tmpDb;
    private string $migDir;

    public function setUp(): void
    {
        $this->tmpDb  = sys_get_temp_dir() . '/bp-migtest-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->migDir = sys_get_temp_dir() . '/bp-mig-' . bin2hex(random_bytes(6));
        mkdir($this->migDir, 0777, true);
    }

    public function tearDown(): void
    {
        foreach ([$this->tmpDb, $this->tmpDb . '-wal', $this->tmpDb . '-shm'] as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (is_dir($this->migDir)) {
            foreach (glob($this->migDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->migDir);
        }
    }

    public function testTracksAppliedMigrations(): void
    {
        file_put_contents($this->migDir . '/0001_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        file_put_contents($this->migDir . '/0002_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY);');
        $pdo = Database::open($this->tmpDb);
        $m = new Migrator($pdo, $this->migDir);
        assert_same('0', $m->currentVersion());
        $ran = $m->migrate();
        assert_same(['0001_a', '0002_b'], $ran);
        assert_same('0002_b', $m->currentVersion());

        // Second run applies nothing.
        assert_same([], $m->migrate());
    }

    public function testRollsBackFailedMigration(): void
    {
        file_put_contents($this->migDir . '/0001_ok.sql', 'CREATE TABLE ok (id INTEGER PRIMARY KEY);');
        file_put_contents($this->migDir . '/0002_bad.sql',
            "CREATE TABLE bad (id INTEGER PRIMARY KEY);\nTHIS IS NOT SQL;"
        );
        $pdo = Database::open($this->tmpDb);
        $m = new Migrator($pdo, $this->migDir);

        assert_throws(fn () => $m->migrate(), 'failed');
        // First migration should have been applied; second must not exist.
        assert_same('0001_ok', $m->currentVersion());
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll();
        $names = array_map(static fn ($r) => $r['name'], $tables);
        assert_true(in_array('ok', $names, true), 'ok table should exist');
        assert_true(!in_array('bad', $names, true), 'bad table must not exist after rollback');
    }
}
