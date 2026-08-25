<?php

/**
 * @file plugins/pubIds/sri/classes/bootstrap.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Bootstraps the shared, version-independent SRI\Plugin\ core copied into
 * classes/core/ (by build/package.php or scripts/sync-core.php). Keeping the
 * core here, outside the APP\plugins namespace, lets the exact same files run
 * unchanged inside the OJS 3.3 legacy plugin and in the standalone unit tests.
 */

declare(strict_types=1);

require_once(__DIR__ . '/core/autoload.php');
if (file_exists(__DIR__ . '/SriMetadataBuilder.php')) {
    require_once(__DIR__ . '/SriMetadataBuilder.php');
}

// The autoload.php in classes/core/ registers the SRI\Plugin\ PSR-4 autoloader
// pointing at its own directory. Nothing further is required here.
