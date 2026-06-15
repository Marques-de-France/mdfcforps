<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ModuleConfig
{
    public static function get(string $key, string $default = ''): string
    {
        $shopId = self::getCurrentShopId();

        if ($shopId > 0) {
            $value = \Configuration::get($key, null, null, $shopId);
            if ($value !== false && $value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $fallback = \Configuration::get($key);

        if ($fallback === false || $fallback === '') {
            return $default;
        }

        return (string) $fallback;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }

    public static function update(string $key, $value): bool
    {
        $shopId = self::getCurrentShopId();

        if ($shopId > 0) {
            return (bool) \Configuration::updateValue($key, $value, false, null, $shopId);
        }

        return (bool) \Configuration::updateValue($key, $value);
    }

    private static function getCurrentShopId(): int
    {
        // Front controllers can run without a fully booted Symfony container.
        // Resolve shop ID from legacy context first, then static Shop fallbacks.
        $context = \Context::getContext();
        if ($context && isset($context->shop) && \Validate::isLoadedObject($context->shop)) {
            return (int) $context->shop->id;
        }

        if (class_exists('Shop')) {
            $contextShopId = (int) \Shop::getContextShopID();
            if ($contextShopId > 0) {
                return $contextShopId;
            }
        }

        $defaultShopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        if ($defaultShopId > 0) {
            return $defaultShopId;
        }

        return 0;
    }
}
