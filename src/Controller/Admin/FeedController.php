<?php

declare(strict_types=1);

namespace Mdfcforps\Controller\Admin;

use Configuration;
use Mdfcforps\Service\FeedProductsService;
use PrestaShop\PrestaShop\Core\Grid\GridFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tools;

/**
 * Symfony controller for the Product Feed tab.
 *
 * Routes: mdfcforps_feed_index, mdfcforps_feed_toggle, mdfcforps_feed_bulk, mdfcforps_feed_mode
 */
class FeedController extends FrameworkBundleAdminController
{
    /** @var GridFactory */
    private $productFeedGridFactory;

    /** @var GridFactory */
    private $productCatalogGridFactory;

    public function dashboardAction(): Response
    {
        $hubClient = new \Mdfcforps\Service\HubClient();

        $analytics = [];
        $status = [];
        $error = null;

        try {
            $analytics = $hubClient->getAnalytics();
            $status = $hubClient->getStatus();
        } catch (\Throwable $e) {
            $error = $this->trans('Unable to reach the Marques de France platform.', [], 'Modules.Mdfcforps.Admin');
        }

        return $this->render(
            '@Modules/mdfcforps/views/templates/admin/mdfcforps/dashboard.html.twig',
            [
                'analytics' => $analytics,
                'status' => $status,
                'error' => $error,
                'currentTab' => 'dashboard',
                'enableSidebar' => true,
                'layoutTitle' => 'Marques de France',
            ]
        );
    }

    public function salesAction(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('sales_page', 1));
        $saleRepo = new \Mdfcforps\Repository\SaleRepository();
        $result = $saleRepo->paginate($page, 25);

