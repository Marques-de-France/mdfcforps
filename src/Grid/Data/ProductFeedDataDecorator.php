<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Grid\Data;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mdfcforps\Service\PriceResolver;
use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Decorates the ProductFeed data factory to format price, image URL, and availability badge.
 */
final class ProductFeedDataDecorator implements GridDataFactoryInterface
{
    /** @var GridDataFactoryInterface */
    private $inner;

    /** @var \Link */
    private $link;

    /** @var array<int, int> */
    private $combinationCountCache = [];

    /** @var PriceResolver */
    private $priceResolver;

    public function __construct(GridDataFactoryInterface $inner, ?PriceResolver $priceResolver = null)
    {
        $this->inner = $inner;
        $this->priceResolver = $priceResolver ?: new PriceResolver();
        $this->link = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()
            ->get('prestashop.adapter.legacy.context')
            ->getContext()
            ->link;
    }

    public function getData(SearchCriteriaInterface $searchCriteria)
    {
        $data = $this->inner->getData($searchCriteria);
        $records = [];

        foreach ($data->getRecords() as $record) {
            $pid = (int) ($record['id'] ?? 0);

            // Image URL
            $imageId = (int) ($record['id_image'] ?? 0);
            $record['image'] = $imageId
                ? (string) $this->link->getImageLink('product', $imageId, 'small_default')
                : '';

            // Link product name to BO edit page in a new tab.
            // PS 8+ uses the Symfony route 'admin_products_edit'; PS 1.7.x uses the legacy AdminProducts URL.
            $name = (string) ($record['name'] ?? '');
            try {
                $editUrl = (string) $this->link->getAdminLink('AdminProducts', true, [
                    'route' => 'admin_products_edit',
                    'productId' => $pid,
                ]);
            } catch (\Exception $e) {
                // PS 1.7.x fallback: legacy controller URL
                $editUrl = (string) $this->link->getAdminLink('AdminProducts', true)
                    . '&id_product=' . $pid . '&updateproduct';
            }

            $combinationCount = $this->getCombinationCount($pid);
            $nameHtml = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

            $linkedName = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                htmlspecialchars((string) $editUrl, ENT_QUOTES, 'UTF-8'),
                $nameHtml
            );

            if ($combinationCount > 0) {
                $linkedName .= ' <span class="badge badge-secondary">'
                    . $combinationCount
                    . ' ' . htmlspecialchars($this->transCombinations($combinationCount), ENT_QUOTES, 'UTF-8') . '</span>';
            }

            $record['linked_name'] = $linkedName;

            // Price cell — mirrors what the feed publishes: tax included, discounts
            // applied. Falls back to the raw catalog price if the product can no
            // longer be priced (deleted mid-request, missing combination, ...).
            $record['price'] = $this->buildPriceHtml($pid, (float) ($record['price_raw'] ?? 0));

            // Availability badge HTML
            $qty = (int) ($record['quantity'] ?? 0);
            $allowOrders = (bool) ($record['allow_orders'] ?? false);
            if ($qty > 0) {
                $record['availability'] = '<span class="badge badge-success">' . htmlspecialchars($this->trans('In stock'), ENT_QUOTES, 'UTF-8') . '</span>';
            } elseif ($allowOrders) {
                $record['availability'] = '<span class="badge badge-success">' . htmlspecialchars($this->trans('Out of stock but allow orders'), ENT_QUOTES, 'UTF-8') . '</span>';
            } else {
                $record['availability'] = '<span class="badge badge-danger">' . htmlspecialchars($this->trans('Out of stock'), ENT_QUOTES, 'UTF-8') . '</span>';
            }

            $active = (bool) ($record['active'] ?? false);
            $record['status_badge'] = $active
                ? '<span class="badge badge-success">' . htmlspecialchars($this->trans('Enabled'), ENT_QUOTES, 'UTF-8') . '</span>'
                : '<span class="badge badge-danger">' . htmlspecialchars($this->trans('Disabled'), ENT_QUOTES, 'UTF-8') . '</span>';

            $records[] = $record;
        }

        return new GridData(new RecordCollection($records), $data->getRecordsTotal(), $data->getQuery());
    }

    /**
     * Render the price cell: a single price normally, or the regular price struck
     * through followed by the discounted price when a specific price / catalog price
     * rule applies.
     */
    private function buildPriceHtml(int $productId, float $rawPrice): string
    {
        if ($productId <= 0) {
            return htmlspecialchars($this->formatPrice($rawPrice), ENT_QUOTES, 'UTF-8');
        }

        $prices = $this->priceResolver->resolve($productId);

        // getPriceStatic() returns null for a product it cannot price; resolve() casts
        // that to 0.0, so fall back to the catalog price rather than showing "0,00 €".
        if ($prices['effective'] <= 0) {
            return htmlspecialchars($this->formatPrice($rawPrice), ENT_QUOTES, 'UTF-8');
        }

        $effective = htmlspecialchars($this->formatPrice($prices['effective']), ENT_QUOTES, 'UTF-8');

        if (!$prices['on_sale']) {
            return $effective;
        }

        $regular = htmlspecialchars($this->formatPrice($prices['regular']), ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<span class="mdf-price-regular">%s</span> <span class="mdf-price-sale">%s</span>',
            $regular,
            $effective
        );
    }

    private function getCombinationCount(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        if (isset($this->combinationCountCache[$productId])) {
            return $this->combinationCountCache[$productId];
        }

        $query = new \DbQuery();
        $query->select('COUNT(*)')
              ->from('product_attribute')
              ->where('id_product = ' . (int) $productId);

        $count = (int) \Db::getInstance()->getValue($query);
        $this->combinationCountCache[$productId] = $count;

        return $count;
    }

    private function trans(string $message): string
    {
        return \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()
            ->get('prestashop.adapter.legacy.context')
            ->getContext()
            ->getTranslator()
            ->trans($message, [], 'Modules.Mdfcforps.Admin');
    }

    /**
     * Singular/plural form of "combinations" for the count badge.
     *
     * Two separate catalogue entries rather than the translator's own pluralisation:
     * the module supports PrestaShop 1.7.7.5 (Symfony 3.4) through 9 (Symfony 6.4), and
     * neither mechanism spans that range — transChoice() was removed in Symfony 5, while
     * trans() with %count% and the "singular|plural" syntax does not pluralise in 3.4.
     *
     * Callers only render the badge for counts >= 1, so "> 1" gives the right form in
     * both English and French.
     */
    private function transCombinations(int $count): string
    {
        return $count > 1 ? $this->trans('combinations') : $this->trans('combination');
    }

    private function formatPrice(float $amount): string
    {
        $context = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()
            ->get('prestashop.adapter.legacy.context')
            ->getContext();
        $isoCode = isset($context->currency)
            ? (string) $context->currency->iso_code
            : 'EUR';

        if (isset($context->currentLocale)) {
            return (string) $context->currentLocale->formatPrice($amount, $isoCode);
        }

        return number_format($amount, 2, '.', ' ') . ' ' . $isoCode;
    }
}
