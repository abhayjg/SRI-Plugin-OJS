<?php

/**
 * @file plugins/pubIds/sri/classes/core/autoload.php
 *
 * Shared, version-independent core for the SRI-Plugin OJS plugin.
 *
 * Registers a lightweight PSR-4 autoloader for the SRI\Plugin\ namespace.
 * The canonical home for these classes is <repo>/src/. The plugin packages
 * copy them under their own classes/core/ directory (see build/package.php
 * and scripts/sync-core.php), so this file lives in both places unchanged.
 *
 * The autoloader intentionally does nothing if the classes are already
 * loadable (e.g. when running the standalone unit tests via composer's own
 * autoloader configured in composer.json).
 */

namespace SRI\Plugin;

if (!function_exists(__NAMESPACE__ . '\\registerAutoload')) {

/**
 * Register a PSR-4 autoloader mapping SRI\Plugin\ to this directory.
 *
 * @param string $directory Base directory containing the class files.
 */
function registerAutoload(string $directory): void
{
    $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $registered = spl_autoload_register(static function (string $class) use ($directory): void {
        $prefix = 'SRI\\Plugin\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $directory . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    if (!$registered) {
        // If we could not register (e.g. duplicated), verify the core classes
        // can still be resolved so the plugin fails loudly rather than silently.
        if (!class_exists(CheckCharacter::class)) {
            throw new \RuntimeException('SRI\\Plugin core autoloader could not be registered and core classes are not loadable.');
        }
    }
}

}

// Bootstrap automatically when this file is loaded, pointing at its own
// directory so the same file works in src/ and in classes/core/.
if (!class_exists(CheckCharacter::class)) {
    registerAutoload(__DIR__);
}
