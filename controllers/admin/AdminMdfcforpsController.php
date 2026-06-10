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
    private const TABS = ['dashboard', 'sales', 'feed'];

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

        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin/chart.min.js');
        $this->addJS($this->module->getPathUri() . 'views/js/admin/dashboard.js');
        $this->addJS($this->module->getPathUri() . 'views/js/admin/feed.js');

        // Assign module template dir so partials can be included from any tab
        $this->context->smarty->assign(
            'mdf_tpl_dir',
            _PS_MODULE_DIR_ . 'mdfcforps/views/templates/admin/'
        );
        $this->context->smarty->assign(
            'mdf_banner_tpl',
            _PS_MODULE_DIR_ . 'mdfcforps/views/templates/admin/_status_banner.tpl'
        );

        switch ($tab) {
            case 'sales':
                $this->renderSalesTab();
                break;
            case 'feed':
                $this->renderFeedTab();
                break;
            default:
                $this->renderDashboardTab();
        }
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
