<?php
/**
 * Upgrade script for v1.5.0.
 *
 * Changes in this version:
 * - Attribution now survives a payment provider that redirects the customer away.
 *   The signals are frozen against the cart in the customer's own request, while the
 *   cookies are still readable, and read back at order validation through the order's
 *   id_cart. Until now an order validated by a provider's server-to-server IPN — or
 *   returned through a cross-site POST, on which the browser withholds the module's
 *   SameSite=Lax cookies — resolved to "unknown" and was discarded outright: no local
 *   row, nothing sent to the Hub, nothing to retry. One partner on PayPlug was losing
 *   every single conversion this way.
 * - Second recovery layer on the order confirmation page, which PrestaShop always
 *   reaches by a top-level GET and which therefore always has the cookies.
 * - Both order hooks are now exception-safe, so no fault in this module can reach
 *   PaymentModule::validateOrder() and cost a merchant an order.
 * - The PrestaShop session cookie no longer receives full-length landing and referring
 *   URLs. Its whole payload is one encrypted cookie with a practical budget near 1000
 *   characters, and overflowing it makes the browser drop the visitor's session.
 *
 * Database change: adds `mdfcforps_cart_attribution`.
 * New hooks: displayOrderConfirmation, actionObjectCartAddAfter,
 * actionObjectCartUpdateAfter. install() only ever runs once, so an existing
 * installation has to be registered here or the fix does nothing.
 * MDFCFORPS_DB_VERSION moves from 1.1.0 to 1.2.0.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_5_0(Module $module): bool
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'mdfcforps_cart_attribution';

    // Guarded CREATE, kept identical to sql/install.sql so an upgraded shop and a
    // fresh install converge on the same schema. Idempotent and safe to re-run.
    $created = $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . bqSQL($table) . '` (
            `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart`             INT UNSIGNED NOT NULL,
            `attribution_source`  VARCHAR(64)  NOT NULL DEFAULT \'unknown\',
            `click_id`            VARCHAR(255) NOT NULL DEFAULT \'\',
            `landing_ref`         VARCHAR(2048) NOT NULL DEFAULT \'\',
            `utm_source`          VARCHAR(255) NOT NULL DEFAULT \'\',
            `utm_medium`          VARCHAR(255) NOT NULL DEFAULT \'\',
            `utm_campaign`        VARCHAR(255) NOT NULL DEFAULT \'\',
            `utm_content`         VARCHAR(255) NOT NULL DEFAULT \'\',
            `utm_term`            VARCHAR(255) NOT NULL DEFAULT \'\',
            `landing_site`        VARCHAR(2048) NOT NULL DEFAULT \'\',
            `referring_site`      VARCHAR(2048) NOT NULL DEFAULT \'\',
            `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_id_cart` (`id_cart`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!$created) {
        return false;
    }

    // Without these three the module upgrades cleanly and still loses every sale on a
    // redirect payment, which is the failure this release exists to fix.
    $hooks = [
        'displayOrderConfirmation',
        'actionObjectCartAddAfter',
        'actionObjectCartUpdateAfter',
    ];

    foreach ($hooks as $hook) {
        if (!$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
            return false;
        }
    }

    Configuration::updateValue('MDFCFORPS_LAST_CART_PURGE', 0);
    Configuration::updateValue('MDFCFORPS_DB_VERSION', Mdfcforps::DB_VERSION);

    // Rebuild the compiled Symfony container / caches so the new hook registrations
    // and services are picked up.
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
