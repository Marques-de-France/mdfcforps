<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 */

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
        $idShop = (int) self::getContext()->shop->id;

        $countQuery = new \DbQuery();
        $countQuery->select('COUNT(*)')
                   ->from('mdfcforps_feed_products');

        $total = (int) \Db::getInstance()->getValue($countQuery);

        $query = new \DbQuery();
        $query->select('fp.id, fp.product_id, fp.added_at, pl.name as product_name, p.reference, ps.price, ps.active, m.name as brand_name, sa.quantity')
              ->from('mdfcforps_feed_products', 'fp')
              ->leftJoin('product', 'p', 'p.id_product = fp.product_id')
              ->leftJoin('product_lang', 'pl', 'pl.id_product = fp.product_id AND pl.id_lang = ' . $idLang)
              ->leftJoin('product_shop', 'ps', 'ps.id_product = fp.product_id AND ps.id_shop = ' . $idShop)
              ->leftJoin('manufacturer', 'm', 'm.id_manufacturer = p.id_manufacturer')
              ->leftJoin(
                  'stock_available',
                  'sa',
                  'sa.id_product = fp.product_id AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $idShop
              )
              ->orderBy('fp.added_at DESC')
              ->limit($perPage, $offset);

        $rows = \Db::getInstance()->executeS($query);

        if (!is_array($rows)) {
            $rows = [];
        }

        $link = self::getContext()->link;
        $products = [];

        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $imageUrl = '';
            $cover = \Image::getCover($pid);
            if (is_array($cover) && isset($cover['id_image'])) {
                $imageUrl = (string) $link->getImageLink(
                    'product',
                    (int) $cover['id_image'],
                    'small_default'
                );
            }

            $products[] = [
                'id' => $pid,
                'name' => (string) ($row['product_name'] ?? ''),
                'brand' => (string) ($row['brand_name'] ?? ''),
                'reference' => (string) ($row['reference'] ?? ''),
                'availability' => ((int) ($row['quantity'] ?? 0) > 0) ? self::trans('In stock') : self::trans('Out of stock'),
                'price' => self::formatPrice((float) ($row['price'] ?? 0)),
                'status' => ((int) ($row['active'] ?? 0) === 1) ? self::trans('Active') : self::trans('Disabled'),
                'image' => $imageUrl,
                'added_at' => (string) ($row['added_at'] ?? ''),
            ];
        }

        return [
            'products' => $products,
            'total' => $total,
        ];
    }

    /**
     * Search products by name or reference for the BO product picker.
     *
     * @return array{products: array<int, array<string, mixed>>, total: int}
     */
    public static function searchProducts(
        string $search,
        int $idLang,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $offset = ($page - 1) * $perPage;
        $safeSql = \pSQL($search);
        $idShop = (int) self::getContext()->shop->id;

        $where = 'pl.id_lang = ' . $idLang;
        if ($search !== '') {
            $where .= " AND (pl.name LIKE '%" . $safeSql . "%'"
                    . " OR p.reference LIKE '%" . $safeSql . "%')";
        }

        // Total count
        $countQuery = new \DbQuery();
        $countQuery->select('COUNT(*)')
                   ->from('product', 'p')
                   ->leftJoin(
                       'product_lang',
                       'pl',
                       'pl.id_product = p.id_product AND pl.id_lang = ' . $idLang
                   )
                   ->leftJoin(
                       'product_shop',
                       'ps',
                       'ps.id_product = p.id_product AND ps.id_shop = ' . $idShop
                   )
                   ->where($where);

        $total = (int) \Db::getInstance()->getValue($countQuery);

        // Paged rows
        $query = new \DbQuery();
        $query->select('p.id_product, p.reference, ps.price, ps.active, pl.name, m.name as brand_name, sa.quantity')
              ->from('product', 'p')
              ->leftJoin(
                  'product_lang',
                  'pl',
                  'pl.id_product = p.id_product AND pl.id_lang = ' . $idLang
              )
              ->leftJoin(
                  'product_shop',
                  'ps',
                  'ps.id_product = p.id_product AND ps.id_shop = ' . $idShop
              )
              ->leftJoin('manufacturer', 'm', 'm.id_manufacturer = p.id_manufacturer')
              ->leftJoin(
                  'stock_available',
                  'sa',
                  'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $idShop
              )
              ->where($where)
              ->orderBy('pl.name ASC')
              ->limit($perPage, $offset);

        $rows = \Db::getInstance()->executeS($query);
        if (!is_array($rows)) {
            $rows = [];
        }

        $selectedIds = array_flip(self::getSelectedProductIds());
        $link = self::getContext()->link;

        $products = [];
        foreach ($rows as $row) {
            $pid = (int) $row['id_product'];
            $imageUrl = '';
            $cover = \Image::getCover($pid);
            if (is_array($cover) && isset($cover['id_image'])) {
                $imageUrl = (string) $link->getImageLink(
                    'product',
                    (int) $cover['id_image'],
                    'small_default'
                );
            }

            $products[] = [
                'id' => $pid,
                'name' => (string) ($row['name'] ?? ''),
                'brand' => (string) ($row['brand_name'] ?? ''),
                'reference' => (string) ($row['reference'] ?? ''),
                'availability' => ((int) ($row['quantity'] ?? 0) > 0) ? self::trans('In stock') : self::trans('Out of stock'),
                'price' => self::formatPrice((float) ($row['price'] ?? 0)),
                'status' => ((int) ($row['active'] ?? 0) === 1) ? self::trans('Active') : self::trans('Disabled'),
                'image' => $imageUrl,
                'in_feed' => isset($selectedIds[$pid]),
            ];
        }

        return [
            'products' => $products,
            'total' => $total,
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
                'added_at' => date('Y-m-d H:i:s'),
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

    private static function trans(string $message): string
    {
        return self::getContext()->getTranslator()->trans($message, [], 'Modules.Mdfcforps.Admin');
    }

    private static function formatPrice(float $amount): string
    {
        $context = self::getContext();
        $isoCode = isset($context->currency)
            ? (string) $context->currency->iso_code
            : 'EUR';

        if (isset($context->currentLocale)) {
            return (string) $context->currentLocale->formatPrice($amount, $isoCode);
        }

        return number_format($amount, 2, '.', ' ') . ' ' . $isoCode;
    }

    private static function getContext()
    {
        return \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()
            ->get('prestashop.adapter.legacy.context')
            ->getContext();
    }
}
