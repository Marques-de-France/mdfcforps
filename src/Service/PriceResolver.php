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

/**
 * Resolves the regular (pre-discount) and effective (post-discount) tax-included
 * prices of a product, or of one of its combinations.
 *
 * PrestaShop has no regular/sale column pair like WooCommerce. Discounts live in
 * ps_specific_price — both the manual "Specific prices" of the product's Prices tab
 * and the catalog price rules, which PS materialises into the same table. They are
 * applied by Product::getPriceStatic() through its $useReduction flag, so we price
 * the item twice and treat the delta as the sale.
 *
 * Core also filters specific prices by their from/to window and resolves priority
 * between competing rows, so expired and scheduled discounts are excluded for free.
 *
 * Caveat: a specific price that overrides the price outright (its `price` column set
 * instead of `reduction`) is applied by PS on both passes, so it reads as a new
 * catalog price rather than a discount — which is what PS itself displays.
 *
 * Shared by the RSS feed and the back-office grids so the two can never disagree.
 */
class PriceResolver
{
    /**
     * Computation precision. Prices are rounded to CENT_DECIMALS before comparison.
     */
    private const COMPUTE_DECIMALS = 6;

    private const CENT_DECIMALS = 2;

    /**
     * @return array{regular: float, effective: float, on_sale: bool}
     */
    public function resolve(int $idProduct, int $idProductAttribute = 0): array
    {
        $idPA = $idProductAttribute > 0 ? $idProductAttribute : null;

        // Catalog price, ignoring any specific price / catalog price rule reduction.
        $regular = (float) \Product::getPriceStatic(
            $idProduct,
            true,   // usetax — we publish and display tax-included prices
            $idPA,
            self::COMPUTE_DECIMALS,
            null,   // divisor
            false,  // onlyReduction
            false   // useReduction
        );

        // Price actually charged today, reductions applied.
        $effective = (float) \Product::getPriceStatic(
            $idProduct,
            true,
            $idPA,
            self::COMPUTE_DECIMALS,
            null,
            false,
            true    // useReduction
        );

        // Round before comparing: percentage reductions routinely leave sub-cent noise.
        $regular = round($regular, self::CENT_DECIMALS);
        $effective = round($effective, self::CENT_DECIMALS);

        return [
            'regular' => $regular,
            'effective' => $effective,
            'on_sale' => $effective > 0 && $effective < $regular,
        ];
    }
}
