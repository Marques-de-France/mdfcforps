<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Controller\Admin;

$mdfModuleRoot = dirname(__DIR__, 3);

// Hosted ZIP installs may not include Composer vendor autoload.
// Preload module classes needed by feed grid services to avoid class-not-found
// when Symfony instantiates grid factories/query builders.
foreach (['/src/Service', '/src/Grid'] as $mdfAutoloadDir) {
    $mdfPath = $mdfModuleRoot . $mdfAutoloadDir;
    if (!is_dir($mdfPath)) {
        continue;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($mdfPath, \FilesystemIterator::SKIP_DOTS)
    );

    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'index.php') {
            continue;
        }

        require_once $file->getPathname();
    }
}

use Mdfcforps\Service\FeedProductsService;
use Mdfcforps\Service\ModuleConfig;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Grid\GridFactory;
use PrestaShop\PrestaShop\Core\Search\Filters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Symfony controller for the Product Feed tab.
 *
 * Routes: mdfcforps_feed_index, mdfcforps_feed_toggle, mdfcforps_feed_bulk, mdfcforps_feed_mode
 *
 * Twig is injected via constructor (not fetched from container at runtime) to support
 * both PS8 (Symfony 4 — twig is public) and PS9 (Symfony 6 — twig is a private/inlined service).
 *
 * Extends Symfony's AbstractController (stable across Symfony 3.4/4.4/6.4) rather than
 * PrestaShop's FrameworkBundleAdminController, which was removed in PrestaShop 9. The only
 * base-class helpers used — json(), redirectToRoute(), createAccessDeniedException() — all
 * live in AbstractController, so this keeps a single code path working on PS 1.7.8, 8 and 9.
 * Back-office access control is still enforced via the route's _legacy_controller attribute,
 * independently of the controller base class.
 */
class FeedController extends AbstractController
{
    /** @var \Twig\Environment */
    private $twigEnv;

    /** @var CsrfTokenManagerInterface */
    private $csrfTokenManager;

    public function __construct(\Twig\Environment $twig, CsrfTokenManagerInterface $csrfTokenManager)
    {
        $this->twigEnv = $twig;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    public function dashboardAction(Request $request): Response
    {
        $hubClient = new \Mdfcforps\Service\HubClient();
        $updateChecker = new \Mdfcforps\Service\UpdateChecker();

        $dashboardStats = [
            'totalSales' => 0,
            'totalRevenue' => 0.0,
            'monthRevenue' => 0.0,
            'monthSales' => 0,
        ];
        $dashboardAnalytics = [];
        $status = [];
        $error = null;

        try {
            $status = $hubClient->getStatus();

            // The Hub folds its "latest module version" announcement into /status, so
            // keeping the banner current costs no extra HTTP request here.
            $updateChecker->ingestStatus($status);

            $now = new \DateTimeImmutable('now');
            $analyticsDateTo = $now->format('Y-m-d');
            $analyticsDateFrom = $now->modify('-11 months')->modify('first day of this month')->format('Y-m-d');
            $dashboardAnalytics = $hubClient->getAnalytics($analyticsDateFrom, $analyticsDateTo, 'month');

            $salesSummary = $hubClient->getHubSalesList(1, 1, [
                'status' => 'confirmed',
            ]);

            $monthStart = $now->modify('first day of this month')->format('Y-m-d');
            $monthSummary = $hubClient->getHubSalesList(1, 1, [
                'status' => 'confirmed',
                'dateFrom' => $monthStart,
                'dateTo' => $analyticsDateTo,
            ]);

            $dashboardStats['totalSales'] = (int) ($status['totalSales'] ?? 0);
            $dashboardStats['totalRevenue'] = (float) ($salesSummary['totalRevenue'] ?? 0.0);
            $dashboardStats['monthRevenue'] = (float) ($monthSummary['totalRevenue'] ?? 0.0);
            $dashboardStats['monthSales'] = (int) ($monthSummary['total'] ?? 0);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                '[MDF] Dashboard Hub error: ' . (string) $e->getMessage(),
                2,
                null,
                'Mdfcforps'
            );
            $error = $this->mdfTrans('Unable to reach the Marques de France platform.');
        }

        // Built outside the try/catch above on purpose: it reads the cached
        // announcement, so a Hub outage must not suppress the update banner. That is
        // precisely when a merchant most needs to be told to update.
        $updateVars = $this->buildUpdateViewVars($request, $updateChecker);

        return $this->mdfRender(
            '@Modules/mdfcforps/views/templates/admin/mdfcforps/dashboard.html.twig',
            array_merge($updateVars, [
                'dashboardStats' => $dashboardStats,
                'dashboardAnalytics' => $dashboardAnalytics,
                'status' => $status,
                'error' => $error,
                'currentTab' => 'dashboard',
                'enableSidebar' => true,
                'layoutTitle' => 'Marques de France',
            ])
        );
    }

