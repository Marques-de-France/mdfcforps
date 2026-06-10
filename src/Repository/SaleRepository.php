<?php

declare(strict_types=1);

namespace Mdfcforps\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SaleRepository
{
    private const TABLE = _DB_PREFIX_ . 'mdfcforps_sales';

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Insert a new sale record.
     *
     * @param array<string, string> $attribution
     */
    public function recordSale(\Order $order, array $attribution): bool
    {
        $currency = \Currency::getIsoCodeById((int) $order->id_currency);

        $data = [
            'order_id'          => (int) $order->id,
            'order_reference'   => pSQL($order->reference),
            'amount'            => (float) $order->total_paid_tax_incl,
            'currency'          => pSQL($currency ?: 'EUR'),
            'attribution_source'=> pSQL($attribution['source'] ?? 'unknown'),
            'utm_source'        => pSQL($attribution['utm_source'] ?? ''),
            'utm_medium'        => pSQL($attribution['utm_medium'] ?? ''),
            'utm_campaign'      => pSQL($attribution['utm_campaign'] ?? ''),
            'utm_content'       => pSQL($attribution['utm_content'] ?? ''),
            'utm_term'          => pSQL($attribution['utm_term'] ?? ''),
            'landing_site'      => pSQL($attribution['landing_site'] ?? '', true),
            'referring_site'    => pSQL($attribution['referring_site'] ?? '', true),
            'landing_ref'       => pSQL($attribution['landing_ref'] ?? '', true),
            'click_id'          => pSQL($attribution['click_id'] ?? ''),
            'status'            => 'confirmed',
            'hub_synced'        => 0,
            'hub_sync_attempts' => 0,
            'created_at'        => date('Y-m-d H:i:s'),
        ];

        return (bool) \Db::getInstance()->insert(
            'mdfcforps_sales',
            $data,
            false,
            true,
            \Db::INSERT_IGNORE
        );
    }

    public function updateStatus(int $id, string $status): bool
    {
        return (bool) \Db::getInstance()->update(
            'mdfcforps_sales',
            ['status' => pSQL($status)],
            'id = ' . (int) $id
        );
    }

    public function markSynced(int $id): bool
    {
        return (bool) \Db::getInstance()->update(
            'mdfcforps_sales',
            ['hub_synced' => 1],
            'id = ' . (int) $id
        );
    }

    public function incrementSyncAttempts(int $id): bool
    {
        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'mdfcforps_sales`
             SET `hub_sync_attempts` = `hub_sync_attempts` + 1
             WHERE `id` = ' . (int) $id
        );
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId): ?array
    {
        $query = new \DbQuery();
        $query->select('*')
              ->from('mdfcforps_sales')
              ->where('order_id = ' . (int) $orderId)
              ->limit(1);

        $row = \Db::getInstance()->getRow($query);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $query = new \DbQuery();
        $query->select('*')
              ->from('mdfcforps_sales')
              ->where('id = ' . (int) $id)
              ->limit(1);

        $row = \Db::getInstance()->getRow($query);

        return $row ?: null;
    }

    /**
     * Fetch rows pending Hub sync (dead-letter threshold = 5 attempts).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingSync(int $limit = 50): array
    {
        $query = new \DbQuery();
        $query->select('*')
              ->from('mdfcforps_sales')
              ->where('hub_synced = 0')
              ->where('hub_sync_attempts < 5')
              ->orderBy('created_at ASC')
              ->limit($limit);

        $result = \Db::getInstance()->executeS($query);

        return is_array($result) ? $result : [];
    }

    /**
     * Return all sales (for backfill comparison).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $query = new \DbQuery();
        $query->select('*')
              ->from('mdfcforps_sales')
              ->orderBy('created_at ASC');

        $result = \Db::getInstance()->executeS($query);

        return is_array($result) ? $result : [];
    }

    /**
     * Paginated list for BO Sales tab.
     *
     * @return array{sales: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;

        $countQuery = new \DbQuery();
        $countQuery->select('COUNT(*)')
                   ->from('mdfcforps_sales');

        $total = (int) \Db::getInstance()->getValue($countQuery);

        $query = new \DbQuery();
        $query->select('*')
              ->from('mdfcforps_sales')
              ->orderBy('created_at DESC')
              ->limit($perPage, $offset);

        $sales = \Db::getInstance()->executeS($query);

        return [
            'sales' => is_array($sales) ? $sales : [],
            'total' => $total,
        ];
    }
}
