<?php

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * CRUD service for the manually-curated SERVERLIST feed product table.
 */
class FeedProductsService
{
    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /**
     * @return int[]
     */
    public static function getSelectedProductIds(): array
    {
        $query = new \DbQuery();
        $query->select('product_id')
              ->from('mdfcforps_feed_products')
              ->orderBy('added_at DESC');

        $rows = \Db::getInstance()->executeS($query);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn ($r) => (int) $r['product_id'], $rows);
    }

    /**
     * Paginated list with product name for BO display.
     *
     * @return array{products: array<int, array<string, mixed>>, total: int}
     */
    public static function paginateForBo(int $idLang, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;

        $countQuery = new \DbQuery();
        $countQuery->select('COUNT(*)')
                   ->from('mdfcforps_feed_products');

        $total = (int) \Db::getInstance()->getValue($countQuery);

        $query = new \DbQuery();
        $query->select('fp.id, fp.product_id, fp.added_at, pl.name as product_name')
              ->from('mdfcforps_feed_products', 'fp')
              ->leftJoin('product_lang', 'pl', 'pl.id_product = fp.product_id AND pl.id_lang = ' . $idLang)
              ->orderBy('fp.added_at DESC')
              ->limit($perPage, $offset);

        $rows = \Db::getInstance()->executeS($query);

        return [
            'products' => is_array($rows) ? $rows : [],
            'total'    => $total,
        ];
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    public static function addProduct(int $productId): bool
    {
        return (bool) \Db::getInstance()->insert(
            'mdfcforps_feed_products',
            [
                'product_id' => $productId,
                'added_at'   => date('Y-m-d H:i:s'),
            ],
            false,
            true,
            \Db::INSERT_IGNORE
        );
    }

    public static function removeProduct(int $productId): bool
    {
        return (bool) \Db::getInstance()->delete(
            'mdfcforps_feed_products',
            'product_id = ' . $productId
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public static function isSelected(int $productId): bool
    {
        $query = new \DbQuery();
        $query->select('COUNT(*)')
              ->from('mdfcforps_feed_products')
              ->where('product_id = ' . $productId);

        return (int) \Db::getInstance()->getValue($query) > 0;
    }
}