    /**
     * Everything the dashboard update banner needs.
     *
     * @return array<string, mixed>
     */
    private function buildUpdateViewVars(Request $request, \Mdfcforps\Service\UpdateChecker $checker): array
    {
        $updater = new \Mdfcforps\Service\ModuleUpdater($checker);

        $vars = [
            'updateAvailable' => false,
            'updateInfo' => [
                'currentVersion' => '',
                'latestVersion' => '',
                'releaseNotesUrl' => '',
                'mandatory' => false,
            ],
            'updateBlockers' => [],
            'updatePhase' => \Mdfcforps\Service\ModuleUpdater::PHASE_NONE,
            'updateRecoveryCommand' => '',
            'updateLastError' => '',
            'updateResult' => '',
            'feedCsrfToken' => $this->getCsrfTokenManager()->getToken('mdfcforps_feed_actions')->getValue(),
        ];

        try {
            $announcement = $checker->getAnnouncement();
            $state = $updater->getState();
            $phase = (string) ($state['phase'] ?? \Mdfcforps\Service\ModuleUpdater::PHASE_NONE);

            // A swap whose finalize never ran (php-fpm timeout, closed tab) is only
            // surfaced once it is clearly stalled, so a redirect still in flight does
            // not flash a scary banner at the merchant.
            if ($phase === \Mdfcforps\Service\ModuleUpdater::PHASE_SWAPPED && !$updater->hasStalledUpdate()) {
                $phase = \Mdfcforps\Service\ModuleUpdater::PHASE_NONE;
            }

            $vars['updatePhase'] = $phase;
            $vars['updateRecoveryCommand'] = (string) ($state['command'] ?? '');
            $vars['updateLastError'] = $updater->getLastError();

            $result = (string) $request->query->get('update', '');
            $vars['updateResult'] = in_array($result, ['success', 'failed', 'manual', 'checked'], true) ? $result : '';

            $vars['updateInfo'] = [
                'currentVersion' => $checker->getInstalledVersion(),
                'latestVersion' => (string) ($announcement['latestVersion'] ?? ''),
                'releaseNotesUrl' => $this->safeExternalUrl((string) ($announcement['releaseNotesUrl'] ?? '')),
                'mandatory' => !empty($announcement['mandatory']),
            ];

            $vars['updateAvailable'] = $checker->isUpdateAvailable();

            if ($vars['updateAvailable']) {
                foreach ($updater->preflight($announcement) as $blocker) {
                    $vars['updateBlockers'][] = $this->mdfTrans(
                        (string) $blocker['message'],
                        'Modules.Mdfcforps.Admin',
                        $blocker['params']
                    );
                }
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                '[MDF][update] dashboard banner error: ' . $e->getMessage(),
                2,
                null,
                'Mdfcforps'
            );
        }

        return $vars;
    }

    /**
     * Release-notes links come from the Hub, so they are rendered only when they are
     * plainly an https URL — a javascript: or data: URL would otherwise become a
     * clickable link in the back office.
     */
    private function safeExternalUrl(string $url): string
    {
        return stripos($url, 'https://') === 0 ? $url : '';
    }

