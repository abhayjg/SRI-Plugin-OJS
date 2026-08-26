<?php

/**
 * @file build/package.php
 *
 * Builds the installable OJS plugin packages:
 *
 *   dist/sri-pubid-3.5.tar.gz   (OJS 3.5 — namespaced adapter)
 *   dist/sri-pubid-3.4.tar.gz   (OJS 3.4 — namespaced adapter)
 *   dist/sri-pubid-3.3.tar.gz   (OJS 3.3 — legacy adapter)
 *
 * Each tarball extracts to a `sri/` directory intended for
 * `plugins/pubIds/sri/` inside an OJS install (or, simpler, uploaded via the
 * standard Plugin Upload UI, which handles the folder layout itself).
 *
 * The shared core (src/) is copied into each package under classes/core/.
 *
 * Usage: php build/package.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = realpath(dirname(__DIR__));
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
if (!is_dir($dist) && !mkdir($dist, 0777, true) && !is_dir($dist)) {
    fwrite(STDERR, "Could not create dist/ directory.\n");
    exit(1);
}

$version = '1.2.2';
$builders = [
    '3.5' => 'plugin35',
    '3.4' => 'plugin34',
    '3.3' => 'plugin33',
];

$pharReadonly = ini_get('phar.readonly');
if (strcasecmp((string)$pharReadonly, '0') !== 0 && strcasecmp((string)$pharReadonly, 'Off') !== 0 && (string)$pharReadonly !== '') {
    fwrite(STDERR, 'Note: phar.readonly is not 0; if packaging fails, run: php -d phar.readonly=0 build/package.php' . PHP_EOL);
}

foreach ($builders as $ojsVersion => $adapterDir) {
    $adapter = $root . DIRECTORY_SEPARATOR . $adapterDir;
    $work = $dist . DIRECTORY_SEPARATOR . 'work-sri';
    $package = $work . DIRECTORY_SEPARATOR . 'sri';

    // Fresh working copy
    if (is_dir($work)) {
        removeDir($work);
    }
    copyTree($adapter, $package);

    // Inject the shared core
    $coreTarget = $package . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'core';
    if (!is_dir($coreTarget) && !mkdir($coreTarget, 0777, true) && !is_dir($coreTarget)) {
        fwrite(STDERR, "Could not create core dir in package.\n");
        exit(1);
    }
    copyTree($root . DIRECTORY_SEPARATOR . 'src', $coreTarget);

    // A README inside the package for convenience
    file_put_contents(
        $package . DIRECTORY_SEPARATOR . 'README.txt',
        "SRI-Plugin v{$version} for OJS {$ojsVersion}\n\n"
        . "Install via: Settings -> Website -> Plugins -> Upload a new plugin.\n"
        . "Full docs: https://scitekhub.com/sri-ojs\n"
    );

    // Build a plain tarball, then gzip it.
    $tarPath = $dist . DIRECTORY_SEPARATOR . "sri-pubid-{$ojsVersion}.tar";
    $gzPath = $dist . DIRECTORY_SEPARATOR . "sri-pubid-{$ojsVersion}.tar.gz";
    @unlink($tarPath);
    @unlink($gzPath);

    try {
        // The staging dir holds only the sri/ package folder, so include
        // everything under it (no path regex needed).
        $entry = new PharData($tarPath);
        $entry->buildFromDirectory($work);
        unset($entry);

        $gz = new PharData($tarPath);
        $gz->compress(Phar::GZ);
        unset($gz);

        @unlink($tarPath); // compress() leaves the plain tar behind
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Packaging failed: ' . $e->getMessage() . PHP_EOL);
        @unlink($tarPath);
        if (is_dir($work)) {
            removeDir($work);
        }
        exit(1);
    }

    removeDir($work);

    if (is_file($gzPath)) {
        echo "Built dist/sri-pubid-{$ojsVersion}.tar.gz\n";
    } else {
        fwrite(STDERR, "Target was not created: {$gzPath}\n");
        exit(1);
    }
}

echo "All packages built successfully.\n";

function copyTree(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }
    if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
        fwrite(STDERR, "Could not create {$dst}\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $sub = $dst . DIRECTORY_SEPARATOR . $it->getSubPathname();
        if ($item->isDir()) {
            if (!is_dir($sub) && !mkdir($sub, 0777, true) && !is_dir($sub)) {
                fwrite(STDERR, "Could not create {$sub}\n");
                exit(1);
            }
        } else {
            // Skip transient / build artifacts if present
            if (preg_match('/(\.DS_Store|Thumbs\.db|\.git.*)$/', $item->getFilename())) {
                continue;
            }
            copy($item->getPathname(), $sub);
        }
    }
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}
