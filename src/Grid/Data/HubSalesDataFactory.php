<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 */

declare(strict_types=1);

namespace Mdfcforps\Grid\Data;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mdfcforps\Service\HubClient;
use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Hub-backed data source for the sales grid.
 */
final class HubSalesDataFactory implements GridDataFactoryInterface
{
    /** @var HubClient */
    private $hubClient;

    public function __construct(HubClient $hubClient)
    {
        $this->hubClient = $hubClient;
    }

    public function getData(SearchCriteriaInterface $searchCriteria)
    {
        $limit = max(1, (int) ($searchCriteria->getLimit() ?? 25));
        $offset = max(0, (int) ($searchCriteria->getOffset() ?? 0));
        $page = (int) floor($offset / $limit) + 1;

        $sortFieldMap = [
            'order_reference' => 'orderId',
            'amount' => 'amount',
            'status' => 'status',
            'created_at' => 'createdAt',
        ];

        $orderBy = (string) ($searchCriteria->getOrderBy() ?? 'created_at');
        $orderWay = strtolower((string) ($searchCriteria->getOrderWay() ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $filters = $searchCriteria->getFilters();

        $hubFilters = [
            'search' => trim((string) ($filters['order_reference'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'source' => trim((string) ($filters['source'] ?? '')),
            'amountMin' => is_array($filters['amount'] ?? null) ? (string) (($filters['amount']['min_field'] ?? '') ?: '') : '',
            'amountMax' => is_array($filters['amount'] ?? null) ? (string) (($filters['amount']['max_field'] ?? '') ?: '') : '',
            'sortField' => $sortFieldMap[$orderBy] ?? 'createdAt',
            'sortDir' => $orderWay,
        ];

        $response = $this->hubClient->getHubSalesList($page, $limit, $hubFilters);
        $sales = is_array($response['sales'] ?? null) ? $response['sales'] : [];

        $records = [];
        foreach ($sales as $sale) {
            if (!is_array($sale)) {
                continue;
            }

            $records[] = [
                'order_reference' => (string) (($sale['orderName'] ?? '') !== '' ? $sale['orderName'] : ('#' . (string) ($sale['orderId'] ?? ''))),
                'amount' => (float) ($sale['amount'] ?? 0),
                'currency' => (string) ($sale['currency'] ?? 'EUR'),
                'attribution_source' => (string) ($sale['attributionSource'] ?? 'unknown'),
                'status' => (string) ($sale['status'] ?? 'pending'),
                'hub_synced' => 1,
                'hub_sync_attempts' => 0,
                'created_at' => (string) ($sale['createdAt'] ?? ''),
            ];
        }

        return new GridData(
            new RecordCollection($records),
            (int) ($response['total'] ?? 0),
            ''
        );
    }
}
