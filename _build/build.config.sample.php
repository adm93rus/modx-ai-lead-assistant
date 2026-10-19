<?php

/**
 * Copy to build.config.php and point it at the MODX installation
 * that should build the transport package.
 */

define('MODX_BASE_PATH', '/var/www/modx/');
define('MODX_CORE_PATH', MODX_BASE_PATH . 'core/');
define('MODX_CONFIG_KEY', 'config');
