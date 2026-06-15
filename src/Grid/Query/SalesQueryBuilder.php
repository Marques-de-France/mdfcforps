<?php

/**
 * Module source file.
 *
 * @author Marques de France
 */

declare(strict_types=1);

namespace Mdfcforps\Grid\Query;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Builds Doctrine queries for the "Sales" grid.
 */
final class SalesQueryBuilder extends AbstractDoctrineQueryBuilder
{
    private const ORDER_MAP = [
        'order_reference' => 's.order_reference',
        'amount' => 's.amount',
        'currency' => 's.currency',
        'source' => 's.attribution_source',
        'status' => 's.status',
        'synced' => 's.hub_synced',
        'created_at' => 's.created_at',
    ];

    public function __construct(Connection $connection, string $dbPrefix)
    {
        parent::__construct($connection, $dbPrefix);
    }

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getBaseQuery()
            ->select(
                's.id',
                's.order_reference',
                's.amount',
                's.currency',
                's.attribution_source',
                's.status',
                's.hub_synced',
                's.hub_sync_attempts',
                's.created_at'
            );

        $this->applyFilters($qb, $searchCriteria->getFilters());

        $orderBy = self::ORDER_MAP[$searchCriteria->getOrderBy()] ?? 's.created_at';
        $orderWay = in_array(strtolower((string) $searchCriteria->getOrderWay()), ['asc', 'desc'], true)
            ? strtoupper((string) $searchCriteria->getOrderWay())
            : 'DESC';

        $qb->orderBy($orderBy, $orderWay);

        if (null !== $searchCriteria->getLimit()) {
            $qb->setMaxResults($searchCriteria->getLimit());
        }
        if (null !== $searchCriteria->getOffset()) {
            $qb->setFirstResult($searchCriteria->getOffset());
        }

        return $qb;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getBaseQuery()->select('COUNT(s.id)');
        $this->applyFilters($qb, $searchCriteria->getFilters());

        return $qb;
    }

    private function getBaseQuery(): QueryBuilder
    {
        return $this->connection
            ->createQueryBuilder()
            ->from($this->dbPrefix . 'mdfcforps_sales', 's');
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $name => $value) {
            switch ($name) {
                case 'order_reference':
                    $value = trim((string) $value);
                    if ($value === '') {
                        break;
                    }

                    $searchValue = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
                    $qb->andWhere('LOWER(s.order_reference) LIKE LOWER(:filter_order_reference)')
                        ->setParameter('filter_order_reference', $searchValue);
                    break;
                case 'source':
                    $value = trim((string) $value);
                    if ($value === '') {
                        break;
                    }

                    $searchValue = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
                    $qb->andWhere('LOWER(s.attribution_source) LIKE LOWER(:filter_source)')
                        ->setParameter('filter_source', $searchValue);
                    break;
                case 'status':
                    $value = trim((string) $value);
                    if ($value === '') {
                        break;
                    }

                    $qb->andWhere('s.status = :filter_status')
                        ->setParameter('filter_status', $value);
                    break;
                case 'synced':
                    $value = trim((string) $value);
                    if ($value === '') {
                        break;
                    }

                    if ($value === 'yes') {
                        $qb->andWhere('s.hub_synced = 1');
                    } elseif ($value === 'pending') {
                        $qb->andWhere('s.hub_synced = 0')
                            ->andWhere('s.hub_sync_attempts < 5');
                    } elseif ($value === 'failed') {
                        $qb->andWhere('s.hub_synced = 0')
                            ->andWhere('s.hub_sync_attempts >= 5');
                    }
                    break;
            }
        }
    }
}
