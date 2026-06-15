<?php

/**
 * Module source file.
 *
 * @author Marques de France
 */

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Shared feed eligibility rules used by XML generation and BO feed grids.
 */
final class FeedEligibilityService
{
    /** @var bool */
    private $allowOrderOutOfStockByDefault;

    /** @var int */
    private $allowOrderOutOfStockByDefaultInt;

    public function __construct($globalOutOfStockDefault)
    {
        $this->allowOrderOutOfStockByDefault = (int) $globalOutOfStockDefault === 1;
        $this->allowOrderOutOfStockByDefaultInt = $this->allowOrderOutOfStockByDefault ? 1 : 0;
    }

    public function getGlobalOutOfStockDefaultAsInt(): int
    {
        return $this->allowOrderOutOfStockByDefaultInt;
    }

    public function isOutOfStockPolicyOrderable(int $outOfStockPolicy): bool
    {
        if ($outOfStockPolicy === 1) {
            return true;
        }

        if ($outOfStockPolicy === 2) {
            return $this->allowOrderOutOfStockByDefault;
        }

        return false;
    }

    public function isEligibleByQuantityAndPolicy(int $quantity, int $outOfStockPolicy): bool
    {
        if ($quantity > 0) {
            return true;
        }

        return $this->isOutOfStockPolicyOrderable($outOfStockPolicy);
    }

    /**
     * @return array{quantity:int, out_of_stock:int}
     */
    public function getProductStockContext(int $productId, int $shopId): array
    {
        $query = new \DbQuery();
        $query->select('COALESCE(sa.quantity, 0) AS quantity, COALESCE(sa.out_of_stock, 2) AS out_of_stock')
              ->from('stock_available', 'sa')
              ->where('sa.id_product = ' . (int) $productId)
              ->where('sa.id_product_attribute = 0')
              ->where('sa.id_shop = ' . (int) $shopId);

        $row = \Db::getInstance()->getRow($query);

        if (!is_array($row)) {
            return ['quantity' => 0, 'out_of_stock' => 2];
        }

        return [
            'quantity' => (int) ($row['quantity'] ?? 0),
            'out_of_stock' => (int) ($row['out_of_stock'] ?? 2),
        ];
    }

    public function buildOutOfStockOrderableExpression(string $outOfStockExpression): string
    {
        return '('
            . $outOfStockExpression . ' = 1'
            . ' OR (' . $outOfStockExpression . ' = 2 AND ' . $this->allowOrderOutOfStockByDefaultInt . ' = 1)'
            . ')';
    }

    public function buildStockEligibilityExpression(string $quantityExpression, string $outOfStockExpression): string
    {
        $orderableExpr = $this->buildOutOfStockOrderableExpression($outOfStockExpression);

        return '(' . $quantityExpression . ' > 0 OR ' . $orderableExpr . ')';
    }
}
