<?php

/**
 * Module source file.
 *
 * @author Marques de France
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Front controller: /index.php?fc=module&module=mdfcforps&controller=feed
 *
 * Serves the Google Merchant Center RSS 2.0 product feed.
 *
 * Token authentication:
 *   ?token=<secureToken> — required when MDFCFORPS_SECURE_TOKEN is configured.
 *
 * Pagination:
 *   ?per_page=200&page=1 (defaults: 200 / 1)
 *
 * This URL is called by the Hub, NOT by merchants — it is internal only.
 */
class MdfcforpsFeedModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();
    }

    public function postProcess(): void
    {
        // ---------------------------------------------------------------
        // Token validation
        // ---------------------------------------------------------------
        $storedToken = Mdfcforps\Service\ModuleConfig::get('MDFCFORPS_SECURE_TOKEN', '');

        if ($storedToken !== '') {
            $providedToken = (string) (Tools::getValue('token') ?? '');

            if ($providedToken === '') {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid token.']);
                exit;
            }

            $tokenA = hash('sha256', $providedToken);
            $tokenB = hash('sha256', $storedToken);

            if (!hash_equals($tokenB, $tokenA)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid token.']);
                exit;
            }
        }

        // ---------------------------------------------------------------
        // Pagination params
        // ---------------------------------------------------------------
        $perPage = min(500, max(1, (int) (Tools::getValue('per_page') ?: 200)));
        $page = max(1, (int) (Tools::getValue('page') ?: 1));

        // ---------------------------------------------------------------
        // Generate feed
        // ---------------------------------------------------------------
        $feedService = new Mdfcforps\Service\FeedService();
        $xml = $feedService->buildFeed($perPage, $page);

        // ---------------------------------------------------------------
        // Serve raw XML
        // ---------------------------------------------------------------
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $xml;
        exit;
    }
}
