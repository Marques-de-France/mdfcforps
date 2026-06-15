<?php
/**
 * PHPStan bootstrap for module static analysis.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 */

declare(strict_types=1);

$psRootDir = getenv('_PS_ROOT_DIR_');

if (is_string($psRootDir) && $psRootDir !== '') {
    $configFile = rtrim($psRootDir, DIRECTORY_SEPARATOR) . '/config/config.inc.php';
    if (is_file($configFile)) {
        require_once $configFile;
    }
}

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.0.0');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
