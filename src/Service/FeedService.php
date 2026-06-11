<?php

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Product Feed Service — PrestaShop port of the WooCommerce MDFCFORWC_Feed class.
 *
 * Produces a Google Merchant Center-compatible RSS 2.0 feed.
 * Feed format is identical to the WC plugin so that the Hub can parse both.
 *
 * Feed modes:
 *  - TAG         (default): products with the PS tag "marques-de-france"
 *  - SERVERLIST: manually curated product list (mdfcforps_feed_products table)
 *
 * Pagination: ?per_page=200&page=1
 */
class FeedService
{
    private \Context $context;
    private string $shopName;
    private string $currency;
    private FeedEligibilityService $eligibilityService;

    public function __construct(?FeedEligibilityService $eligibilityService = null)
    {
        $this->context  = \Context::getContext();
        $this->shopName = (string) \Configuration::get('PS_SHOP_NAME');

        $defaultCurrency = \Currency::getDefaultCurrency();
        $this->currency  = $defaultCurrency ? $defaultCurrency->iso_code : 'EUR';
        $this->eligibilityService = $eligibilityService
            ?: new FeedEligibilityService((int) \Configuration::get('PS_ORDER_OUT_OF_STOCK'));
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Generate and return the full RSS 2.0 XML string.
     */
    public function buildFeed(int $perPage = 200, int $page = 1): string
    {
        $products = $this->getFeedProducts($perPage, $page);
        return $this->buildRss($products);
    }

    // -----------------------------------------------------------------------
    // Product query
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getFeedProducts(int $perPage, int $page): array
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $mode   = (string) \Configuration::get('MDFCFORPS_FEED_FILTER_MODE');

        if ($mode === 'SERVERLIST') {
            $productIds = \Mdfcforps\Service\FeedProductsService::getSelectedProductIds();
            if (empty($productIds)) {
                return [];
            }
            $rawProducts = $this->getProductsByIds($productIds, $idLang, $idShop, $perPage, $page);
        } else {
            // TAG mode: products with PS tag "marques-de-france"
            $rawProducts = $this->getProductsByTag('marques-de-france', $idLang, $idShop, $perPage, $page);
        }

        $items = [];

        foreach ($rawProducts as $raw) {
            $productId = (int) $raw['id_product'];
            $product   = new \Product($productId, true, $idLang, $idShop);

            if (!\Validate::isLoadedObject($product)) {
                continue;
            }

            // Skip products with no active combinations OR that are out of stock
            if ($product->hasCombinations()) {
                $combinations = $this->getInStockCombinations($product, $idLang);
                if (empty($combinations)) {
                    continue;
                }

                $prices     = array_filter(array_map(fn ($c) => (float) $c['price_computed'], $combinations));
                $minPrice   = $prices ? min($prices) : null;

                foreach ($combinations as $combo) {
                    $isCheapest = $minPrice !== null && (float) $combo['price_computed'] === $minPrice;
                    $items[]    = $this->normaliseCombination($combo, $product, $idLang, $isCheapest);
                }
            } else {
                $stockContext = $this->eligibilityService->getProductStockContext($productId, $idShop);
                if (!$this->eligibilityService->isEligibleByQuantityAndPolicy(
                    (int) $stockContext['quantity'],
                    (int) $stockContext['out_of_stock']
                )) {
                    continue;
                }
                $items[] = $this->normaliseProduct($product, $idLang);
            }
        }

        return $items;
    }

