<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Back-office controller for the Marques de France module.
 *
 * Three tabs: Dashboard | Sales | Product Feed
 *
 * URL:  /admin/index.php?controller=AdminMdfcforps&tab=dashboard (etc.)
 */
class AdminMdfcforpsController extends ModuleAdminController
{
    /** @var string[] */
    private const TABS = ['dashboard', 'feed', 'sales'];

    public function __construct()
    {
        $this->bootstrap   = true;
        $this->module      = Module::getInstanceByName('mdfcforps');
        $this->display     = 'view';
        $this->meta_title  = 'Marques de France';

        parent::__construct();
    }

    // -----------------------------------------------------------------------
    // initContent — dispatch to the correct tab
    // -----------------------------------------------------------------------

    public function initContent(): void
    {
        parent::initContent();

        $tab = Tools::getValue('tab', 'dashboard');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'dashboard';
        }

        // Legacy controller now acts as an entrypoint shim to explicit Symfony tab routes.
        $router = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('router');
        if ($tab === 'feed') {
            Tools::redirectAdmin($router->generate('mdfcforps_feed_index'));

            return;
        }

        if ($tab === 'sales') {
            $page = max(1, (int) Tools::getValue('sales_page', 1));
            Tools::redirectAdmin($router->generate('mdfcforps_sales_index', ['sales_page' => $page]));

            return;
        }

        Tools::redirectAdmin($router->generate('mdfcforps_dashboard_index'));

