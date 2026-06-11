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

    /** Map grid column IDs to SQL order expressions */
    private const ORDER_MAP = [
        'name'         => 'pl.name',
        'brand'        => 'm.name',
        'reference'    => 'p.reference',
        'price'        => 'ps.price',
        'availability' => 'sa.quantity',
        'active'       => 'ps.active',
    ];

    public function __construct(Connection $connection, string $dbPrefix, int $contextLangId, int $contextShopId)
    {
        parent::__construct($connection, $dbPrefix);
        $this->contextLangId = $contextLangId;
        $this->contextShopId = $contextShopId;
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
                'COALESCE(ci.id_image, 0) AS id_image'
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
            ->setParameter('ctx_lang', $this->contextLangId)
            ->setParameter('ctx_shop', $this->contextShopId);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $name => $value) {
            $value = trim((string) $value);
            if ('' === $value) {
                continue;
            }

            switch ($name) {
                case 'name':
                    $qb->andWhere('pl.name LIKE :filter_name')
                       ->setParameter('filter_name', '%' . $value . '%');
                    break;
                case 'brand':
                    $qb->andWhere('m.name LIKE :filter_brand')
                       ->setParameter('filter_brand', '%' . $value . '%');
                    break;
                case 'reference':
                    $qb->andWhere('p.reference LIKE :filter_ref')
                       ->setParameter('filter_ref', '%' . $value . '%');
                    break;
                case 'availability':
                    $qb->andWhere("(CASE WHEN COALESCE(sa.quantity, 0) > 0 THEN 'In stock' ELSE 'Out of stock' END) LIKE :filter_availability")
                       ->setParameter('filter_availability', '%' . $value . '%');
                    break;
                case 'price':
                    $qb->andWhere('CAST(COALESCE(ps.price, p.price, 0) AS CHAR) LIKE :filter_price')
                       ->setParameter('filter_price', '%' . $value . '%');
                    break;
                case 'status':
                    $qb->andWhere("(CASE WHEN COALESCE(ps.active, p.active, 0) = 1 THEN 'Enabled' ELSE 'Disabled' END) LIKE :filter_status")
                       ->setParameter('filter_status', '%' . $value . '%');
                    break;
            }
        }
    }
}
