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
        //
        // Wrapped so any failure is logged and returned as a readable JSON error
        // instead of an opaque PHP 500 (which the Hub reports as upstream_error:null).
        // Reaching this point already required a valid token, so surfacing the
        // message to the caller is safe for this internal, Hub-only endpoint.
        // ---------------------------------------------------------------
        try {
            $feedService = new Mdfcforps\Service\FeedService();
            $xml = $feedService->buildFeed($perPage, $page);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[MDF] Feed generation failed: ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                3,
                null,
                'Mdfcforps'
            );

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Feed generation failed.',
                'message' => $e->getMessage(),
                'type' => get_class($e),
                'where' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            exit;
        }

        // ---------------------------------------------------------------
        // Serve raw XML
        // ---------------------------------------------------------------
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $xml;
        exit;
    }
}
