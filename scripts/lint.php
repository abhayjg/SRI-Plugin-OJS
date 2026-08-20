<?php

/**
 * @file scripts/lint.php
 *
 * Runs `php -l` over every PHP file in the repository (excluding vendor/,
 * dist/, and any extracted OJS installs). Returns non-zero on syntax errors.
 *
 * Usage: php scripts/lint.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = realpath(dirname(__DIR__));
$excludeDirs = ['vendor', 'dist', '.git', 'cache', 'node_modules'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$failed = 0;
$checked = 0;

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $rel = substr($path, strlen($root) + 1);
    foreach ($excludeDirs as $dir) {
        if (str_starts_with($rel, $dir . DIRECTORY_SEPARATOR) || $rel === $dir) {
            continue 2;
        }
    }

    $checked++;
    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exit);
    if ($exit !== 0) {
        $failed++;
        echo implode("\n", $output), "\n";
    }
}

echo "Linted {$checked} PHP file(s); {$failed} error(s).\n";
exit($failed > 0 ? 1 : 0);
