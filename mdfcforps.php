<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// PSR-4 autoloading for Symfony-style classes (Grid, Controller, Form types, etc.)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/src/Install/Installer.php';
require_once __DIR__ . '/src/Service/HubClient.php';
require_once __DIR__ . '/src/Repository/SaleRepository.php';
require_once __DIR__ . '/src/Service/AttributionService.php';
require_once __DIR__ . '/src/Service/FeedService.php';
require_once __DIR__ . '/src/Service/FeedProductsService.php';
require_once __DIR__ . '/src/Service/ModuleConfig.php';

class Mdfcforps extends Module
{
    public const VERSION = '1.0.0';
    public const DB_VERSION = '1.0.0';

    private const LAZY_INTERVAL_SEC = 3600;
    private const RECONCILE_WINDOW_DAYS = 7;
    private const RECONCILE_LOCAL_LIMIT = 300;
    private const HUB_PAGE_LIMIT = 100;
    private const HUB_MAX_PAGES = 20;

    public function __construct()
    {
        $this->name = 'mdfcforps';
        $this->tab = 'market_place';
        $this->version = '1.0.0';
        $this->author = 'Marques de France';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Marques de France', [], 'Modules.Mdfcforps.Admin');
        $this->description = $this->trans(
            'Connect your PrestaShop store to the Marques de France platform.',
            [],
            'Modules.Mdfcforps.Admin'
        );
    }

    // -----------------------------------------------------------------------
    // Install / Uninstall
    // -----------------------------------------------------------------------

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $installer = new Mdfcforps\Install\Installer($this);

        if (!$installer->install()) {
            return false;
        }

        $hooks = [
            'actionValidateOrder',
            'actionOrderStatusUpdate',
            'displayHeader',
            'displayBeforeBodyClosingTag',
        ];

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        // Attempt self-registration immediately after install
        $installer->selfRegister();

