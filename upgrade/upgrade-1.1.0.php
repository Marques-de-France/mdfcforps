<?php
/**
 * Upgrade script for v1.1.0.
 *
 * Changes in this version:
 * - Feed: <g:price> is now tax-INCLUDED (it previously published the tax-excluded
 *   price by mistake), and <g:sale_price> is populated from PrestaShop specific
 *   prices and catalog price rules.
 * - Back office: the "Products in feed" price column shows the discounted price with
 *   the regular price struck through, and sorting/filtering run on the same figures.
 * - Back office: fixed the record count for that grid, which always reported 1 and so
 *   capped the listing at a single page.
 * - Sales: timestamps are transmitted with their UTC offset and rendered in the shop
 *   timezone; imported Hub timestamps are converted instead of stored verbatim.
 * - Compatibility: declared minimum lowered to PrestaShop 1.7.7.5 (PHP 7.4+ required).
 *
 * No database change: the schema is untouched and MDFCFORPS_DB_VERSION stays at 1.0.0.
 * The Symfony container must be rebuilt though, because config/services.yml gained the
 * mdfcforps.price_resolver service and new constructor arguments, and the translation
 * catalogue gained a singular "combination" entry.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0(Module $module): bool
{
    // Evict the stale compiled container before anything tries to load it.
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
