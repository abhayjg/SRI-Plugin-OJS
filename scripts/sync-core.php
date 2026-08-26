<?php

/**
 * @file scripts/sync-core.php
 *
 * Development convenience: mirrors the shared core (src/) into each adapter's
 * classes/core/ directory so a working-tree copy of the plugin is directly
 * installable without running the full package build.
 *
 * Usage: php scripts/sync-core.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$src = $root . DIRECTORY_SEPARATOR . 'src';
$targets = [
    $root . DIRECTORY_SEPARATOR . 'plugin35' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'core',
    $root . DIRECTORY_SEPARATOR . 'plugin34' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'core',
    $root . DIRECTORY_SEPARATOR . 'plugin33' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'core',
];

if (!is_dir($src)) {
    fwrite(STDERR, "Source core not found: {$src}\n");
    exit(1);
}

foreach ($targets as $target) {
    if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
        fwrite(STDERR, "Could not create target: {$target}\n");
        exit(1);
    }
    // Clear only the PHP files we manage (never user files).
    foreach (glob($target . DIRECTORY_SEPARATOR . '*.php') ?: [] as $old) {
        unlink($old);
    }
    $count = 0;
    foreach (glob($src . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
        if (!copy($file, $target . DIRECTORY_SEPARATOR . basename($file))) {
            fwrite(STDERR, "Could not copy {$file}\n");
            exit(1);
        }
        $count++;
    }
    echo "Synced {$count} core file(s) -> {$target}\n";
}

echo "Core sync complete.\n";
