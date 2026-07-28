<?php
/**
 * Upgrade script for v1.2.0.
 *
 * Changes in this version:
 * - Sales: records the net (tax-excluded) order amount locally and transmits it
 *   to the Hub, which derives the affiliation commission from the store's rate.
 * - Back office: the Sales grid and KPIs surface a Commission figure when the
 *   store belongs to an active affiliation program.
 *
 * Database change: adds the `net_amount` column to `mdfcforps_sales`.
 * MDFCFORPS_DB_VERSION moves from 1.0.0 to 1.1.0.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0(Module $module): bool
{
    $table = _DB_PREFIX_ . 'mdfcforps_sales';
    $db = Db::getInstance();

    // Guarded ALTER: only add the column when it is missing so the upgrade is
    // idempotent and safe to re-run.
    $columns = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE \'net_amount\'');
    if (empty($columns)) {
        $db->execute(
            'ALTER TABLE `' . bqSQL($table) . '` '
            . 'ADD COLUMN `net_amount` DECIMAL(20,6) NOT NULL DEFAULT 0 AFTER `amount`'
        );
    }

    Configuration::updateValue('MDFCFORPS_DB_VERSION', Mdfcforps::DB_VERSION);

    // Rebuild the compiled Symfony container / caches so the new grid column,
    // services and translations are picked up.
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
