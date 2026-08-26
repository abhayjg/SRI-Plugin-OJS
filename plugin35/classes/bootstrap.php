<?php

/**
 * @file plugins/pubIds/sri/classes/bootstrap.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Bootstraps the shared, version-independent SRI\Plugin\ core copied into
 * classes/core/ (by build/package.php or scripts/sync-core.php).
 */

declare(strict_types=1);

require_once(__DIR__ . '/core/autoload.php');
if (file_exists(__DIR__ . '/SriMetadataBuilder.php')) {
    require_once(__DIR__ . '/SriMetadataBuilder.php');
}
