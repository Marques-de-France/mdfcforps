<?php
/**
 * Upgrade script for v1.4.0.
 *
 * Changes in this version:
 * - One-click self-update from the back-office dashboard. The module asks the Hub
 *   which version is current (folded into GET /api/ps/status), shows a banner when a
 *   newer one is announced, and can download, verify and install it in place.
 * - New services: Mdfcforps\Service\UpdateChecker and Mdfcforps\Service\ModuleUpdater.
 * - New routes: mdfcforps_update_check / _run / _finalize / _rollback.
 * - New configuration keys: MDFCFORPS_UPDATE_INFO, MDFCFORPS_UPDATE_CHECKED_AT,
 *   MDFCFORPS_UPDATE_STATE, MDFCFORPS_UPDATE_LAST_ERROR.
 *
 * The Symfony cache clear below is MANDATORY for this release: it adds four routes
 * and two services, so the compiled container on disk no longer matches config/.
 * Without the clear the new routes 404 and the update button does nothing.
 *
 * No database schema change: MDFCFORPS_DB_VERSION stays at 1.1.0.
 *
 * Note this release is itself installed manually — a shop on 1.3.0 has no updater.
 * The first one-click update is 1.4.0 to 1.4.1.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0(Module $module): bool
{
    // Seed the self-update bookkeeping. MDFCFORPS_UPDATE_STATE is written globally
    // rather than per shop on purpose — see ModuleUpdater::KEY_STATE.
    Configuration::updateValue('MDFCFORPS_UPDATE_INFO', '');
    Configuration::updateValue('MDFCFORPS_UPDATE_CHECKED_AT', 0);
    Configuration::updateValue('MDFCFORPS_UPDATE_STATE', '');
    Configuration::updateValue('MDFCFORPS_UPDATE_LAST_ERROR', '');

    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }

    if (method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache('dev');
        Tools::clearSf2Cache('prod');
    }

    return true;
}