        return $this->render(
            '@Modules/mdfcforps/views/templates/admin/mdfcforps/sales.html.twig',
            [
                'sales' => $result['sales'],
                'total' => (int) $result['total'],
                'page' => $page,
                'perPage' => 25,
                'currentTab' => 'sales',
                'enableSidebar' => true,
                'layoutTitle' => 'Marques de France',
            ]
        );
    }

    public function __construct(
        GridFactory $productFeedGridFactory,
        GridFactory $productCatalogGridFactory
    ) {
        $this->productFeedGridFactory    = $productFeedGridFactory;
        $this->productCatalogGridFactory = $productCatalogGridFactory;
    }

    /**
     * @param mixed $raw
     *
     * @return array{min_field: ?float, max_field: ?float}
     */
    private function normalizePriceRange($raw): array
    {
        if (!is_array($raw)) {
            return ['min_field' => null, 'max_field' => null];
        }

        $minRaw = $raw['min_field'] ?? null;
        $maxRaw = $raw['max_field'] ?? null;

        $min = (is_numeric($minRaw) && $minRaw !== '') ? (float) $minRaw : null;
        $max = (is_numeric($maxRaw) && $maxRaw !== '') ? (float) $maxRaw : null;

        return [
            'min_field' => $min,
            'max_field' => $max,
        ];
    }

    /**
     * Main feed page: renders the Product Feed grid and (optionally) the catalog manage panel.
     */
    public function indexAction(Request $request): Response
    {
        $manage = (bool) $request->query->get('manage', false);

        // Merge GET (sort/paginate links) and POST (filter form submission)
        $allParams = array_replace_recursive(
            $request->query->all(),
            $request->request->all()
        );

        // ---- Product Feed grid (in-feed list) ---------------------------
        $feedParams = $allParams['product_feed'] ?? [];
        $feedFilters = [
            // Grid filter inputs may come as product_feed[name]/[reference]
            // (current rendering) or product_feed[filters][name]/[reference].
            'filters' => [
                'name' => (string) ($feedParams['name'] ?? ($feedParams['filters']['name'] ?? '')),
                'brand' => (string) ($feedParams['brand'] ?? ($feedParams['filters']['brand'] ?? '')),
                'reference' => (string) ($feedParams['reference'] ?? ($feedParams['filters']['reference'] ?? '')),
                'availability' => (string) ($feedParams['availability'] ?? ($feedParams['filters']['availability'] ?? '')),
                'price' => $this->normalizePriceRange($feedParams['price'] ?? ($feedParams['filters']['price'] ?? null)),
            ],
            'orderBy' => $feedParams['orderBy'] ?? null,
            // PrestaShop Filters expects sortOrder key.
            'sortOrder' => $feedParams['sortOrder'] ?? ($feedParams['orderWay'] ?? null),
            'offset' => (int) ($feedParams['offset'] ?? 0),
            'limit' => (int) ($feedParams['limit'] ?? 25),
        ];

        $productFeedGrid = $this->presentGrid(
            $this->productFeedGridFactory->getGrid(new Filters($feedFilters, 'product_feed'))
        );

        // ---- Feed mode & URL -----------------------------------------
        $feedMode = (string) Configuration::get('MDFCFORPS_FEED_FILTER_MODE');
        $hubUrl   = rtrim((string) (getenv('MDF_HUB_URL') ?: 'https://flux.marques-de-france.fr'), '/');
        $shopUrl  = (Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http')
                    . '://' . Configuration::get('PS_SHOP_DOMAIN');
        $feedUrl  = $hubUrl . '/feed/xml/' . rawurlencode($shopUrl);

        $templateVars = [
            'productFeedGrid'     => $productFeedGrid,
            'feedMode'            => $feedMode,
            'feedUrl'             => $feedUrl,
            'manage'              => $manage,
            'currentTab'          => 'feed',
            'enableSidebar'       => true,
            'layoutTitle'         => 'Marques de France',
        ];

        // ---- Product Catalog grid (manage panel) ----------------------
        if ($manage) {
            $catalogParams = $allParams['product_catalog'] ?? [];
            $catalogFilters = [
                'filters' => [
                    'search' => (string) ($catalogParams['search'] ?? ($catalogParams['filters']['search'] ?? '')),
                    'name' => (string) ($catalogParams['name'] ?? ($catalogParams['filters']['name'] ?? '')),
                    'brand' => (string) ($catalogParams['brand'] ?? ($catalogParams['filters']['brand'] ?? '')),
                    'reference' => (string) ($catalogParams['reference'] ?? ($catalogParams['filters']['reference'] ?? '')),
                    'availability' => (string) ($catalogParams['availability'] ?? ($catalogParams['filters']['availability'] ?? '')),
                    'price' => $this->normalizePriceRange($catalogParams['price'] ?? ($catalogParams['filters']['price'] ?? null)),
                ],
                'orderBy' => $catalogParams['orderBy'] ?? null,
                'sortOrder' => $catalogParams['sortOrder'] ?? ($catalogParams['orderWay'] ?? null),
                'offset' => (int) ($catalogParams['offset'] ?? 0),
                'limit' => (int) ($catalogParams['limit'] ?? 20),
            ];

            $templateVars['productCatalogGrid'] = $this->presentGrid(
                $this->productCatalogGridFactory->getGrid(new Filters($catalogFilters, 'product_catalog'))
            );
        }

        return $this->render(
            '@Modules/mdfcforps/views/templates/admin/mdfcforps/feed.html.twig',
            $templateVars
        );
    }

    /**
     * AJAX: toggle a single product in/out of the feed.
     * POST body: action=[add|remove]  product_id=N
     */
    public function toggleAction(Request $request): JsonResponse
    {
        $productId = (int) $request->request->get('product_id', 0);
        $action    = $request->request->get('action', '');

        if ($productId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid product ID']);
        }

        if ($action === 'add') {
            $product = new \Product($productId);
            if (!\Validate::isLoadedObject($product)) {
                return $this->json(['success' => false, 'error' => 'Product not found']);
            }
            FeedProductsService::addProduct($productId);
        } elseif ($action === 'remove') {
            FeedProductsService::removeProduct($productId);
        } else {
            return $this->json(['success' => false, 'error' => 'Invalid action']);
        }

        return $this->json(['success' => true]);
    }

    /**
     * AJAX: bulk add or remove multiple products.
     * POST body: action=[add|remove]  product_ids[]=N  product_ids[]=M  ...
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $action = $request->request->get('action', '');
        $ids    = $request->request->all()['product_ids'] ?? [];

        if (!in_array($action, ['add', 'remove'], true)) {
            return $this->json(['success' => false, 'error' => 'Invalid action']);
        }

        foreach ($ids as $id) {
            $productId = (int) $id;
            if ($productId <= 0) {
                continue;
            }
            if ($action === 'add') {
                FeedProductsService::addProduct($productId);
            } else {
                FeedProductsService::removeProduct($productId);
            }
        }

        return $this->json(['success' => true]);
    }

    /**
     * Updates feed mode (TAG / SERVERLIST) and redirects back to the feed page.
     */
    public function updateModeAction(Request $request): Response
    {
        $mode = $request->request->get('feed_mode') === 'SERVERLIST' ? 'SERVERLIST' : 'TAG';
        Configuration::updateValue('MDFCFORPS_FEED_FILTER_MODE', $mode);

        return $this->redirectToRoute('mdfcforps_feed_index');
    }
}
