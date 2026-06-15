<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Attribution service — mirrors the WooCommerce plugin's attribution logic.
 *
 * Priority order (highest first):
 *  1. mdf_click_id  (direct MDF affiliate click)
 *  2. landing_ref containing "marques-de-france"
 *  3. UTM source containing "marques-de-france"
 *  4. Referrer containing "marques-de-france.fr"
 */
class AttributionService
{
    private const MDF_DOMAIN = 'marques-de-france';
    private const MDF_REFERRER = 'marques-de-france.fr';

    // -----------------------------------------------------------------------
    // Collect attribution data from cookies at checkout
    // -----------------------------------------------------------------------

    /**
     * Reads $_COOKIE at the moment of order placement.
     *
     * @return array<string, string>
     */
    public function collectFromCookies(): array
    {
        $clickId = $this->sanitize($_COOKIE['mdf_click_id'] ?? '');
        $landingRef = $this->sanitize($_COOKIE['mdf_landing_ref'] ?? '');
        $utmSource = $this->sanitize($_COOKIE['mdf_utm_source'] ?? '');
        $utmMedium = $this->sanitize($_COOKIE['mdf_utm_medium'] ?? '');
        $utmCampaign = $this->sanitize($_COOKIE['mdf_utm_campaign'] ?? '');
        $utmContent = $this->sanitize($_COOKIE['mdf_utm_content'] ?? '');
        $utmTerm = $this->sanitize($_COOKIE['mdf_utm_term'] ?? '');
        $landingSite = $this->sanitize($_COOKIE['mdf_landing_site'] ?? '');
        $referringSite = $this->sanitize($_COOKIE['mdf_referring_site'] ?? '');

        $source = $this->resolveSource(
            $clickId,
            $landingRef,
            $utmSource,
            $referringSite
        );

        return [
            'source' => $source,
            'click_id' => $clickId,
            'landing_ref' => $landingRef,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'utm_content' => $utmContent,
            'utm_term' => $utmTerm,
            'landing_site' => $landingSite,
            'referring_site' => $referringSite,
        ];
    }

    // -----------------------------------------------------------------------
    // Source priority resolution
    // -----------------------------------------------------------------------

    private function resolveSource(
        string $clickId,
        string $landingRef,
        string $utmSource,
        string $referringSite,
    ): string {
        // 1. Direct MDF click ID
        if ($clickId !== '') {
            return 'mdf_click';
        }

        // 2. Landing referrer containing MDF domain
        if (str_contains(strtolower($landingRef), self::MDF_DOMAIN)) {
            return 'mdf_landing_ref';
        }

        // 3. UTM source containing MDF domain
        if (str_contains(strtolower($utmSource), self::MDF_DOMAIN)) {
            return 'mdf_utm';
        }

        // 4. Referring site containing MDF domain
        if (str_contains(strtolower($referringSite), self::MDF_REFERRER)) {
            return 'mdf_referrer';
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Cookie stamp (called from AJAX front controller)
    // -----------------------------------------------------------------------

    /**
     * Stamps PS cookie object with attribution values received via AJAX.
     * Only called by the front ajax controller — values come from JS.
     *
     * @param array<string, mixed> $data
     */
    public function stampCookie(array $data): void
    {
        $cookie = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()
            ->get('prestashop.adapter.legacy.context')
            ->getContext()
            ->cookie;

        $fields = [
            'mdf_click_id',
            'mdf_attributed',
            'mdf_utm_source',
            'mdf_utm_medium',
            'mdf_utm_campaign',
            'mdf_utm_content',
            'mdf_utm_term',
            'mdf_landing_site',
            'mdf_referring_site',
            'mdf_landing_ref',
        ];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $cookie->__set($field, $this->sanitize((string) $data[$field]));
            }
        }

        $cookie->write();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}
