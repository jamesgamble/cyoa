<?php

declare(strict_types=1);

use App\WriteLock;

final class BPWriteLockTest
{
    private string $lockPath;

    public function setUp(): void
    {
        $this->lockPath = sys_get_temp_dir() . '/bp-lock-' . bin2hex(random_bytes(6)) . '.lock';
    }

    public function tearDown(): void
    {
        if (is_file($this->lockPath)) @unlink($this->lockPath);
    }

    public function testAcquiresAndReleases(): void
    {
        $lock = new WriteLock($this->lockPath, 1000);
        assert_true(!$lock->isHeld());
        $lock->acquire();
        assert_true($lock->isHeld());
        $lock->release();
        assert_true(!$lock->isHeld());
    }

    public function testReleaseIsIdempotent(): void
    {
        $lock = new WriteLock($this->lockPath, 1000);
        $lock->acquire();
        $lock->release();
        $lock->release(); // no-op, must not throw
        assert_true(!$lock->isHeld());
    }

    public function testWithLockReleasesEvenOnThrow(): void
    {
        $lock = new WriteLock($this->lockPath, 1000);
        try {
            $lock->withLock(function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $_) {
            // expected
        }
        assert_true(!$lock->isHeld(), 'withLock must release on throw');
        // Should now be acquirable again by a new instance.
        $lock2 = new WriteLock($this->lockPath, 500);
        $lock2->acquire();
        assert_true($lock2->isHeld());
        $lock2->release();
    }

    public function testContendingHolderTimesOut(): void
    {
        // Acquire the lock in a child PHP process, keep it for a bit,
        // then release. Meanwhile the parent tries to acquire with a
        // short timeout and must fail with a bounded wait.
        $script = tempnam(sys_get_temp_dir(), 'bp-child-');
        rename($script, $script . '.php');
        $script .= '.php';
        file_put_contents($script, "<?php\n" .
            'require ' . var_export(BP_ROOT, true) . " . '/app/bootstrap.php';\n" .
            '$lock = new App\\WriteLock(' . var_export($this->lockPath, true) . ", 2000);\n" .
            "\$lock->acquire();\n" .
            "fwrite(STDOUT, \"holding\\n\"); fflush(STDOUT);\n" .
            "usleep(1500 * 1000);\n" .
            "\$lock->release();\n"
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $script], $descriptors, $pipes);
        assert_true(is_resource($proc), 'child process could not start');
        // Wait until the child reports it is holding the lock.
        $line = fgets($pipes[1]);
        assert_true($line !== false && trim($line) === 'holding', 'child did not confirm hold');

        $lock = new WriteLock($this->lockPath, 200);
        $started = microtime(true);
        assert_throws(fn () => $lock->acquire(), 'timeout');
        $elapsedMs = (microtime(true) - $started) * 1000;
        assert_true($elapsedMs >= 150, "acquire returned too fast ({$elapsedMs}ms)");
        assert_true($elapsedMs <  1200, "acquire waited too long ({$elapsedMs}ms) — must be bounded");

        // Wait for the child so its lock is released and we can clean up.
        proc_close($proc);
        @unlink($script);
    }
}
