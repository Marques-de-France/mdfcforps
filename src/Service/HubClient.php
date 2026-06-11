<?php

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

        $this->secureToken = (string) \Configuration::get('MDFCFORPS_SECURE_TOKEN');
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
            'siteUrl'    => $this->shopUrl,
            'shopUrl'    => $this->shopUrl,
            'platform'   => 'prestashop',
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
                'orderId'          => $sale['order_id'],
                'orderReference'   => $sale['order_reference'],
                'amount'           => (float) $sale['amount'],
                'currency'         => $sale['currency'],
                'attributionSource'=> $sale['attribution_source'],
                'utmSource'        => $sale['utm_source'],
                'utmMedium'        => $sale['utm_medium'],
                'utmCampaign'      => $sale['utm_campaign'],
                'utmContent'       => $sale['utm_content'],
                'utmTerm'          => $sale['utm_term'],
                'landingSite'      => $sale['landing_site'],
                'referringSite'    => $sale['referring_site'],
                'landingRef'       => $sale['landing_ref'],
                'clickId'          => $sale['click_id'],
                'status'           => $sale['status'],
                'createdAt'        => $sale['created_at'],
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
        return $this->get('/api/ps/status');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        return $this->get('/api/ps/analytics');
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
        string $dateTo = ''
    ): array {
        $query = [
            'page'  => max(1, $page),
            'limit' => min(100, max(1, $limit)),
        ];

        if ($dateFrom !== '') {
            $query['dateFrom'] = $dateFrom;
        }

        if ($dateTo !== '') {
            $query['dateTo'] = $dateTo;
        }

        $response = $this->get('/api/ps/sales?' . http_build_query($query));

        return is_array($response) ? $response : [];
    }

    // -----------------------------------------------------------------------
    // Low-level HTTP helpers (native PHP streams — no extra dependencies)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
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
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $this->timeout,
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

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
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
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $this->timeout,
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

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
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
