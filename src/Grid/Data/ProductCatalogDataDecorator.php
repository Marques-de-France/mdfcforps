<?php

declare(strict_types=1);

namespace Mdfcforps\Grid\Data;

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

    public function __construct(GridDataFactoryInterface $inner)
    {
        $this->inner = $inner;
        $this->link  = \Context::getContext()->link;
    }

    public function getData(SearchCriteriaInterface $searchCriteria)
    {
        $data    = $this->inner->getData($searchCriteria);
        $records = [];

        foreach ($data->getRecords() as $record) {
            $pid    = (int) ($record['id'] ?? 0);
            $inFeed = (bool) ($record['in_feed'] ?? false);

            // Image URL
            $imageId = (int) ($record['id_image'] ?? 0);
            $record['image'] = $imageId
                ? (string) $this->link->getImageLink('product', $imageId, 'small_default')
                : '';

            // Formatted price
            $record['price'] = \Tools::displayPrice((float) ($record['price_raw'] ?? 0));

            // Link product name to BO edit page in a new tab.
            $name = (string) ($record['name'] ?? '');
            $productsUrl = (string) $this->link->getAdminLink('AdminProducts', true);
            $urlParts = parse_url($productsUrl);
            $editPath = rtrim((string) ($urlParts['path'] ?? ''), '/') . '/' . $pid . '/edit';
            $editUrl = $editPath;
            if (!empty($urlParts['query'])) {
                $editUrl .= '?' . $urlParts['query'];
            }

            $record['linked_name'] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                htmlspecialchars((string) $editUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            );

            // Availability badge HTML
            $qty = (int) ($record['quantity'] ?? 0);
            if ($qty > 0) {
                $record['availability'] = '<span class="badge badge-success">In stock</span>';
            } else {
                $record['availability'] = '<span class="badge badge-danger">Out of stock</span>';
            }

            $allowOrders = (bool) ($record['allow_orders'] ?? false);
            $record['allow_orders_badge'] = $allowOrders
                ? '<span class="badge badge-success">Allow orders</span>'
                : '<span class="badge badge-danger">Deny orders</span>';

            $active = (bool) ($record['active'] ?? false);
            $record['status_badge'] = $active
                ? '<span class="badge badge-success">Enabled</span>'
                : '<span class="badge badge-danger">Disabled</span>';

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
}
