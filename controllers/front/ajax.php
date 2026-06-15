<?php
/**
 * Module source file.
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
        } catch (\JsonException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid payload']);
            exit;
        }

        $attributionService = new \Mdfcforps\Service\AttributionService();
        $attributionService->stampCookie($data);

        echo json_encode(['success' => true]);
        exit;
    }
}
