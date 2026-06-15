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

/**
 * Legacy back-office entrypoint shim.
 *
 * This controller only redirects to Symfony routes handled by
 * Mdfcforps\Controller\Admin\FeedController.
 */
class AdminMdfcforpsController extends ModuleAdminController
{
    /** @var string[] */
    private const TABS = ['dashboard', 'feed', 'sales'];

    /** @var array<string, string> */
    private const TAB_ROUTES = [
        'dashboard' => 'mdfcforps_dashboard_index',
        'feed' => 'mdfcforps_feed_index',
        'sales' => 'mdfcforps_sales_index',
    ];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        $this->meta_title = 'Marques de France';

        parent::__construct();

        $moduleInstance = Module::getInstanceByName('mdfcforps');
        if ($moduleInstance instanceof Module) {
            $this->module = $moduleInstance;
        }
    }

    public function initContent(): void
    {
        parent::initContent();

        $tab = Tools::getValue('tab', 'dashboard');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'dashboard';
        }

        $routeParams = [];
        if ($tab === 'sales') {
            $routeParams['sales_page'] = max(1, (int) Tools::getValue('sales_page', 1));
        }

        $targetUrl = $this->buildTabUrl($tab, $routeParams);
        Tools::redirectAdmin($targetUrl);
    }

    /**
     * Build target tab URL using Symfony route when available, fallback to module configure URL.
     *
     * @param array<string, int|string> $routeParams
     */
    private function buildTabUrl(string $tab, array $routeParams = []): string
    {
        $routeName = self::TAB_ROUTES[$tab] ?? self::TAB_ROUTES['dashboard'];

        try {
            $container = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if ($container && $container->has('router')) {
                $url = $container->get('router')->generate($routeName, $routeParams);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[MDF] Legacy route fallback for tab "' . $tab . '": ' . (string) $e->getMessage(),
                2,
                null,
                'Mdfcforps'
            );
        }

        $query = ['configure' => 'mdfcforps'];
        if ($tab !== 'dashboard') {
            $query['tab'] = $tab;
        }
        if ($tab === 'sales' && isset($routeParams['sales_page'])) {
            $query['sales_page'] = (int) $routeParams['sales_page'];
        }

        return $this->context->link->getAdminLink('AdminModules', true, [], $query);
    }

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