        return;
    }

    // -----------------------------------------------------------------------
    // Tab: Dashboard
    // -----------------------------------------------------------------------

    private function renderDashboardTab(): void
    {
        $hubClient = new \Mdfcforps\Service\HubClient();

        $analytics = [];
        $status    = [];
        $error     = null;

        try {
            $analytics = $hubClient->getAnalytics();
            $status    = $hubClient->getStatus();
        } catch (\Throwable $e) {
            $error = $this->trans('Unable to reach the Marques de France platform.', [], 'Modules.Mdfcforps.Admin');
        }

        $this->context->smarty->assign([
            'mdf_tab'       => 'dashboard',
            'mdf_analytics' => $analytics,
            'mdf_status'    => $status,
            'mdf_error'     => $error,
            'mdf_admin_url' => $this->context->link->getAdminLink('AdminMdfcforps'),
            'mdf_module_uri'=> $this->module->getPathUri(),
        ]);

        $this->setTemplate('dashboard.tpl');
    }

    // -----------------------------------------------------------------------
    // Tab: Sales
    // -----------------------------------------------------------------------

    private function renderSalesTab(): void
    {
        // Handle POST: update feed mode from feed tab (or future settings)
        if (Tools::isSubmit('mdf_update_feed_mode')) {
            $this->handleFeedModeUpdate();
        }

        $saleRepo = new \Mdfcforps\Repository\SaleRepository();

        $page    = max(1, (int) Tools::getValue('sales_page', 1));
        $result  = $saleRepo->paginate($page, 25);

        $this->context->smarty->assign([
            'mdf_tab'        => 'sales',
            'mdf_sales'      => $result['sales'],
            'mdf_total'      => $result['total'],
            'mdf_page'       => $page,
            'mdf_per_page'   => 25,
            'mdf_admin_url'  => $this->context->link->getAdminLink('AdminMdfcforps'),
        ]);

        $this->setTemplate('sales.tpl');
    }

    // -----------------------------------------------------------------------
    // Tab: Product Feed
    // -----------------------------------------------------------------------

    private function renderFeedTab(): void
    {
        // Handle AJAX actions
        if (Tools::isSubmit('mdf_add_product')) {
            $this->handleAddProduct();
            return;
        }

        if (Tools::isSubmit('mdf_remove_product')) {
            $this->handleRemoveProduct();
            return;
        }

        if (Tools::isSubmit('mdf_search_products')) {
            $this->handleSearchProducts();
            return;
        }

        if (Tools::isSubmit('mdf_bulk_add_products')) {
            $this->handleBulkAddProducts();
            return;
        }

        if (Tools::isSubmit('mdf_bulk_remove_products')) {
            $this->handleBulkRemoveProducts();
            return;
        }

        if (Tools::isSubmit('mdf_update_feed_mode')) {
            $this->handleFeedModeUpdate();
        }

        $idLang    = (int) $this->context->language->id;
        $page      = max(1, (int) Tools::getValue('feed_page', 1));
        $result    = \Mdfcforps\Service\FeedProductsService::paginateForBo($idLang, $page, 25);
        $feedMode  = (string) Configuration::get('MDFCFORPS_FEED_FILTER_MODE');

        // Hub feed URL (internal: Hub proxies this to serve merchants)
        $hubUrl = rtrim(
            (string) (getenv('MDF_HUB_URL') ?: 'https://flux.marques-de-france.fr'),
            '/'
        );
        $shopUrl   = ((bool) Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http')
                     . '://' . Configuration::get('PS_SHOP_DOMAIN');
        $feedUrl   = $hubUrl . '/feed/xml/' . rawurlencode($shopUrl);

        $this->context->smarty->assign([
            'mdf_tab'           => 'feed',
            'mdf_feed_products' => $result['products'],
            'mdf_feed_total'    => $result['total'],
            'mdf_feed_page'     => $page,
            'mdf_feed_mode'     => $feedMode,
            'mdf_feed_url'      => $feedUrl,
            'mdf_admin_url'     => $this->context->link->getAdminLink('AdminMdfcforps'),
        ]);

        $this->setTemplate('feed.tpl');
    }

    // -----------------------------------------------------------------------
    // POST handlers
    // -----------------------------------------------------------------------

    private function handleFeedModeUpdate(): void
    {
        $mode = Tools::getValue('feed_mode') === 'SERVERLIST' ? 'SERVERLIST' : 'TAG';
        Configuration::updateValue('MDFCFORPS_FEED_FILTER_MODE', $mode);
        $this->confirmations[] = $this->trans('Feed mode updated.', [], 'Modules.Mdfcforps.Admin');
    }

    private function handleAddProduct(): void
    {
        header('Content-Type: application/json');

        $productId = (int) Tools::getValue('product_id');

        if ($productId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
            exit;
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            exit;
        }

        \Mdfcforps\Service\FeedProductsService::addProduct($productId);
        echo json_encode(['success' => true]);
        exit;
    }

    private function handleRemoveProduct(): void
    {
        header('Content-Type: application/json');

        $productId = (int) Tools::getValue('product_id');

        if ($productId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
            exit;
        }

        \Mdfcforps\Service\FeedProductsService::removeProduct($productId);
        echo json_encode(['success' => true]);
        exit;
    }

    private function handleSearchProducts(): void
    {
        header('Content-Type: application/json');

        $search  = (string) Tools::getValue('search', '');
        $page    = max(1, (int) Tools::getValue('page', 1));
        $perPage = min(50, max(1, (int) Tools::getValue('per_page', 20)));
        $idLang  = (int) $this->context->language->id;

        $result = \Mdfcforps\Service\FeedProductsService::searchProducts(
            $search,
            $idLang,
            $page,
            $perPage
        );

        echo json_encode($result);
        exit;
    }

    private function handleBulkAddProducts(): void
    {
        header('Content-Type: application/json');

        $ids = Tools::getValue('product_ids');
        if (!is_array($ids)) {
            echo json_encode(['success' => false, 'error' => 'product_ids must be an array']);
            exit;
        }

        foreach ($ids as $id) {
            $productId = (int) $id;
            if ($productId > 0) {
                \Mdfcforps\Service\FeedProductsService::addProduct($productId);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    private function handleBulkRemoveProducts(): void
    {
        header('Content-Type: application/json');

        $ids = Tools::getValue('product_ids');
        if (!is_array($ids)) {
            echo json_encode(['success' => false, 'error' => 'product_ids must be an array']);
            exit;
        }

        foreach ($ids as $id) {
            $productId = (int) $id;
            if ($productId > 0) {
                \Mdfcforps\Service\FeedProductsService::removeProduct($productId);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // -----------------------------------------------------------------------
    // Breadcrumbs
    // -----------------------------------------------------------------------

    public function initPageHeaderToolbar(): void
    {
        parent::initPageHeaderToolbar();
        $this->page_header_toolbar_title = $this->trans(
            'Marques de France',
            [],
            'Modules.Mdfcforps.Admin'
        );
    }
}
