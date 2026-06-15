<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade script for mdfcforps v1.0.0
 *
 * Runs automatically when a store that had an older version installed
 * upgrades to 1.0.0. On fresh installs this file is never called.
 */
function upgrade_module_1_0_0(Module $module): bool
{
    // Ensure DB version flag is set
    Configuration::updateValue('MDFCFORPS_DB_VERSION', '1.0.0');

    // Ensure feed mode default is set if missing
    if (!Configuration::get('MDFCFORPS_FEED_FILTER_MODE')) {
        Configuration::updateValue('MDFCFORPS_FEED_FILTER_MODE', 'TAG');
    }

    return true;
}
