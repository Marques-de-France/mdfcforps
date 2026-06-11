<?php

declare(strict_types=1);

namespace Mdfcforps\Grid\Data;

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
            $pid = (int) ($record['id'] ?? 0);

            // Image URL
            $imageId  = (int) ($record['id_image'] ?? 0);
            $record['image'] = $imageId
                ? (string) $this->link->getImageLink('product', $imageId, 'small_default')
                : '';

            // Link product name to BO edit page in a new tab using the documented PrestaShop route generation.
            $name = (string) ($record['name'] ?? '');
            $editUrl = (string) $this->link->getAdminLink('AdminProducts', true, [
                'route' => 'admin_products_edit',
                'productId' => $pid,
            ]);

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
                    . ' combinations</span>';
            }

            $record['linked_name'] = $linkedName;

            // Formatted price
            $record['price'] = \Tools::displayPrice((float) ($record['price_raw'] ?? 0));

            // Availability badge HTML
            $qty = (int) ($record['quantity'] ?? 0);
            $allowOrders = (bool) ($record['allow_orders'] ?? false);
            if ($qty > 0) {
                $record['availability'] = '<span class="badge badge-success">In stock</span>';
            } elseif ($allowOrders) {
                $record['availability'] = '<span class="badge badge-success">Out of stock but allow orders</span>';
            } else {
                $record['availability'] = '<span class="badge badge-danger">Out of stock</span>';
            }

            $active = (bool) ($record['active'] ?? false);
            $record['status_badge'] = $active
                ? '<span class="badge badge-success">Enabled</span>'
                : '<span class="badge badge-danger">Disabled</span>';

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
}
