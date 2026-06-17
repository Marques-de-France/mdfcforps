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

use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Decorates the ProductCatalog data factory to format price, image URL, availability badge,
 * and pre-built checkbox HTML for the AJAX toggle.
 */
final class ProductCatalogDataDecorator implements GridDataFactoryInterface
{
    /** @var GridDataFactoryInterface */
    private $inner;

    /** @var \Link */
    private $link;

    /** @var array<int, int> */
    private $combinationCountCache = [];

    public function __construct(GridDataFactoryInterface $inner)
    {
        $this->inner = $inner;
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
            $inFeed = (bool) ($record['in_feed'] ?? false);

            // Image URL
            $imageId = (int) ($record['id_image'] ?? 0);
            $record['image'] = $imageId
                ? (string) $this->link->getImageLink('product', $imageId, 'small_default')
                : '';

            // Formatted price
            $record['price'] = $this->formatPrice((float) ($record['price_raw'] ?? 0));

            // Link product name to BO edit page in a new tab.
            $name = (string) ($record['name'] ?? '');
            $productsUrl = (string) $this->link->getAdminLink('AdminProducts', true);
            $urlParts = parse_url($productsUrl);
            $editPath = rtrim((string) ($urlParts['path'] ?? ''), '/') . '/' . $pid . '/edit';
            $editUrl = $editPath;
            if (!empty($urlParts['query'])) {
                $editUrl .= '?' . $urlParts['query'];
            }

            $linkedName = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                htmlspecialchars((string) $editUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            );

            // "X combinations" badge — mirrors the Products-in-feed grid.
            $combinationCount = $this->getCombinationCount($pid);
            if ($combinationCount > 0) {
                $linkedName .= ' <span class="badge badge-secondary">'
                    . $combinationCount
                    . ' ' . htmlspecialchars($this->trans('combinations'), ENT_QUOTES, 'UTF-8') . '</span>';
            }

            $record['linked_name'] = $linkedName;

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

            // Checkbox HTML — carries data attributes used by feed.js toggleProduct()
            $checked = $inFeed ? ' checked' : '';
            $record['checkbox_html'] = sprintf(
                '<div class="md-checkbox md-checkbox-inline"><label><input type="checkbox" class="mdf-manage-cb form-check-input"%s data-product-id="%d" data-in-feed="%d"><i class="md-checkbox-control"></i></label></div>',
                $checked,
                $pid,
                $inFeed ? 1 : 0
            );

            $records[] = $record;
        }

        return new GridData(new RecordCollection($records), $data->getRecordsTotal(), $data->getQuery());
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
