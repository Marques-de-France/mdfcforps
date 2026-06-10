<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/Install/Installer.php';
require_once __DIR__ . '/src/Service/HubClient.php';
require_once __DIR__ . '/src/Repository/SaleRepository.php';
require_once __DIR__ . '/src/Service/AttributionService.php';
require_once __DIR__ . '/src/Service/FeedService.php';
require_once __DIR__ . '/src/Service/FeedProductsService.php';

class Mdfcforps extends Module
{
    public const VERSION = '1.0.0';
    public const DB_VERSION = '1.0.0';

    public function __construct()
    {
        $this->name = 'mdfcforps';
        $this->tab = 'market_place';
        $this->version = self::VERSION;
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

        $installer = new \Mdfcforps\Install\Installer($this);

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
        $installer = new \Mdfcforps\Install\Installer($this);
        $installer->uninstall();

        return parent::uninstall();
    }

    // -----------------------------------------------------------------------
    // Back-office entry point — redirect to Dashboard tab
    // -----------------------------------------------------------------------

    public function getContent(): string
    {
        Tools::redirectAdmin(
            Context::getContext()->link->getAdminLink('AdminMdfcforps')
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

        $attributionService = new \Mdfcforps\Service\AttributionService();
        $attributionData = $attributionService->collectFromCookies();

        $saleRepo = new \Mdfcforps\Repository\SaleRepository();
        $saleRepo->recordSale($order, $attributionData);
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
            $refundedState  => 'refunded',
            default         => null,
        };

        if ($newStatus === null) {
            return;
        }

        $saleRepo = new \Mdfcforps\Repository\SaleRepository();
        $sale = $saleRepo->findByOrderId($orderId);

        if (!$sale) {
            return;
        }

        $saleRepo->updateStatus((int) $sale['id'], $newStatus);

        $hubClient = new \Mdfcforps\Service\HubClient();
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

        // Lazy-cron: only fire in BO when an employee is logged in
        if (
            $this->context->employee instanceof Employee
            && $this->context->employee->isLoggedBack()
        ) {
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
            'mdfcforps_ajax_url' => Context::getContext()->link->getModuleLink(
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
        $lastFlush = (int) Configuration::get('MDFCFORPS_LAST_FLUSH');
        if ((time() - $lastFlush) < 3600) {
            return;
        }

        Configuration::updateValue('MDFCFORPS_LAST_FLUSH', time());

        $saleRepo = new \Mdfcforps\Repository\SaleRepository();
        $hubClient = new \Mdfcforps\Service\HubClient();

        $pending = $saleRepo->getPendingSync(50);

        foreach ($pending as $sale) {
            try {
                $result = $hubClient->syncSale($sale);
                if ($result) {
                    $saleRepo->markSynced((int) $sale['id']);
                } else {
                    $saleRepo->incrementSyncAttempts((int) $sale['id']);
                }
            } catch (\Throwable $e) {
                $saleRepo->incrementSyncAttempts((int) $sale['id']);
            }
        }

        // Backfill: push historic orders on first run only
        if (!Configuration::get('MDFCFORPS_BACKFILL_DONE')) {
            $this->runBackfill($saleRepo, $hubClient);
        }
    }

    private function runBackfill(
        \Mdfcforps\Repository\SaleRepository $saleRepo,
        \Mdfcforps\Service\HubClient $hubClient
    ): void {
        try {
            $hubSales = $hubClient->getHubSales();
            $syncedOrderRefs = array_column($hubSales, 'orderReference');

            $localSales = $saleRepo->findAll();

            foreach ($localSales as $sale) {
                if (!in_array($sale['order_reference'], $syncedOrderRefs, true)) {
                    $hubClient->syncSale($sale);
                }
            }

            Configuration::updateValue('MDFCFORPS_BACKFILL_DONE', 1);
        } catch (\Throwable $e) {
            // Silently fail — will retry next cron cycle
        }
    }
}
