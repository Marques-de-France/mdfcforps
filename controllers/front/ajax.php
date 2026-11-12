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
 * Front controller: /index.php?fc=module&module=mdfcforps&controller=ajax
 *
 * Receives attribution cookie data from the JS tracker and stamps the PS
 * cookie object for later reading at order placement.
 *
 * Expected POST body (JSON):
 * {
 *   "mdf_click_id": "...",
 *   "mdf_attributed": "1",
 *   "mdf_utm_source": "...",
 *   "mdf_utm_medium": "...",
 *   "mdf_utm_campaign": "...",
 *   "mdf_utm_content": "...",
 *   "mdf_utm_term": "...",
 *   "mdf_landing_site": "...",
 *   "mdf_referring_site": "...",
 *   "mdf_landing_ref": "..."
 * }
 */
class MdfcforpsAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();
    }

    public function postProcess(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
            exit;
        }

        // Validate CSRF token if present (PS 1.7+)
        // Token validation is optional here — the endpoint only writes attribution
        // data into the visitor's own session cookie; no privileged action is taken.

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Empty body']);
            exit;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid payload']);
            exit;
        }

        try {
            $attributionService = new Mdfcforps\Service\AttributionService();
            // A campaign's click code replacing an older one: the cart's frozen copy is taken again
            $previousClickId = $attributionService->readSignal('mdf_click_id', 'mdf_click_id');
            $newClickId = is_string($data['mdf_click_id'] ?? null) ? $data['mdf_click_id'] : '';
            $campaignClick = Mdfcforps\Service\AttributionService::isCampaignClickId($newClickId)
                && $newClickId !== $previousClickId;
            $attributionService->stampCookie($data);

            // The stamp above is the moment this attribution becomes readable
            // server-side. Freeze it onto the cart now, so it survives a payment
            // provider that validates the order without the customer's cookies.
            // The freezer memoises the cart in the session, so the tracker calling
            // this endpoint on every page view still costs a single INSERT.
            $freezer = new Mdfcforps\Service\CartAttributionFreezer($attributionService);
            $campaignClick ? $freezer->refreeze() : $freezer->freeze();
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                '[MDF] Attribution AJAX error: ' . $e->getMessage(),
                2,
                null,
                'Mdfcforps'
            );

            http_response_code(200);
            echo json_encode(['success' => false, 'error' => 'Attribution failed']);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }
}
