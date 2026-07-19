<?php
/**
 * WriteLock — a bounded, file-based advisory lock built on `flock()`.
 *
 * Branching Paths keeps write serialisation outside of SQLite so that
 * long-running writes (migrations, bulk inserts, snapshot exports) do
 * not block the many short-lived read transactions that serve the UI.
 * Every writer acquires the lock, does its work in a short transaction,
 * and releases the lock.
 *
 * The lock is bounded — `acquire()` will retry for up to `timeoutMs`
 * milliseconds and then throw. This guarantees a request will never
 * hang indefinitely on a lost or stalled writer.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;

final class WriteLock
{
    /** @var resource|null */
    private $handle = null;
    private bool $held = false;
    private string $path;
    private int $timeoutMs;

    public function __construct(?string $path = null, ?int $timeoutMs = null)
    {
        $config = bp_config();
        $this->path      = $path ?? $config['lock']['path'];
        $this->timeoutMs = $timeoutMs ?? (int) $config['lock']['timeout_ms'];

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('lock directory is not writable');
        }
    }

    /**
     * Try to acquire an exclusive lock, waiting up to `timeoutMs`
     * milliseconds. Throws on timeout. Returns silently on success.
     */
    public function acquire(?int $timeoutMs = null): void
    {
        if ($this->held) {
            return;
        }
        $deadlineMs = $this->nowMs() + ($timeoutMs ?? $this->timeoutMs);

        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException('lock file could not be opened');
        }
        $this->handle = $handle;

        // Poll for a non-blocking exclusive lock. This keeps the wait
        // strictly bounded regardless of how `flock()` behaves on the
        // underlying filesystem.
        $attempts = 0;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                $this->held = true;
                // Record the pid for post-mortem diagnostics only —
                // never surfaced to callers.
                @ftruncate($handle, 0);
                @fwrite($handle, (string) getmypid());
                @fflush($handle);
                return;
            }
            $attempts++;
            // Back off between 5 and 50 ms.
            $sleepUs = min(50000, 5000 * $attempts);
            usleep($sleepUs);
        } while ($this->nowMs() < $deadlineMs);

        // Give up — release the handle so we do not leak descriptors.
        fclose($handle);
        $this->handle = null;
        throw new RuntimeException('write lock timeout');
    }

    /**
     * Release the lock if held. Safe to call repeatedly.
     */
    public function release(): void
    {
        if (!$this->held || $this->handle === null) {
            $this->held = false;
            if ($this->handle !== null) {
                @fclose($this->handle);
                $this->handle = null;
            }
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
        $this->held = false;
    }

    /**
     * Run `$fn` while holding the lock. Guarantees release even when
     * `$fn` throws.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function withLock(callable $fn)
    {
        $this->acquire();
        try {
            return $fn();
        } finally {
            $this->release();
        }
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        // Best-effort cleanup in case a caller forgot to release.
        $this->release();
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