    /**
     * Re-check the Hub for a newer version on demand.
     */
    public function updateCheckAction(Request $request): Response
    {
        if (!$this->isValidFeedCsrfToken((string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        (new \Mdfcforps\Service\UpdateChecker())->refresh();

        return $this->redirectToRoute('mdfcforps_dashboard_index', ['update' => 'checked']);
    }

    /**
     * Phase 1 of a self-update: download, validate, and swap the files.
     *
     * Read ModuleUpdater's class docblock before touching this method. After
     * downloadValidateAndSwap() returns, the module directory on disk is the NEW
     * version while this request is still executing the OLD one, so the only calls
     * permitted below are pre-resolved core ones — and the response must be a
     * redirect, never a rendered template, because Twig would autoload new code into
     * an old request.
     */
    public function updateRunAction(Request $request): Response
    {
        if (!$this->isValidFeedCsrfToken((string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $checker = new \Mdfcforps\Service\UpdateChecker();
        $updater = new \Mdfcforps\Service\ModuleUpdater($checker);

        $announcement = $checker->getAnnouncement();
        $installed = $checker->getInstalledVersion();
        $target = (string) ($announcement['latestVersion'] ?? '');

        if (!$checker->isUpdateAvailable()) {
            return $this->redirectToRoute('mdfcforps_dashboard_index');
        }

        $blockers = $updater->preflight($announcement);
        if (!empty($blockers)) {
            $messages = [];
            foreach ($blockers as $blocker) {
                $messages[] = $this->mdfTrans((string) $blocker['message'], 'Modules.Mdfcforps.Admin', $blocker['params']);
            }

            $updater->recordError(implode(' ', $messages));

            return $this->redirectToRoute('mdfcforps_dashboard_index', ['update' => 'failed']);
        }

        // Resolved BEFORE the swap, while the router is unambiguously healthy and the
        // container on disk still matches the code in memory.
        $finalizeUrl = $this->generateUrl('mdfcforps_update_finalize');
        $failedUrl = $this->generateUrl('mdfcforps_dashboard_index', ['update' => 'failed']);

        // Force-load the redirect class now: a `use` statement does not autoload, and
        // after the swap the autoloader would resolve it out of the new tree.
        class_exists(RedirectResponse::class, true);

        \PrestaShopLogger::addLog(
            sprintf('[MDF][update] requested from=%s to=%s', $installed, $target),
            1,
            null,
            'Mdfcforps'
        );

        try {
            $updater->downloadValidateAndSwap($announcement);
        } catch (\Mdfcforps\Service\UpdateException $e) {
            $updater->recordError($e->getMessage());

            \PrestaShopLogger::addLog(
                sprintf('[MDF][update] failed at %s: %s', $e->getStep(), $e->getMessage()),
                3,
                null,
                'Mdfcforps'
            );

            $this->reportUpdateOutcome($installed, $target, 'failed', $e->getStep(), $e->getMessage());

            return new RedirectResponse($failedUrl);
        }

        // ---- Nothing below here may autoload. Core calls and a redirect only. ----

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return new RedirectResponse($finalizeUrl);
    }

    /**
     * Phase 2: bring the database in line with the files swapped in by phase 1.
     *
     * Runs in a fresh request, so this code is the NEW version.
     */
    public function updateFinalizeAction(): Response
    {
        $checker = new \Mdfcforps\Service\UpdateChecker();
        $updater = new \Mdfcforps\Service\ModuleUpdater($checker);

        $state = $updater->getState();
        $phase = (string) ($state['phase'] ?? \Mdfcforps\Service\ModuleUpdater::PHASE_NONE);
        $from = (string) ($state['from'] ?? '');
        $to = (string) ($state['to'] ?? '');

        if ($phase !== \Mdfcforps\Service\ModuleUpdater::PHASE_SWAPPED) {
            return $this->redirectToRoute('mdfcforps_dashboard_index');
        }

        // Resolved before the upgrade chain, which clears the compiled Symfony
        // container out from under this request.
        $successUrl = $this->generateUrl('mdfcforps_dashboard_index', ['update' => 'success']);
        $manualUrl = $this->generateUrl('mdfcforps_dashboard_index', ['update' => 'manual']);
        class_exists(RedirectResponse::class, true);

        $outcome = $updater->finalize();

        if ($outcome === \Mdfcforps\Service\ModuleUpdater::PHASE_NONE) {
            $this->reportUpdateOutcome($from, $to, 'success', 'finalize', '');

            return new RedirectResponse($successUrl);
        }

        $updater->recordError('The database upgrade did not complete automatically.');
        $this->reportUpdateOutcome($from, $to, 'manual_upgrade_required', 'finalize', '');

        return new RedirectResponse($manualUrl);
    }

    /**
     * Restore the version that was in place before the last update.
     */
    public function updateRollbackAction(Request $request): Response
    {
        if (!$this->isValidFeedCsrfToken((string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $updater = new \Mdfcforps\Service\ModuleUpdater();

        $dashboardUrl = $this->generateUrl('mdfcforps_dashboard_index', ['update' => 'failed']);
        class_exists(RedirectResponse::class, true);

        if (!$updater->rollback()) {
            $updater->recordError('The previous version could not be restored automatically.');
        }

        // Same rule as phase 1: the module directory just changed underneath this
        // request, so redirect rather than render.
        return new RedirectResponse($dashboardUrl);
    }

    /**
     * Tell the Hub how an update went. Fire-and-forget; never blocks the redirect.
     */
    private function reportUpdateOutcome(
        string $from,
        string $to,
        string $outcome,
        string $step,
        string $error
    ): void {
        try {
            (new \Mdfcforps\Service\HubClient())->reportUpdate([
                'fromVersion' => $from,
                'toVersion' => $to,
                'outcome' => $outcome,
                'step' => $step,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            // Reporting is best-effort by definition.
        }
    }

    public function salesAction(Request $request): Response
    {
        $analytics = [];
        $analyticsError = null;
        try {
            $now = new \DateTimeImmutable('now');
            $dateTo = $now->format('Y-m-d');
            $dateFrom = $now->modify('-730 days')->format('Y-m-d');

            $analytics = (new \Mdfcforps\Service\HubClient())->getAnalytics($dateFrom, $dateTo, 'day');
        } catch (\Throwable $e) {
            $analyticsError = $this->mdfTrans('Unable to load analytics chart data.');
        }

        $allParams = array_replace_recursive(
            $request->query->all(),
            $request->request->all()
        );

        $salesParams = $allParams['sales'] ?? [];
        $salesFilters = [
            'filters' => [
                'order_reference' => (string) ($salesParams['order_reference'] ?? ($salesParams['filters']['order_reference'] ?? '')),
                'source' => (string) ($salesParams['source'] ?? ($salesParams['filters']['source'] ?? '')),
                'status' => (string) ($salesParams['status'] ?? ($salesParams['filters']['status'] ?? '')),
                'amount' => $this->normalizePriceRange($salesParams['amount'] ?? ($salesParams['filters']['amount'] ?? null)),
            ],
            'orderBy' => $salesParams['orderBy'] ?? 'created_at',
            'sortOrder' => $salesParams['sortOrder'] ?? ($salesParams['orderWay'] ?? 'DESC'),
            'offset' => (int) ($salesParams['offset'] ?? 0),
            'limit' => (int) ($salesParams['limit'] ?? 25),
        ];

        $salesGrid = $this->mdfPresentGrid(
            $this->getSalesGridFactory()->getGrid(new Filters($salesFilters, 'sales'))
        );

        return $this->mdfRender(
            '@Modules/mdfcforps/views/templates/admin/mdfcforps/sales.html.twig',
            [
                'salesGrid' => $salesGrid,
                'analytics' => $analytics,
                'analyticsError' => $analyticsError,
                'affiliationActive' => \Configuration::get('MDFCFORPS_AFFILIATION_ACTIVE') === '1',
                'currentTab' => 'sales',
                'enableSidebar' => true,
                'layoutTitle' => 'Marques de France',
            ]
        );
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
        $feedError = null;
        $productFeedGrid = null;

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

        try {
            $productFeedGrid = $this->mdfPresentGrid(
                $this->getProductFeedGridFactory()->getGrid(new Filters($feedFilters, 'product_feed'))
            );
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                '[MDF] Feed grid error: ' . (string) $e->getMessage(),
                2,
                null,
                'Mdfcforps'
            );
            $feedError = $this->mdfTrans('Product feed is temporarily unavailable. Please contact support.');
        }

        // ---- Feed mode & URL -----------------------------------------
        $feedMode = ModuleConfig::get('MDFCFORPS_FEED_FILTER_MODE', 'TAG');
        $hubUrl = rtrim((string) (getenv('MDF_HUB_URL') ?: 'https://flux.marques-de-france.fr'), '/');
        $shopUrl = (\Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http')
                    . '://' . \Configuration::get('PS_SHOP_DOMAIN');
        $feedUrl = $hubUrl . '/feed/xml/' . rawurlencode($shopUrl);

        $templateVars = [
            'productFeedGrid' => $productFeedGrid,
            'feedMode' => $feedMode,
            'feedCsrfToken' => $this->getCsrfTokenManager()->getToken('mdfcforps_feed_actions')->getValue(),
            'feedUrl' => $feedUrl,
            'feedError' => $feedError,
            'manage' => $manage,
            'currentTab' => 'feed',
            'enableSidebar' => true,
            'layoutTitle' => 'Marques de France',
        ];

        // ---- Product Catalog grid (manage panel) ----------------------
        if ($manage && $feedError === null) {
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

            $templateVars['productCatalogGrid'] = $this->mdfPresentGrid(
                $this->getProductCatalogGridFactory()->getGrid(new Filters($catalogFilters, 'product_catalog'))
            );
        }

        return $this->mdfRender(
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
        if (!$this->isValidFeedCsrfToken((string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $productId = (int) $request->request->get('product_id', 0);
        $action = $request->request->get('action', '');

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
        if (!$this->isValidFeedCsrfToken((string) $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $action = $request->request->get('action', '');
        $ids = $request->request->all()['product_ids'] ?? [];

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
        if (!$this->isValidFeedCsrfToken((string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $mode = $request->request->get('feed_mode') === 'SERVERLIST' ? 'SERVERLIST' : 'TAG';
        ModuleConfig::update('MDFCFORPS_FEED_FILTER_MODE', $mode);

        return $this->redirectToRoute('mdfcforps_feed_index');
    }

    private function isValidFeedCsrfToken(string $submittedToken): bool
    {
        if ($submittedToken === '') {
            return false;
        }

        return $this->getCsrfTokenManager()->isTokenValid(
            new CsrfToken('mdfcforps_feed_actions', $submittedToken)
        );
    }

    private function getProductFeedGridFactory(): GridFactory
    {
        return SymfonyContainer::getInstance()->get('mdfcforps.grid.factory.product_feed');
    }

    private function getProductCatalogGridFactory(): GridFactory
    {
        return SymfonyContainer::getInstance()->get('mdfcforps.grid.factory.product_catalog');
    }

    private function getSalesGridFactory(): GridFactory
    {
        return SymfonyContainer::getInstance()->get('mdfcforps.grid.factory.sales');
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    /**
     * Version-agnostic trans() — works on PS8 (Symfony 4) and PS9 (Symfony 6).
     */
    /**
     * @param array<string, string> $params placeholder values, e.g. ['%version%' => '1.4.1']
     */
    private function mdfTrans(string $id, string $domain = 'Modules.Mdfcforps.Admin', array $params = []): string
    {
        /** @var \Symfony\Component\Translation\TranslatorInterface $translator */
        $translator = SymfonyContainer::getInstance()->get('translator');

        return $translator->trans($id, $params, $domain);
    }

    /**
     * Version-agnostic render() — uses injected Twig environment.
     * Works on PS8 (Symfony 4) and PS9 (Symfony 6 where 'twig' is a private/inlined service).
     *
     * @param array<string, mixed> $params
     */
    private function mdfRender(string $template, array $params = []): Response
    {
        return new Response($this->twigEnv->render($template, $params));
    }

    /**
     * Version-agnostic presentGrid() — uses the grid presenter service.
     *
     * @param \PrestaShop\PrestaShop\Core\Grid\GridInterface $grid
     *
     * @return array<string, mixed>
     */
    private function mdfPresentGrid($grid): array
    {
        $presenter = SymfonyContainer::getInstance()->get('prestashop.core.grid.presenter.grid_presenter');

        return $presenter->present($grid);
    }
}