    // -----------------------------------------------------------------------
    // Normalisation — simple product
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function normaliseProduct(\Product $product, int $idLang): array
    {
        $imageUrl  = $this->getProductImageUrl($product);
        $addImages = $this->getAdditionalImageUrls($product);
        $category  = $this->getCategoryPath($product, $idLang);
        $tags      = $this->getProductTags($product, $idLang);
        $link      = $this->context->link->getProductLink($product);
        $title     = strip_tags($product->name);
        $mpn       = $product->reference ?: '';
        $gtin      = (string) ($product->ean13 ?: $product->isbn ?: $product->upc ?: '');

        $availStr  = $this->getAvailabilityString($product);

        return [
            'id'                      => (string) $product->id,
            'title'                   => $title,
            'parent_title'            => $title,
            'parent_link'             => $link,
            'description'             => $this->sanitizeRichHtml($product->description ?: $product->description_short),
            'short_description'       => $this->sanitizeRichHtml($product->description_short),
            'link'                    => $link,
            'image'                   => $imageUrl,
            'parent_image'            => $imageUrl,
            'variant_image'           => '',
            'additional_images'       => $addImages,
            'price'                   => $this->getProductPrice($product),
            'regular_price'           => $this->getProductPrice($product, false),
            'sale_price'              => $this->getProductSalePrice($product),
            'currency'                => $this->currency,
            'sku'                     => $mpn,
            'availability'            => $availStr,
            'availability_date'       => '',
            'condition'               => 'new',
            'category'                => $category,
            'brand'                   => $this->getProductBrand($product),
            'gtin'                    => $gtin,
            'mpn'                     => $mpn,
            'tags'                    => $tags,
            'color'                   => '',
            'size'                    => '',
            'gender'                  => $this->inferGender($product, $idLang),
            'age_group'               => $this->inferAgeGroup($product, $idLang),
            'google_product_category' => $this->getGoogleProductCategory($category),
            'shipping'                => $this->getShippingBlock(),
            'identifier_exists'       => !empty($gtin) || !empty($mpn),
            'has_variants'            => false,
            'is_cheapest_variant'     => true,
            'woo_product_type'        => 'simple',
            'mdf_product_type'        => 'external',
            'attributes'              => $this->getProductFeatures($product, $idLang),
            'item_group_id'           => (string) $product->id,
            'shipping_weight'         => $product->weight ? $product->weight . ' kg' : '',
            'shipping_length'         => '',
            'shipping_width'          => '',
            'shipping_height'         => '',
            'shipping_label'          => '',
            'add_to_cart_url'         => $this->context->link->getAddToCartURL((int) $product->id, 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Normalisation — combination (PS equivalent of WC variation)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $combo  Row from Combination::getAttributeById()
     * @return array<string, mixed>
     */
    private function normaliseCombination(array $combo, \Product $product, int $idLang, bool $isCheapest): array
    {
        $parentImageUrl   = $this->getProductImageUrl($product);
        $variantImageUrl  = $this->getCombinationImageUrl($product, (int) $combo['id_product_attribute']);
        $displayImageUrl  = $variantImageUrl ?: $parentImageUrl;
        $addImages        = $this->getAdditionalImageUrls($product);

        $category  = $this->getCategoryPath($product, $idLang);
        $tags      = $this->getProductTags($product, $idLang);
        $link      = $this->context->link->getProductLink($product);

        // Build title: "Product Name – Attr1, Attr2"
        $attrValues = array_filter(array_column($combo['attributes'] ?? [], 'value'));
        $title      = strip_tags($product->name);
        if (!empty($attrValues)) {
            $title .= ' – ' . implode(', ', $attrValues);
        }

        $parentTitle = strip_tags($product->name);
        $mpn         = $combo['reference'] ?: $product->reference ?: '';
        $gtin        = $combo['ean13'] ?: $combo['isbn'] ?: $combo['upc'] ?: '';

        $comboPrice   = (float) $combo['price_computed'];
        $comboRegular = $comboPrice; // PS: use computed price as regular (discount handled separately)
        $comboSale    = ''; // PS discount resolution is complex — leave blank in feed

        return [
            'id'                      => $product->id . '_' . $combo['id_product_attribute'],
            'title'                   => $title,
            'parent_title'            => $parentTitle,
            'parent_link'             => $link,
            'description'             => $this->sanitizeRichHtml($product->description ?: $product->description_short),
            'short_description'       => $this->sanitizeRichHtml($product->description_short),
            'link'                    => $link,
            'image'                   => $displayImageUrl,
            'parent_image'            => $parentImageUrl,
            'variant_image'           => $variantImageUrl,
            'additional_images'       => $addImages,
            'price'                   => (string) $comboPrice,
            'regular_price'           => (string) $comboRegular,
            'sale_price'              => $comboSale,
            'currency'                => $this->currency,
            'sku'                     => $mpn,
            'availability'            => $combo['in_stock']
                ? 'in stock'
                : (($combo['allows_out_of_stock_orders'] ?? false) ? 'preorder' : 'out of stock'),
            'availability_date'       => '',
            'condition'               => 'new',
            'category'                => $category,
            'brand'                   => $this->getProductBrand($product),
            'gtin'                    => (string) $gtin,
            'mpn'                     => $mpn,
            'tags'                    => $tags,
            'color'                   => $this->getCombinationColor($combo),
            'size'                    => $this->getCombinationSize($combo),
            'gender'                  => $this->inferGender($product, $idLang),
            'age_group'               => $this->inferAgeGroup($product, $idLang),
            'google_product_category' => $this->getGoogleProductCategory($category),
            'shipping'                => $this->getShippingBlock(),
            'identifier_exists'       => !empty($gtin) || !empty($mpn),
            'has_variants'            => true,
            'is_cheapest_variant'     => $isCheapest,
            'woo_product_type'        => 'variable',
            'mdf_product_type'        => 'variable',
            'attributes'              => $combo['attributes'] ?? [],
            'item_group_id'           => (string) $product->id,
            'shipping_weight'         => $product->weight ? $product->weight . ' kg' : '',
            'shipping_length'         => '',
            'shipping_width'          => '',
            'shipping_height'         => '',
            'shipping_label'          => '',
            'add_to_cart_url'         => $this->context->link->getAddToCartURL((int) $product->id, (int) $combo['id_product_attribute']),
        ];
    }

    // -----------------------------------------------------------------------
    // RSS 2.0 XML builder (identical structure to WC plugin)
    // -----------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function buildRss(array $products): string
    {
        $shopUrl = $this->context->shop->getBaseURL(true);

        // Filter zero-price items
        $valid = array_values(array_filter($products, function ($p) {
            $effectivePrice = $p['regular_price'] !== '' ? (float) $p['regular_price'] : (float) $p['price'];
            return $effectivePrice > 0;
        }));

        $totalVariants  = count($valid);
        $totalProducts  = count(array_unique(array_column($valid, 'item_group_id')));

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title><![CDATA[Marques de France Product Feed – ' . $this->shopName . ']]></title>' . "\n";
        $xml .= '    <link>' . htmlspecialchars($shopUrl, ENT_XML1, 'UTF-8') . '</link>' . "\n";
        $xml .= '    <description><![CDATA[Product feed for Marques de France guide]]></description>' . "\n";
        $xml .= '    <total_products>' . $totalProducts . '</total_products>' . "\n";
        $xml .= '    <total_variants>' . $totalVariants . '</total_variants>' . "\n\n";

        foreach ($valid as $p) {
            $regularPrice = $p['regular_price'] !== '' ? number_format((float) $p['regular_price'], 2, '.', '') . ' ' . $p['currency'] : '';
            $salePrice    = $p['sale_price'] !== '' ? number_format((float) $p['sale_price'], 2, '.', '') . ' ' . $p['currency'] : '';

            $x = static fn(string $v): string => htmlspecialchars($v, ENT_XML1, 'UTF-8');
            $u = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

            $xml .= '    <item>' . "\n";
            $xml .= '      <g:id>'            . $x($p['id'])            . '</g:id>' . "\n";
            $xml .= '      <g:item_group_id>' . $x($p['item_group_id']) . '</g:item_group_id>' . "\n";
            $xml .= '      <g:title><![CDATA[' . $p['title']           . ']]></g:title>' . "\n";
            $xml .= '      <parent_title><![CDATA[' . ($p['parent_title'] ?? $p['title']) . ']]></parent_title>' . "\n";
            $xml .= '      <parent_link>'     . $u($p['parent_link'] ?? $p['link']) . '</parent_link>' . "\n";
            $xml .= '      <g:description><![CDATA[' . $p['description'] . ']]></g:description>' . "\n";
            if ($p['short_description'] ?? '') {
                $xml .= '      <short_description><![CDATA[' . $p['short_description'] . ']]></short_description>' . "\n";
            }
            if ($p['gtin'] ?? '') {
                $xml .= '      <g:gtin>' . $x($p['gtin']) . '</g:gtin>' . "\n";
            }
            if ($p['mpn'] ?? '') {
                $xml .= '      <g:mpn>' . $x($p['mpn']) . '</g:mpn>' . "\n";
            }
            $xml .= '      <g:price>'     . $x($regularPrice) . '</g:price>' . "\n";
            if ($salePrice) {
                $xml .= '      <g:sale_price>' . $x($salePrice) . '</g:sale_price>' . "\n";
            }
            $xml .= '      <g:link>'        . $u($p['link'])  . '</g:link>' . "\n";
            $xml .= '      <g:image_link>'  . $u($p['image']) . '</g:image_link>' . "\n";
            foreach (array_slice($p['additional_images'] ?? [], 0, 10) as $ai) {
                $xml .= '      <g:additional_image_link>' . $u($ai) . '</g:additional_image_link>' . "\n";
            }
            $xml .= '      <parent_image>'  . $u($p['parent_image'] ?? '') . '</parent_image>' . "\n";
            $xml .= '      <variant_image>' . $u($p['variant_image'] ?? '') . '</variant_image>' . "\n";
            if ($p['brand'] ?? '') {
                $xml .= '      <g:brand><![CDATA[' . $p['brand'] . ']]></g:brand>' . "\n";
            }
            if ($p['category'] ?? '') {
                $xml .= '      <g:product_type><![CDATA[' . $p['category'] . ']]></g:product_type>' . "\n";
            }
            $xml .= '      <g:availability>' . $x($p['availability']) . '</g:availability>' . "\n";
            if ($p['availability_date'] ?? '') {
                $xml .= '      <g:availability_date>' . $x($p['availability_date']) . '</g:availability_date>' . "\n";
            }
            if ($p['color'] ?? '') {
                $xml .= '      <g:color>' . $x($p['color']) . '</g:color>' . "\n";
            }
            if ($p['size'] ?? '') {
                $xml .= '      <g:size>' . $x($p['size']) . '</g:size>' . "\n";
            }
            if (!empty($p['tags'])) {
                $xml .= '      <tags>' . $x(implode(', ', $p['tags'])) . '</tags>' . "\n";
            }
            if (!empty($p['shipping'])) {
                $s = $p['shipping'];
                $xml .= '      <g:shipping>' . "\n";
                $xml .= '        <g:country>' . $x($s['country']) . '</g:country>' . "\n";
                if ($s['service'] ?? '') {
                    $xml .= '        <g:service>' . $x($s['service']) . '</g:service>' . "\n";
                }
                $xml .= '        <g:price>' . $x(number_format((float) $s['price'], 2, '.', '') . ' ' . $s['currency']) . '</g:price>' . "\n";
                $xml .= '      </g:shipping>' . "\n";
            }
            $xml .= '      <g:identifier_exists>' . (($p['identifier_exists'] ?? false) ? 'yes' : 'no') . '</g:identifier_exists>' . "\n";
            $xml .= '      <is_cheapest_variant>' . (($p['is_cheapest_variant'] ?? false) ? '1' : '0') . '</is_cheapest_variant>' . "\n";
            $xml .= '      <has_variants>'         . (($p['has_variants'] ?? false) ? '1' : '0') . '</has_variants>' . "\n";
            $xml .= '      <woo_product_type>'     . $x($p['woo_product_type'] ?? '') . '</woo_product_type>' . "\n";
            $xml .= '      <mdf_product_type>'     . $x($p['mdf_product_type'] ?? '') . '</mdf_product_type>' . "\n";
            $xml .= '      <g:condition>'          . $x($p['condition']) . '</g:condition>' . "\n";
            if ($p['google_product_category'] ?? '') {
                $xml .= '      <g:google_product_category>' . $x($p['google_product_category']) . '</g:google_product_category>' . "\n";
            }
            if ($p['gender'] ?? '') {
                $xml .= '      <g:gender>' . $x($p['gender']) . '</g:gender>' . "\n";
            }
            if ($p['age_group'] ?? '') {
                $xml .= '      <g:age_group>' . $x($p['age_group']) . '</g:age_group>' . "\n";
            }
            if ($p['shipping_weight'] ?? '') {
                $xml .= '      <g:shipping_weight>' . $x($p['shipping_weight']) . '</g:shipping_weight>' . "\n";
            }
            if ($p['add_to_cart_url'] ?? '') {
                $xml .= '      <add_to_cart_url>' . $u($p['add_to_cart_url']) . '</add_to_cart_url>' . "\n";
            }
            foreach (array_slice($p['attributes'] ?? [], 0, 3) as $i => $attr) {
                $n    = $i + 1;
                $xml .= '      <custom_attribute_' . $n . '_name>'  . $x($attr['name']  ?? '') . '</custom_attribute_' . $n . '_name>' . "\n";
                $xml .= '      <custom_attribute_' . $n . '_value>' . $x($attr['value'] ?? '') . '</custom_attribute_' . $n . '_value>' . "\n";
            }
            $xml .= '    </item>' . "\n\n";
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    // -----------------------------------------------------------------------
    // PrestaShop product/combination helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductsByTag(string $tag, int $idLang, int $idShop, int $perPage, int $page): array
    {
        $offset = ($page - 1) * $perPage;

        $query = new \DbQuery();
        $query->select('pt.id_product')
              ->from('product_tag', 'pt')
              ->innerJoin('tag', 't', 't.id_tag = pt.id_tag')
              ->innerJoin('product', 'p', 'p.id_product = pt.id_product')
              ->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . $idShop)
              ->leftJoin(
                  'stock_available',
                  'sa',
                  'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $idShop
              )
              ->where('LOWER(t.name) = \'' . \pSQL(strtolower($tag)) . '\'')
              ->where('COALESCE(ps.active, p.active, 0) = 1')
              ->where('COALESCE(ps.price, p.price, 0) > 0')
              ->where($this->eligibilityService->buildStockEligibilityExpression('COALESCE(sa.quantity, 0)', 'COALESCE(sa.out_of_stock, 2)'))
              ->groupBy('pt.id_product')
              ->orderBy('pt.id_product DESC')
              ->limit($perPage, $offset);

        $result = \Db::getInstance()->executeS($query);
        return is_array($result) ? $result : [];
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function getProductsByIds(array $ids, int $idLang, int $idShop, int $perPage, int $page): array
    {
        if (empty($ids)) {
            return [];
        }

        $offset   = ($page - 1) * $perPage;
        $safeIds  = implode(',', array_map('intval', $ids));

        $query = new \DbQuery();
        $query->select('p.id_product')
              ->from('product', 'p')
              ->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . $idShop)
              ->leftJoin(
                  'stock_available',
                  'sa',
                  'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $idShop
              )
              ->where('p.id_product IN (' . $safeIds . ')')
              ->where('COALESCE(ps.active, p.active, 0) = 1')
              ->where('COALESCE(ps.price, p.price, 0) > 0')
              ->where($this->eligibilityService->buildStockEligibilityExpression('COALESCE(sa.quantity, 0)', 'COALESCE(sa.out_of_stock, 2)'))
              ->orderBy('FIELD(p.id_product, ' . $safeIds . ')')
              ->limit($perPage, $offset);

        $result = \Db::getInstance()->executeS($query);
        return is_array($result) ? $result : [];
    }

    /**
     * Get all in-stock combinations for a product with their computed prices.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInStockCombinations(\Product $product, int $idLang): array
    {
        $combinations = $product->getAttributeCombinations($idLang);

        if (!is_array($combinations)) {
            return [];
        }

        // Group by id_product_attribute
        $grouped = [];
        foreach ($combinations as $row) {
            $idPA = (int) $row['id_product_attribute'];
            if (!isset($grouped[$idPA])) {
                $grouped[$idPA] = [
                    'id_product_attribute' => $idPA,
                    'reference'            => $row['reference'] ?? '',
                    'ean13'                => $row['ean13'] ?? '',
                    'isbn'                 => $row['isbn'] ?? '',
                    'upc'                  => $row['upc'] ?? '',
                    'quantity'             => (int) $row['quantity'],
                    'price_computed'       => 0.0,
                    'in_stock'             => (int) $row['quantity'] > 0,
                    'attributes'           => [],
                ];
            }
            $grouped[$idPA]['attributes'][] = [
                'name'  => $row['group_name'] ?? '',
                'value' => $row['attribute_name'] ?? '',
            ];
        }

        // Compute price for each combination
        foreach ($grouped as $idPA => &$combo) {
            $price = \Product::getPriceStatic((int) $product->id, true, $idPA);
            $combo['price_computed'] = (float) $price;
        }
        unset($combo);

        // Filter out-of-stock (unless order-out-of-stock allowed)
        $stockContext = $this->eligibilityService->getProductStockContext((int) $product->id, (int) $this->context->shop->id);
        $allowsOutOfStockOrders = $this->eligibilityService->isOutOfStockPolicyOrderable((int) $stockContext['out_of_stock']);
        if (!$allowsOutOfStockOrders) {
            $grouped = array_filter($grouped, fn ($c) => $c['in_stock']);
        }

        foreach ($grouped as &$combo) {
            $combo['allows_out_of_stock_orders'] = $allowsOutOfStockOrders;
        }
        unset($combo);

        return array_values($grouped);
    }

    // -----------------------------------------------------------------------
    // Image helpers
    // -----------------------------------------------------------------------

    private function getProductImageUrl(\Product $product): string
    {
        $cover = \Image::getCover((int) $product->id);
        if (!$cover) {
            return '';
        }
        return $this->context->link->getImageLink(
            $product->link_rewrite,
            (int) $cover['id_image'],
            'large_default'
        );
    }

    private function getCombinationImageUrl(\Product $product, int $idProductAttribute): string
    {
        $images = $product->getCombinationImages((int) $this->context->language->id);
        if (is_array($images) && isset($images[$idProductAttribute][0])) {
            $imageId = (int) $images[$idProductAttribute][0]['id_image'];
            return $this->context->link->getImageLink(
                $product->link_rewrite,
                $imageId,
                'large_default'
            );
        }
        return '';
    }

    /**
     * @return string[]
     */
    private function getAdditionalImageUrls(\Product $product): array
    {
        $images = \Image::getImages((int) $this->context->language->id, (int) $product->id);
        if (!is_array($images)) {
            return [];
        }
        $urls = [];
        foreach ($images as $img) {
            $urls[] = $this->context->link->getImageLink(
                $product->link_rewrite,
                (int) $img['id_image'],
                'large_default'
            );
        }
        return $urls;
    }

    // -----------------------------------------------------------------------
    // Metadata helpers
    // -----------------------------------------------------------------------

    private function getProductBrand(\Product $product): string
    {
        if ($product->id_manufacturer) {
            $manufacturerName = \Manufacturer::getNameById((int) $product->id_manufacturer);
            if ($manufacturerName) {
                return html_entity_decode((string) $manufacturerName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $this->shopName;
    }

    private function getCategoryPath(\Product $product, int $idLang): string
    {
        $categories = $product->getCategories();
        if (empty($categories)) {
            return '';
        }

        $names = [];
        foreach ($categories as $catId) {
            $cat = new \Category((int) $catId, $idLang);
            if (\Validate::isLoadedObject($cat) && !$cat->is_root_category) {
                $names[] = $cat->name;
            }
        }

        return implode(' > ', array_unique($names));
    }

    /**
     * @return string[]
     */
    private function getProductTags(\Product $product, int $idLang): array
    {
        $tags = \Tag::getProductTags((int) $product->id);
        if (!is_array($tags) || !isset($tags[$idLang])) {
            return [];
        }
        return $tags[$idLang];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getProductFeatures(\Product $product, int $idLang): array
    {
        $features = $product->getFrontFeatures($idLang);
        if (!is_array($features)) {
            return [];
        }
        $result = [];
        foreach (array_slice($features, 0, 3) as $feature) {
            $result[] = [
                'name'  => $feature['name'] ?? '',
                'value' => $feature['value'] ?? '',
            ];
        }
        return $result;
    }

    private function getProductPrice(\Product $product, bool $withTax = true): string
    {
        $price = \Product::getPriceStatic((int) $product->id, $withTax);
        return $price > 0 ? (string) $price : '';
    }

    private function getProductSalePrice(\Product $product): string
    {
        // PS doesn't natively separate regular vs sale the way WC does
        // Return empty — the regular price IS the displayed price
        return '';
    }

    private function getAvailabilityString(\Product $product): string
    {
        if ($product->checkQty(1)) {
            return 'in stock';
        }

        $stockContext = $this->eligibilityService->getProductStockContext((int) $product->id, (int) $this->context->shop->id);
        if ($this->eligibilityService->isOutOfStockPolicyOrderable((int) $stockContext['out_of_stock'])) {
            return 'preorder';
        }

        return 'out of stock';
    }

    private function getCombinationColor(array $combo): string
    {
        foreach ($combo['attributes'] ?? [] as $attr) {
            $name = strtolower($attr['name'] ?? '');
            if (str_contains($name, 'color') || str_contains($name, 'colour') || str_contains($name, 'couleur')) {
                return $attr['value'] ?? '';
            }
        }
        return '';
    }

    private function getCombinationSize(array $combo): string
    {
        $sizeKeys = ['size', 'taille', 'talla'];
        foreach ($combo['attributes'] ?? [] as $attr) {
            $name = strtolower($attr['name'] ?? '');
            if (in_array($name, $sizeKeys, true)) {
                return $attr['value'] ?? '';
            }
        }
        return '';
    }

    private function inferGender(\Product $product, int $idLang): string
    {
        $genderMap = [
            'homme'    => 'male',   'men'    => 'male',   'man'      => 'male',
            'masculin' => 'male',   'male'   => 'male',
            'femme'    => 'female', 'women'  => 'female', 'woman'    => 'female',
            'feminin'  => 'female', 'female' => 'female',
            'unisex'   => 'unisex', 'mixte'  => 'unisex',
        ];

        // Check product features first
        $features = $product->getFrontFeatures($idLang);
        if (is_array($features)) {
            foreach ($features as $f) {
                $fName = strtolower($f['name'] ?? '');
                if (in_array($fName, ['genre', 'gender', 'sexe'], true)) {
                    $val = strtolower($f['value'] ?? '');
                    if (isset($genderMap[$val])) {
                        return $genderMap[$val];
                    }
                }
            }
        }

        // Fall back to tags
        foreach ($this->getProductTags($product, $idLang) as $tag) {
            $key = strtolower($tag);
            if (isset($genderMap[$key])) {
                return $genderMap[$key];
            }
        }

        return '';
    }

    private function inferAgeGroup(\Product $product, int $idLang): string
    {
        $ageMap = [
            'newborn'   => 'newborn', 'nouveau-ne' => 'newborn',
            'infant'    => 'infant',  'bebe'       => 'infant',  'nourrisson' => 'infant',
            'toddler'   => 'toddler', 'bambin'     => 'toddler',
            'kids'      => 'kids',    'enfant'     => 'kids',    'child'      => 'kids',
            'adult'     => 'adult',   'adulte'     => 'adult',
        ];

        foreach ($this->getProductTags($product, $idLang) as $tag) {
            $key = strtolower($tag);
            if (isset($ageMap[$key])) {
                return $ageMap[$key];
            }
        }

        return '';
    }

    private function getGoogleProductCategory(string $category): string
    {
        $apparelMap = [
            't-shirt'    => 'Vêtements', 'tshirt'    => 'Vêtements', 'chemise'   => 'Vêtements',
            'robe'       => 'Vêtements', 'pantalon'  => 'Vêtements', 'veste'     => 'Vêtements',
            'manteau'    => 'Vêtements', 'pull'      => 'Vêtements', 'sweat'     => 'Vêtements',
            'chaussures' => 'Chaussures', 'sneakers' => 'Chaussures', 'bottes'   => 'Chaussures',
            'casquette'  => 'Accessoires vestimentaires', 'chapeau' => 'Accessoires vestimentaires',
            'sac'        => 'Bagages et sacs',            'ceinture'=> 'Accessoires vestimentaires',
        ];

        $lower = strtolower($category);
        foreach ($apparelMap as $keyword => $gmc) {
            if (str_contains($lower, $keyword)) {
                return $gmc;
            }
        }

        return '';
    }

    /**
     * @return array{country: string, service: string, price: float, currency: string}
     */
    private function getShippingBlock(): array
    {
        return [
            'country'  => defined('MDFCFORPS_FEED_SHIPPING_COUNTRY')  ? MDFCFORPS_FEED_SHIPPING_COUNTRY  : 'FR',
            'service'  => defined('MDFCFORPS_FEED_SHIPPING_SERVICE')  ? MDFCFORPS_FEED_SHIPPING_SERVICE  : 'Standard',
            'price'    => defined('MDFCFORPS_FEED_SHIPPING_PRICE')    ? (float) MDFCFORPS_FEED_SHIPPING_PRICE    : 0.0,
            'currency' => defined('MDFCFORPS_FEED_SHIPPING_CURRENCY') ? MDFCFORPS_FEED_SHIPPING_CURRENCY : $this->currency,
        ];
    }

    // -----------------------------------------------------------------------
    // Rich HTML sanitiser (11-step pipeline, identical to WC plugin)
    // -----------------------------------------------------------------------

    private function sanitizeRichHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // 0. Remove <style>, <script>, <noscript> blocks
        $html = (string) preg_replace('/<style[\s\S]*?<\/style>/i', '', $html);
        $html = (string) preg_replace('/<script[\s\S]*?<\/script>/i', '', $html);
        $html = (string) preg_replace('/<noscript[\s\S]*?<\/noscript>/i', '', $html);

        // 1. Strip ALL attributes from every tag
        $html = (string) preg_replace('/<(\/?[\w][\w-]*)\b[^>]*>/i', '<$1>', $html);

        // 2. Unwrap <a> links — keep inner text
        $html = (string) preg_replace('/<a>([\s\S]*?)<\/a>/i', '$1', $html);

        // 3. Convert headings → <p><strong>TEXT</strong></p>
        $html = (string) preg_replace('/<h[1-6]>([\s\S]*?)<\/h[1-6]>/i', '<p><strong>$1</strong></p>', $html);

        // 4. Collapse duplicate nested same-tag wrappers
        $html = (string) preg_replace('/<(strong|b|u|em|i)><\1>/', '<$1>', $html);
        $html = (string) preg_replace('/<\/(strong|b|u|em|i)><\/\1>/', '</$1>', $html);

        // 5. Discard non-semantic block wrappers
        $html = (string) preg_replace('/<\/?(div|section|article|header|footer)>/i', '', $html);

        // 6. Normalise <br> variants
        $html = (string) preg_replace('/<br>/i', '<br>', $html);

        // 7. Strip tags not in allowed set
        $html = (string) preg_replace('/<(?!\/?(?:p|ul|ol|li|strong|b|u|em|i|br)\b)[^>]+>/i', '', $html);

        // 8. Remove emojis
        $html = (string) preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $html);
        $html = (string) preg_replace('/[\x{2600}-\x{27BF}]/u',   '', $html);
        $html = (string) preg_replace('/[\x{FE00}-\x{FE0F}]/u',   '', $html);
        $html = (string) preg_replace('/\x{200D}/u',               '', $html);

        // 9. Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 10. Remove empty paragraphs
        $html = (string) preg_replace('/<p>\s*<\/p>/i', '', $html);

        // 11. Trim
        return trim($html);
    }
}
