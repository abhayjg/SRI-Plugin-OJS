<?php

/**
 * @defgroup plugins_pubIds_sri SRI-Plugin
 */

/**
 * @file plugins/pubIds/sri/index.php
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * @ingroup plugins_pubIds_sri
 *
 * @brief Wrapper for the SRI-Plugin OJS plugin.
 */
require_once(dirname(__FILE__) . '/classes/bootstrap.php');

return new \APP\plugins\pubIds\sri\SriPubIdPlugin();