        return true;
    }

    public function uninstall(): bool
    {
        $installer = new Mdfcforps\Install\Installer($this);
        $installer->uninstall();

        return parent::uninstall();
    }

    // -----------------------------------------------------------------------
    // Back-office entry point — redirect to Dashboard tab
    // -----------------------------------------------------------------------

    public function getContent(): string
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminMdfcforps')
        );

        return '';
    }

    // -----------------------------------------------------------------------
    // Hook: validate order → record sale
    // -----------------------------------------------------------------------

    public function hookActionValidateOrder(array $params): void
    {
        /** @var Order $order */
        $order = $params['order'] ?? null;
        if (!$order instanceof Order) {
            return;
        }

        $attributionService = new Mdfcforps\Service\AttributionService();
        $attributionData = $attributionService->collectFromCookies();

        $saleRepo = new Mdfcforps\Repository\SaleRepository();
        $inserted = $saleRepo->recordSale($order, $attributionData);

        // Immediate sync for freshly created rows.
        // If Hub is unavailable, keep local row pending for lazy-cron retries.
        if (!$inserted) {
            return;
        }

        $sale = null;
        $insertedId = (int) Db::getInstance()->Insert_ID();
        if ($insertedId > 0) {
            $sale = $saleRepo->findById($insertedId);
        }

        if (!$sale) {
            $sale = $saleRepo->findByOrderId((int) $order->id);
        }

        if (!$sale) {
            PrestaShopLogger::addLog(
                '[MDF] Immediate sync skipped: unable to resolve inserted sale row for ' . $this->maskEntityId('order', (int) $order->id),
                2,
                null,
                'Mdfcforps'
            );

            return;
        }

        try {
            $hubClient = new Mdfcforps\Service\HubClient();
            $result = $hubClient->syncSale($sale);
            if ($result) {
                $marked = $saleRepo->markSynced((int) $sale['id']);
                if (!$marked) {
                    $saleRepo->incrementSyncAttempts((int) $sale['id']);
                    PrestaShopLogger::addLog(
                        '[MDF] Immediate sync reached Hub but local markSynced failed for ' . $this->maskEntityId('sale', (int) $sale['id']),
                        2,
                        null,
                        'Mdfcforps'
                    );
                }
            } else {
                $saleRepo->incrementSyncAttempts((int) $sale['id']);
            }
        } catch (Throwable $e) {
            $saleRepo->incrementSyncAttempts((int) $sale['id']);
            PrestaShopLogger::addLog(
                '[MDF] Immediate sync error for ' . $this->maskEntityId('sale', (int) $sale['id']) . ': ' . $this->sanitizeLogMessage($e->getMessage()),
                3,
                null,
                'Mdfcforps'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Hook: order status change → sync status to Hub
    // -----------------------------------------------------------------------

    public function hookActionOrderStatusUpdate(array $params): void
    {
        /** @var OrderState $newOrderStatus */
        $newOrderStatus = $params['newOrderStatus'] ?? null;
        $orderId = (int) ($params['id_order'] ?? 0);

        if (!$newOrderStatus instanceof OrderState || $orderId === 0) {
            return;
        }

        $cancelledState = (int) Configuration::get('PS_OS_CANCELED');
        $refundedState = (int) Configuration::get('PS_OS_REFUND');

        $newStatus = match ((int) $newOrderStatus->id) {
            $cancelledState => 'cancelled',
            $refundedState => 'refunded',
            default => null,
        };

        if ($newStatus === null) {
            return;
        }

        $saleRepo = new Mdfcforps\Repository\SaleRepository();
        $sale = $saleRepo->findByOrderId($orderId);

        if (!$sale) {
            return;
        }

        $saleRepo->updateStatus((int) $sale['id'], $newStatus);

        $hubClient = new Mdfcforps\Service\HubClient();
        $hubClient->updateSaleStatus((int) $sale['id'], $newStatus);
    }

    // -----------------------------------------------------------------------
    // Hook: displayHeader → lazy-cron flush + JS runtime injection
    // -----------------------------------------------------------------------

    public function hookDisplayHeader(array $params): string
    {
        // Inject front tracker assets on all non-admin pages
        if (!$this->context->controller instanceof AdminController) {
            $this->context->controller->addJS($this->_path . 'views/js/front/mdf-attribution-context-ps.js');
        }

        // Lazy tasks run with strict throttle on BO and FO page loads.
        $isBoEmployee = $this->context->employee instanceof Employee
            && $this->context->employee->isLoggedBack();
        $isFront = !$this->context->controller instanceof AdminController;

        if ($isBoEmployee || $isFront) {
            $this->runLazyCron();
        }

        return '';
    }

    // -----------------------------------------------------------------------
    // Hook: displayBeforeBodyClosingTag → tracker snippet (front only)
    // -----------------------------------------------------------------------

    public function hookDisplayBeforeBodyClosingTag(array $params): string
    {
        if ($this->context->controller instanceof AdminController) {
            return '';
        }

        $this->context->smarty->assign([
            'mdfcforps_ajax_url' => $this->context->link->getModuleLink(
                $this->name,
                'ajax',
                [],
                (bool) Configuration::get('PS_SSL_ENABLED')
            ),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/tracker.tpl');
    }

    // -----------------------------------------------------------------------
    // Lazy-cron: flush un-synced sales to Hub (max 50 per run)
    // -----------------------------------------------------------------------

    private function runLazyCron(): void
    {
        $lastFlush = Mdfcforps\Service\ModuleConfig::getInt('MDFCFORPS_LAST_FLUSH', 0);
        if ((time() - $lastFlush) < self::LAZY_INTERVAL_SEC) {
            return;
        }

        Mdfcforps\Service\ModuleConfig::update('MDFCFORPS_LAST_FLUSH', time());

        $saleRepo = new Mdfcforps\Repository\SaleRepository();
        $hubClient = new Mdfcforps\Service\HubClient();

        $this->runReconciliation($saleRepo, $hubClient);

        $pending = $saleRepo->getPendingSync(50);

        foreach ($pending as $sale) {
            try {
                $result = $hubClient->syncSale($sale);
                if ($result) {
                    $saleRepo->markSynced((int) $sale['id']);
                } else {
                    $saleRepo->incrementSyncAttempts((int) $sale['id']);
                }
            } catch (Throwable $e) {
                $saleRepo->incrementSyncAttempts((int) $sale['id']);
            }
        }

        // Backfill: push historic orders on first run only
        if (Mdfcforps\Service\ModuleConfig::getInt('MDFCFORPS_BACKFILL_DONE', 0) !== 1) {
            $this->runBackfill($saleRepo, $hubClient);
        }
    }

    private function runBackfill(
        Mdfcforps\Repository\SaleRepository $saleRepo,
        Mdfcforps\Service\HubClient $hubClient,
    ): void {
        try {
            $hubSalesByOrderId = $this->fetchHubSalesByOrderId($hubClient, '', '');

            $localSales = $saleRepo->findAll();

            foreach ($localSales as $sale) {
                $orderId = (string) ($sale['order_id'] ?? '');
                if ($orderId === '') {
                    continue;
                }

                if (!isset($hubSalesByOrderId[$orderId])) {
                    if ($hubClient->syncSale($sale)) {
                        $saleRepo->markSynced((int) $sale['id']);
                    }
                }
            }

            Mdfcforps\Service\ModuleConfig::update('MDFCFORPS_BACKFILL_DONE', 1);
        } catch (Throwable $e) {
            // Silently fail — will retry next cron cycle
        }
    }

    private function runReconciliation(
        Mdfcforps\Repository\SaleRepository $saleRepo,
        Mdfcforps\Service\HubClient $hubClient,
    ): void {
        try {
            $localSales = $saleRepo->findRecent(self::RECONCILE_WINDOW_DAYS, self::RECONCILE_LOCAL_LIMIT);
            if (empty($localSales)) {
                return;
            }

            $dateFrom = date('Y-m-d', time() - (self::RECONCILE_WINDOW_DAYS * 86400));
            $dateTo = date('Y-m-d');
            $hubSalesByOrderId = $this->fetchHubSalesByOrderId($hubClient, $dateFrom, $dateTo);

            $requeued = 0;
            $fixedSynced = 0;
            $restoredLocal = 0;
            $statusAligned = 0;
            $localOrderIdSet = [];

            foreach ($localSales as $sale) {
                $orderId = (string) ($sale['order_id'] ?? '');
                if ($orderId !== '') {
                    $localOrderIdSet[$orderId] = true;
                }
            }

            // Hub is source of truth: restore missing local rows found remotely.
            foreach ($hubSalesByOrderId as $orderId => $hubSale) {
                if (isset($localOrderIdSet[$orderId])) {
                    continue;
                }

                if ($saleRepo->upsertFromHubSale($hubSale)) {
                    ++$restoredLocal;
                }
            }

            foreach ($localSales as $sale) {
                $orderId = (string) ($sale['order_id'] ?? '');
                if ($orderId === '') {
                    continue;
                }

                $hubSale = $hubSalesByOrderId[$orderId] ?? null;
                $isHubPresent = is_array($hubSale);
                $isLocalSynced = (int) ($sale['hub_synced'] ?? 0) === 1;

                // Missing remotely: never delete Hub rows (Hub is immutable source of truth).
                // Requeue local row to upsert back to Hub through the normal sync path.
                if ($isLocalSynced && !$isHubPresent) {
                    if ($saleRepo->markPending((int) $sale['id'])) {
                        ++$requeued;
                    }
                    continue;
                }

                // Late local consistency: mark synced when already present remotely.
                if (!$isLocalSynced && $isHubPresent) {
                    if ($saleRepo->markSynced((int) $sale['id'])) {
                        ++$fixedSynced;
                    }
                }

                // Hub-authoritative status parity.
                if ($isHubPresent) {
                    $remoteStatus = strtolower(trim((string) ($hubSale['status'] ?? '')));
                    $localStatus = strtolower(trim((string) ($sale['status'] ?? '')));

                    if ($remoteStatus !== '' && $remoteStatus !== $localStatus) {
                        if ($saleRepo->updateStatus((int) $sale['id'], $remoteStatus)) {
                            ++$statusAligned;
                        }
                    }
                }
            }

            if ($requeued > 0 || $fixedSynced > 0 || $restoredLocal > 0 || $statusAligned > 0) {
                PrestaShopLogger::addLog(
                    '[MDF] Reconciliation done: requeued=' . $requeued
                    . ', fixedSynced=' . $fixedSynced
                    . ', restoredLocal=' . $restoredLocal
                    . ', statusAligned=' . $statusAligned,
                    1,
                    null,
                    'Mdfcforps'
                );
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                '[MDF] Reconciliation error: ' . $this->sanitizeLogMessage($e->getMessage()),
                2,
                null,
                'Mdfcforps'
            );
        }
    }

    private function maskEntityId(string $entity, int $id): string
    {
        if ($id <= 0) {
            return $entity . '_unknown';
        }

        return $entity . '_' . substr(hash('sha256', (string) $id), 0, 10);
    }

    private function sanitizeLogMessage(string $message): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        if ($normalized === '') {
            return 'unexpected error';
        }

        return substr($normalized, 0, 180);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchHubSalesByOrderId(
        Mdfcforps\Service\HubClient $hubClient,
        string $dateFrom,
        string $dateTo,
    ): array {
        $map = [];

        for ($page = 1; $page <= self::HUB_MAX_PAGES; ++$page) {
            $response = $hubClient->getHubSalesPage(
                $page,
                self::HUB_PAGE_LIMIT,
                $dateFrom,
                $dateTo
            );

            $sales = $response['sales'] ?? [];
            if (!is_array($sales) || empty($sales)) {
                break;
            }

            foreach ($sales as $hubSale) {
                $orderId = (string) ($hubSale['orderId'] ?? '');
                if ($orderId !== '') {
                    $map[$orderId] = $hubSale;
                }
            }

            $total = (int) ($response['total'] ?? 0);
            $limit = (int) ($response['limit'] ?? self::HUB_PAGE_LIMIT);
            if ($total > 0 && ($page * $limit) >= $total) {
                break;
            }
        }

        return $map;
    }
}
