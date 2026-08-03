<?php
/**
 * Upgrade script for v1.3.0.
 *
 * Changes in this version:
 * - Sales: only orders attributed to Marques de France are recorded and transmitted.
 * - Attribution: the PrestaShop session stamped by the AJAX tracker is read back at
 *   checkout, utm_medium / utm_campaign are matched alongside utm_source, signals are
 *   also captured server-side on the landing request so attribution survives the tracker
 *   script being blocked, and HTTP_REFERER is used as a last-resort signal with
 *   self-referrals excluded.
 * - Sync: a sale the Hub declines as unattributed is retired instead of retried.
 * - Order status: cancellations and refunds are keyed on the PrestaShop order id, so
 *   they reach the Hub.
 *
 * No database change: the schema is untouched and MDFCFORPS_DB_VERSION stays at 1.1.0.
 * Existing rows are deliberately left as they are — no purge, no rewrite, no backfill.
 * The new behaviour applies to orders placed from this version onwards.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0(Module $module): bool
{
    // Rebuild the compiled Symfony container / caches so the new AttributionService,
    // LandingCapture and HubClient class definitions are picked up. No data is touched.
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
