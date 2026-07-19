<?php
/**
 * tests/php/run.php — tiny PHPUnit-free test runner.
 *
 * Each test is a public function on a class named *Test. `run()`
 * instantiates every class, invokes every public method whose name
 * starts with `test`, and reports pass / fail counts. Failed
 * assertions throw; the runner catches them and continues.
 *
 * Usage:
 *   php tests/php/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

// ── minimalist assertions ──
function assert_true(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException('assert_true failed' . ($msg !== '' ? ': ' . $msg : ''));
    }
}
function assert_same($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'assert_same failed' . ($msg !== '' ? ': ' . $msg : '') .
            ' — expected ' . var_export($expected, true) .
            ' got ' . var_export($actual, true)
        );
    }
}
function assert_throws(callable $fn, string $substring = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($substring !== '' && strpos($e->getMessage(), $substring) === false) {
            throw new RuntimeException(
                'assert_throws: caught but message missing "' . $substring .
                '" — got "' . $e->getMessage() . '"'
            );
        }
        return;
    }
    throw new RuntimeException('assert_throws: no exception was thrown');
}

// Load tests
$files = glob(__DIR__ . '/*_test.php') ?: [];
foreach ($files as $f) {
    require_once $f;
}

$classes = array_values(array_filter(
    get_declared_classes(),
    static fn ($c) => str_ends_with($c, 'Test') && str_starts_with($c, 'BP')
));

$total = 0;
$fails = 0;
foreach ($classes as $class) {
    $instance = new $class();
    $methods  = array_filter(
        get_class_methods($class) ?: [],
        static fn ($m) => str_starts_with($m, 'test')
    );
    foreach ($methods as $method) {
        $total++;
        try {
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }
            $instance->$method();
            fwrite(STDOUT, "  ok  $class::$method\n");
        } catch (\Throwable $e) {
            $fails++;
            fwrite(STDOUT, "  X   $class::$method — " . $e->getMessage() . "\n");
        } finally {
            if (method_exists($instance, 'tearDown')) {
                try { $instance->tearDown(); } catch (\Throwable $_) {}
            }
        }
    }
}
fwrite(STDOUT, "\n$total tests, $fails failed\n");
exit($fails === 0 ? 0 : 1);
