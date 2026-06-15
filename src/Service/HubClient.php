<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * HTTP client for all communication with the MDF Connectors Hub.
 *
 * Hub URL resolution order:
 *  1. MDF_HUB_URL environment variable (local/Docker dev)
 *  2. Hardcoded production URL: https://flux.marques-de-france.fr
 */
class HubClient
{
    private string $hubUrl;
    private string $shopUrl;
    private string $secureToken;
    private int $timeout = 5;

    public function __construct()
    {
        $this->hubUrl = rtrim(
            (string) (getenv('MDF_HUB_URL') ?: 'https://flux.marques-de-france.fr'),
            '/'
        );

        $this->shopUrl = (
            (bool) \Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http'
        ) . '://' . \Configuration::get('PS_SHOP_DOMAIN');

        $this->secureToken = ModuleConfig::get('MDFCFORPS_SECURE_TOKEN', '');
    }

    // -----------------------------------------------------------------------
    // Self-register
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function selfRegister(): array
    {
        return $this->post('/api/ps/self-register', [
            'siteUrl' => $this->shopUrl,
            'shopUrl' => $this->shopUrl,
            'platform' => 'prestashop',
            'moduleVersion' => \Mdfcforps::VERSION,
        ], false);
    }

    // -----------------------------------------------------------------------
    // Sales sync
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $sale
     */
    public function syncSale(array $sale): bool
    {
        try {
            $response = $this->post('/api/ps/sales', [
                'orderId' => $sale['order_id'],
                'orderReference' => $sale['order_reference'],
                'amount' => (float) $sale['amount'],
                'currency' => $sale['currency'],
                'attributionSource' => $sale['attribution_source'],
                'utmSource' => $sale['utm_source'],
                'utmMedium' => $sale['utm_medium'],
                'utmCampaign' => $sale['utm_campaign'],
                'utmContent' => $sale['utm_content'],
                'utmTerm' => $sale['utm_term'],
                'landingSite' => $sale['landing_site'],
                'referringSite' => $sale['referring_site'],
                'landingRef' => $sale['landing_ref'],
                'clickId' => $sale['click_id'],
                'status' => $sale['status'],
                'createdAt' => $sale['created_at'],
            ]);

            // Success means either newly recorded or already present (idempotent).
            if (($response['recorded'] ?? null) === true) {
                return true;
            }

            if (($response['reason'] ?? '') === 'already_exists') {
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Update sale status on Hub (cancelled / refunded).
     */
    public function updateSaleStatus(int $saleId, string $status): bool
    {
        try {
            $response = $this->post("/api/ps/sales/{$saleId}/status", [
                'status' => $status,
            ]);

            return ($response['updated'] ?? null) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Analytics & status
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        try {
            return $this->get('/api/ps/status');
        } catch (\Throwable $firstError) {
            // Auto-recover common hosted cases (missing/stale token or unknown store row):
            // re-run self-registration, persist returned token, then retry once.
            $registerResult = $this->selfRegister();
            if (!empty($registerResult['secureToken'])) {
                $this->secureToken = (string) $registerResult['secureToken'];
                ModuleConfig::update('MDFCFORPS_SECURE_TOKEN', $this->secureToken);
            }

            return $this->get('/api/ps/status');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(string $dateFrom = '', string $dateTo = '', string $granularity = 'day'): array
    {
        $query = [];

        if ($dateFrom !== '') {
            $query['dateFrom'] = $dateFrom;
        }

        if ($dateTo !== '') {
            $query['dateTo'] = $dateTo;
        }

        if ($granularity !== '') {
            $query['granularity'] = $granularity;
        }

        $path = '/api/ps/analytics';
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        return $this->get($path);
    }

    /**
     * Fetch all sales stored on Hub (used for backfill check).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHubSales(): array
    {
        $response = $this->getHubSalesPage();

        return $response['sales'] ?? [];
    }

    /**
     * Fetch a page of Hub sales for this store.
     *
     * @return array<string, mixed>
     */
    public function getHubSalesPage(
        int $page = 1,
        int $limit = 100,
        string $dateFrom = '',
        string $dateTo = '',
    ): array {
        return $this->getHubSalesList($page, $limit, [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    /**
     * Fetch a page of Hub sales with optional filters/sorting.
     *
     * @param array<string, string|int|float> $filters
     *
     * @return array<string, mixed>
     */
    public function getHubSalesList(int $page = 1, int $limit = 25, array $filters = []): array
    {
        $query = [
            'page' => max(1, $page),
            'limit' => min(100, max(1, $limit)),
        ];

        $allowed = [
            'search',
            'status',
            'source',
            'dateFrom',
            'dateTo',
            'amountMin',
            'amountMax',
            'sortField',
            'sortDir',
        ];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }

            $value = $filters[$key];
            if ((string) $value === '') {
                continue;
            }

            $query[$key] = (string) $value;
        }

        return $this->get('/api/ps/sales?' . http_build_query($query));
    }

    // -----------------------------------------------------------------------
    // Low-level HTTP helpers (native PHP streams — no extra dependencies)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, bool $requireToken = true): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-MDF-Shop: ' . $this->shopUrl,
        ];

        if ($requireToken && $this->secureToken !== '') {
            $headers[] = 'X-MDF-Token: ' . $this->secureToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $url = $this->hubUrl . $path;
        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            throw new \RuntimeException("Hub POST {$path} — network error");
        }

        $responseHeaders = $http_response_header;
        $statusCode = $this->extractStatusCode($responseHeaders);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("Hub POST {$path} — HTTP {$statusCode}");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $headers = [
            'Accept: application/json',
            'X-MDF-Shop: ' . $this->shopUrl,
        ];

        if ($this->secureToken !== '') {
            $headers[] = 'X-MDF-Token: ' . $this->secureToken;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $url = $this->hubUrl . $path;
        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            throw new \RuntimeException("Hub GET {$path} — network error");
        }

        $responseHeaders = $http_response_header;
        $statusCode = $this->extractStatusCode($responseHeaders);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("Hub GET {$path} — HTTP {$statusCode}");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, string> $headers
     */
    private function extractStatusCode(array $headers): int
    {
        if (empty($headers[0])) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
