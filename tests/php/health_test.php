<?php

declare(strict_types=1);

final class BPHealthEndpointTest
{
    private ?array $server = null;
    private string $tmpRoot;

    public function setUp(): void
    {
        // Isolate DB and lock paths so this test does not touch the
        // real project state.
        $this->tmpRoot = sys_get_temp_dir() . '/bp-health-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/data', 0777, true);
        mkdir($this->tmpRoot . '/locks', 0777, true);
        mkdir($this->tmpRoot . '/logs', 0777, true);
        putenv('DATABASE_PATH=' . $this->tmpRoot . '/data/test.sqlite');
        putenv('WRITE_LOCK_PATH=' . $this->tmpRoot . '/locks/write.lock');
    }

    public function tearDown(): void
    {
        putenv('DATABASE_PATH');
        putenv('WRITE_LOCK_PATH');
        // Best-effort cleanup.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmpRoot);
    }

    /**
     * Invoke public/api/index.php in a child PHP process and capture
     * its stdout. We cannot include it directly because bootstrap
     * sets headers and calls exit(); a subprocess gives clean
     * isolation and matches what the web server actually does.
     */
    private function get(string $uri): array
    {
        $script = BP_ROOT . '/public/api/index.php';
        $env = [
            'REQUEST_METHOD'  => 'GET',
            'REQUEST_URI'     => $uri,
            'DATABASE_PATH'   => getenv('DATABASE_PATH'),
            'WRITE_LOCK_PATH' => getenv('WRITE_LOCK_PATH'),
            'PATH'            => getenv('PATH') ?: '',
        ];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $script], $descriptors, $pipes, null, $env);
        assert_true(is_resource($proc));
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        return ['stdout' => (string) $out, 'stderr' => (string) $err, 'exit' => $code];
    }

    public function testHealthReturnsOnlyWhitelistedKeys(): void
    {
        $res = $this->get('/api/health');
        $decoded = json_decode($res['stdout'], true);
        assert_true(is_array($decoded), 'health response must be JSON — got ' . $res['stdout']);
        $keys = array_keys($decoded);
        sort($keys);
        assert_same(['api', 'app_version', 'database', 'schema_version'], $keys);
        assert_same('ok', $decoded['api']);
        assert_same('ok', $decoded['database']);
        assert_same('0', $decoded['schema_version']);
    }

    public function testHealthDoesNotLeakPathsOrTraces(): void
    {
        $res = $this->get('/api/health');
        $body = $res['stdout'];
        // Response must not reveal filesystem paths, class names,
        // stack frames, or SQL error snippets.
        foreach ([BP_ROOT, sys_get_temp_dir(), 'Stack trace', '#0', 'PDOException', 'SQLSTATE'] as $needle) {
            assert_true(
                strpos($body, $needle) === false,
                "health body must not contain '$needle': $body"
            );
        }
    }

    public function testUnknownRouteReturnsGenericNotFound(): void
    {
        $res = $this->get('/api/does-not-exist');
        $decoded = json_decode($res['stdout'], true);
        assert_true(is_array($decoded));
        assert_same(['error' => 'not_found'], $decoded);
    }
}
