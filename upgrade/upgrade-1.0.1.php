<?php
/**
 * Upgrade script for v1.0.1.
 *
 * Changes in this version (manage-mode product grid):
 * - Show the "X combinations" badge next to product names.
 * - Fix the select-all checkbox on PrestaShop 1.7 (robust DOM lookup, document-level
 *   delegation, survives grid re-renders) and send the module CSRF token on the bulk
 *   add/remove request (was returning 403).
 *
 * These are template/PHP changes only (no DB change). Clears the compiled Smarty
 * templates and the Symfony cache so the updated views take effect immediately.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1(Module $module): bool
{
    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }
    if (method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache('dev');
        Tools::clearSf2Cache('prod');
    }

    return true;
}
