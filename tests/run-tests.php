<?php

/**
 * @file tests/run-tests.php
 *
 * Zero-dependency unit test runner for the SRI\Plugin shared core.
 *
 * Usage: php tests/run-tests.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/autoload.php';

/**
 * Minimal assertion-based test case base class.
 */
abstract class SriTestCase
{
    private int $_assertions = 0;
    private array $_messages = [];

    /**
     * @return array{assertions: int, failures: int, messages: string[]}
     */
    abstract public function run(): array;

    protected function ok(bool $cond, string $label): void
    {
        $this->_assertions++;
        if (!$cond) {
            $this->_messages[] = "FAIL: {$label}";
        }
    }

    protected function same($expected, $actual, string $label): void
    {
        $this->ok($expected === $actual, "{$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
    }

    protected function isTrue(bool $cond, string $label): void
    {
        $this->ok($cond === true, "{$label} (expected true)");
    }

    protected function isFalse(bool $cond, string $label): void
    {
        $this->ok($cond === false, "{$label} (expected false)");
    }

    protected function notNull($value, string $label): void
    {
        $this->ok($value !== null, "{$label} (expected non-null)");
    }

    protected function assertThrows(callable $fn, string $label): void
    {
        $this->_assertions++;
        try {
            $fn();
            $this->_messages[] = "FAIL: {$label} (expected exception, none thrown)";
        } catch (\Throwable $e) {
            // expected
        }
    }

    protected function result(): array
    {
        $failures = 0;
        foreach ($this->_messages as $msg) {
            if (str_starts_with($msg, 'FAIL')) {
                $failures++;
            }
        }
        return ['assertions' => $this->_assertions, 'failures' => $failures, 'messages' => $this->_messages];
    }
}

/**
 * Collects results and runs every *Test file placed in this directory.
 */
final class SriTestRunner
{
    private int $assertions = 0;
    private int $failures = 0;
    private array $results = [];

    public function main(array $argv): int
    {
        $dir = __DIR__;
        $filter = $argv[1] ?? null;

        $files = glob($dir . DIRECTORY_SEPARATOR . '*Test.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $base = basename($file, '.php');
            if ($filter !== null && stripos($base, (string)$filter) === false) {
                echo "skip $base (filter)\n";
                continue;
            }
            require_once $file;
            if (!class_exists($base)) {
                fwrite(STDERR, "No class {$base} in {$file}\n");
                $this->failures++;
                continue;
            }
            $instance = new $base();
            if (!method_exists($instance, 'run')) {
                fwrite(STDERR, "No run() method on {$base}\n");
                $this->failures++;
                continue;
            }
            $result = $instance->run();
            $this->assertions += $result['assertions'] ?? 0;
            $this->failures += $result['failures'] ?? 0;
            $this->results[$base] = $result;
        }

        echo "\n==== SRI-Plugin test summary ====\n";
        foreach ($this->results as $base => $r) {
            $status = (($r['failures'] ?? 0) === 0) ? 'PASS' : 'FAIL';
            printf("%-6s %-50s %3d assertions, %d failures\n", $status, $base, $r['assertions'] ?? 0, $r['failures'] ?? 0);
            foreach ($r['messages'] ?? [] as $msg) {
                echo "    - $msg\n";
            }
        }
        printf("Total: %d assertions, %d failures across %d file(s)\n", $this->assertions, $this->failures, count($this->results));
        return $this->failures > 0 ? 1 : 0;
    }
}

exit((new SriTestRunner())->main($argv));
