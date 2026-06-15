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

        // Legacy controller acts as an entrypoint shim to explicit Symfony tab routes.
        $router = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('router');
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
