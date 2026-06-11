<?php

declare(strict_types=1);

namespace Mdfcforps\Grid\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;

/**
 * Builds Doctrine queries for the "Products in Feed" grid.
 * Source table: ps_mdfcforps_feed_products joined to product catalog.
 */
final class ProductFeedQueryBuilder extends AbstractDoctrineQueryBuilder
{
    /** @var int */
    private $contextLangId;

    /** @var int */
    private $contextShopId;

    /** @var bool */
    private $allowOrderOutOfStockByDefault;

    /** Map grid column IDs to SQL order expressions */
    private const ORDER_MAP = [
        'name'         => 'pl.name',
        'brand'        => 'm.name',
        'reference'    => 'p.reference',
        'price'        => 'ps.price',
        'availability' => 'sa.quantity',
        'active'       => 'ps.active',
    ];

    public function __construct(
        Connection $connection,
        string $dbPrefix,
        int $contextLangId,
        int $contextShopId,
        $globalOutOfStockDefault
    )
    {
        parent::__construct($connection, $dbPrefix);
        $this->contextLangId = $contextLangId;
        $this->contextShopId = $contextShopId;
        $this->allowOrderOutOfStockByDefault = (int) $globalOutOfStockDefault === 1;
    }

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getBaseQuery()
            ->select(
                'p.id_product AS id',
                'COALESCE(pl.name, \'\') AS name',
                'COALESCE(m.name, \'\') AS brand',
                'COALESCE(p.reference, \'\') AS reference',
                'COALESCE(ps.price, p.price, 0) AS price_raw',
                'COALESCE(ps.active, p.active, 0) AS active',
                'COALESCE(sa.quantity, 0) AS quantity',
                'COALESCE(ci.id_image, 0) AS id_image',
                'CASE
                    WHEN COALESCE(sa.out_of_stock, 2) = 1 THEN 1
                    WHEN COALESCE(sa.out_of_stock, 2) = 0 THEN 0
                    WHEN :allow_order_out_of_stock_by_default = 1 THEN 1
                    ELSE 0
                END AS allow_orders'
            );

        $this->applyFilters($qb, $searchCriteria->getFilters());

        $orderBy  = self::ORDER_MAP[$searchCriteria->getOrderBy()] ?? 'pl.name';
        $orderWay = in_array(strtolower((string) $searchCriteria->getOrderWay()), ['asc', 'desc'])
            ? strtoupper((string) $searchCriteria->getOrderWay())
            : 'ASC';

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
        $qb = $this->getBaseQuery()->select('COUNT(DISTINCT p.id_product)');
        $this->applyFilters($qb, $searchCriteria->getFilters());

        return $qb;
    }

    private function getBaseQuery(): QueryBuilder
    {
        return $this->connection
            ->createQueryBuilder()
            ->from($this->dbPrefix . 'mdfcforps_feed_products', 'fp')
            ->innerJoin('fp', $this->dbPrefix . 'product', 'p', 'p.id_product = fp.product_id')
            ->leftJoin('p', $this->dbPrefix . 'product_lang', 'pl',
                'pl.id_product = p.id_product AND pl.id_lang = :ctx_lang AND pl.id_shop = :ctx_shop')
            ->leftJoin('p', $this->dbPrefix . 'product_shop', 'ps',
                'ps.id_product = p.id_product AND ps.id_shop = :ctx_shop')
            ->leftJoin('p', $this->dbPrefix . 'manufacturer', 'm',
                'm.id_manufacturer = p.id_manufacturer')
            ->leftJoin('p', $this->dbPrefix . 'stock_available', 'sa',
                'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = :ctx_shop')
            ->leftJoin('p', $this->dbPrefix . 'image', 'ci',
                'ci.id_product = p.id_product AND ci.cover = 1')
            // Keep grid output consistent with feed output eligibility.
            ->andWhere('COALESCE(ps.active, p.active, 0) = 1')
            ->andWhere('(
                COALESCE(sa.quantity, 0) > 0
                OR COALESCE(sa.out_of_stock, 2) = 1
                OR (
                    COALESCE(sa.out_of_stock, 2) = 2
                    AND :allow_order_out_of_stock_by_default = 1
                )
            )')
            ->andWhere('COALESCE(ps.price, p.price, 0) > 0')
            ->setParameter('ctx_lang', $this->contextLangId)
            ->setParameter('ctx_shop', $this->contextShopId)
            ->setParameter('allow_order_out_of_stock_by_default', $this->allowOrderOutOfStockByDefault ? 1 : 0);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $name => $value) {
            switch ($name) {
                case 'name':
                    $value = trim((string) $value);
                    if ('' === $value) {
                        break;
                    }

                    $qb->andWhere('pl.name LIKE :filter_name')
                       ->setParameter('filter_name', '%' . $value . '%');
                    break;
                case 'brand':
                    $value = trim((string) $value);
                    if ('' === $value) {
                        break;
                    }

                    $qb->andWhere('m.name LIKE :filter_brand')
                       ->setParameter('filter_brand', '%' . $value . '%');
                    break;
                case 'reference':
                    $value = trim((string) $value);
                    if ('' === $value) {
                        break;
                    }

                    $qb->andWhere('p.reference LIKE :filter_ref')
                       ->setParameter('filter_ref', '%' . $value . '%');
                    break;
                case 'availability':
                    $value = trim((string) $value);
                    if ($value === 'in_stock') {
                        $qb->andWhere('COALESCE(sa.quantity, 0) > 0');
                    } elseif ($value === 'out_of_stock') {
                        $qb->andWhere('COALESCE(sa.quantity, 0) <= 0');
                    }
                    break;
                case 'price':
                    if (!is_array($value)) {
                        break;
                    }

                    $min = isset($value['min_field']) && $value['min_field'] !== '' ? (float) $value['min_field'] : null;
                    $max = isset($value['max_field']) && $value['max_field'] !== '' ? (float) $value['max_field'] : null;
                    if (null !== $min) {
                        $qb->andWhere('COALESCE(ps.price, p.price, 0) >= :filter_price_min')
                           ->setParameter('filter_price_min', $min);
                    }
                    if (null !== $max) {
                        $qb->andWhere('COALESCE(ps.price, p.price, 0) <= :filter_price_max')
                           ->setParameter('filter_price_max', $max);
                    }
                    break;
            }
        }
    }
}
