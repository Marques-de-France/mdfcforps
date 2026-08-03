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
    /** Sale accepted by the Hub (newly recorded, or already present). */
    public const SYNC_SYNCED = 'synced';

    /** Sale deterministically refused — retrying can never succeed. */
    public const SYNC_IGNORED = 'ignored';

    /** Transient failure — worth retrying on a later cron run. */
    public const SYNC_FAILED = 'failed';

    private string $hubUrl;
    private string $shopUrl;
    private string $secureToken;
    private string $moduleVersion;
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
        // Resolved once: the fallback path queries ps_module, and every request sends it.
        $this->moduleVersion = $this->resolveModuleVersion();
    }

    // -----------------------------------------------------------------------
    // Self-register
    // -----------------------------------------------------------------------

    /**
     * Module version, resolved without depending on the root \Mdfcforps class.
     *
     * That class lives in mdfcforps.php and is loaded by PrestaShop only in a legacy
     * module context. The module's PSR-4 autoloader maps Mdfcforps\… to src/, so it
     * does not cover the un-namespaced root class — referencing it from here fataled
     * with "Class \"Mdfcforps\" not found" in every non-legacy context (Symfony admin
     * controllers, the front feed controller, cron, CLI), which killed self-registration.
     */
    private function resolveModuleVersion(): string
    {
        if (class_exists('\Mdfcforps') && defined('\Mdfcforps::VERSION')) {
            return (string) \Mdfcforps::VERSION;
        }

        try {
            $version = \Db::getInstance()->getValue(
                'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = "mdfcforps"'
            );

            return $version ? (string) $version : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Convert a locally stored naive timestamp into an unambiguous ISO 8601 string.
     *
     * mdfcforps_sales.created_at holds shop-local wall-clock time with no timezone.
     * Sent as-is, the Hub resolves it with new Date(...) against the Node process's own
     * timezone — right only when the Hub happens to run in the shop's timezone, and two
     * hours out when it runs in UTC, which is the Docker default. The WooCommerce and
     * Shopify connectors sidestep this by sending no timestamp at all and letting the Hub
     * stamp new Date(); we send the offset instead, so the true sale time also survives a
     * sync that only succeeds on a later retry.
     *
     * Returns '' when the value cannot be parsed — the Hub then falls back to its own
     * clock rather than recording a wrong instant.
     */
    private function toIso8601(string $localDateTime): string
    {
        $localDateTime = trim($localDateTime);

        if ($localDateTime === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($localDateTime))->format(\DATE_ATOM);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function selfRegister(): array
    {
        return $this->post('/api/ps/self-register', [
            'siteUrl' => $this->shopUrl,
            'shopUrl' => $this->shopUrl,
            'platform' => 'prestashop',
            'moduleVersion' => $this->moduleVersion,
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
        return $this->syncSaleWithOutcome($sale) === self::SYNC_SYNCED;
    }

    /**
     * Push a sale and report how it landed.
     *
     * Distinguishes a transient failure (retry later) from a deterministic refusal
     * (the Hub does not record unattributed sales, so retrying can never succeed).
     *
     * @param array<string, mixed> $sale
     *
     * @return string one of SYNC_SYNCED, SYNC_IGNORED, SYNC_FAILED
     */
    public function syncSaleWithOutcome(array $sale): string
    {
        try {
            $response = $this->post('/api/ps/sales', [
                'orderId' => $sale['order_id'],
                'orderReference' => $sale['order_reference'],
                'amount' => (float) $sale['amount'],
                'netAmount' => (float) ($sale['net_amount'] ?? 0),
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
                'createdAt' => $this->toIso8601((string) ($sale['created_at'] ?? '')),
            ]);

            // Success means either newly recorded or already present (idempotent).
            if (($response['recorded'] ?? null) === true) {
                return self::SYNC_SYNCED;
            }

            $reason = (string) ($response['reason'] ?? '');

            if ($reason === 'already_exists') {
                return self::SYNC_SYNCED;
            }

            // Deterministic refusal: the Hub does not record unattributed sales, so
            // retrying can never succeed. Terminal — but the caller must not mark the
            // row synced, because a synced row absent from the Hub is precisely what
            // runReconciliation() requeues, which would loop every cron run.
            if ($reason === 'not_attributed') {
                return self::SYNC_IGNORED;
            }

            return self::SYNC_FAILED;
        } catch (\Throwable $e) {
            return self::SYNC_FAILED;
        }
    }

    /**
     * Update sale status on Hub (cancelled / refunded).
     *
     * @param int $orderId the PrestaShop order id (id_order), which is how the Hub
     *                     keys sales — not the local mdfcforps_sales row id
     */
    public function updateSaleStatus(int $orderId, string $status): bool
    {
        try {
            $response = $this->post("/api/ps/sales/{$orderId}/status", [
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
            // If recovery itself fails, surface the ORIGINAL error — it describes why the
            // status call failed, whereas the recovery error is a second-order symptom.
            try {
                $registerResult = $this->selfRegister();
            } catch (\Throwable $registerError) {
                throw $firstError;
            }

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
        string $dateTo = ''
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

        if ($this->moduleVersion !== '') {
            $headers[] = 'X-Plugin-Version: ' . $this->moduleVersion;
        }

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

        if ($this->moduleVersion !== '') {
            $headers[] = 'X-Plugin-Version: ' . $this->moduleVersion;
        }

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
