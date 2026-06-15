<?php

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
 * Decorates sales records for display badges and amount formatting.
 */
final class SalesDataDecorator implements GridDataFactoryInterface
{
    /** @var GridDataFactoryInterface */
    private $inner;

    public function __construct(GridDataFactoryInterface $inner)
    {
        $this->inner = $inner;
    }

    public function getData(SearchCriteriaInterface $searchCriteria)
    {
        $data = $this->inner->getData($searchCriteria);
        $records = [];

        foreach ($data->getRecords() as $record) {
            $status = (string) ($record['status'] ?? 'pending');
            $source = (string) ($record['attribution_source'] ?? 'unknown');
            $currency = strtoupper((string) ($record['currency'] ?? 'EUR'));
            $isSynced = (bool) ($record['hub_synced'] ?? false);
            $syncAttempts = (int) ($record['hub_sync_attempts'] ?? 0);

            $record['amount_display'] = sprintf(
                '%s %s',
                number_format((float) ($record['amount'] ?? 0), 2, '.', ' '),
                $currency
            );
            $record['source_badge'] = sprintf(
                '<span class="badge badge-secondary">%s</span>',
                htmlspecialchars($source, ENT_QUOTES, 'UTF-8')
            );

            $statusClass = 'badge-warning';
            if ($status === 'confirmed' || $status === 'completed') {
                $statusClass = 'badge-success';
            } elseif ($status === 'failed') {
                $statusClass = 'badge-danger';
            }

            $record['status_badge'] = sprintf(
                '<span class="badge %s">%s</span>',
                $statusClass,
                htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            );

            $record['created_at'] = $this->formatDateTime((string) ($record['created_at'] ?? ''));

            if ($isSynced) {
                $record['synced_badge'] = '<span class="badge badge-success">Yes</span>';
            } elseif ($syncAttempts >= 5) {
                $record['synced_badge'] = '<span class="badge badge-danger">Failed</span>';
            } else {
                $record['synced_badge'] = '<span class="badge badge-warning">Pending</span>';
            }

            $records[] = $record;
        }

        return new GridData(new RecordCollection($records), $data->getRecordsTotal(), $data->getQuery());
    }

    private function formatDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($raw);
            return $date->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return $raw;
        }
    }
}
