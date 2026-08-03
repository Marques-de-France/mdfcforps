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
 * Server-side landing capture — the PrestaShop counterpart of
 * MDFCFORWC_Tracker::capture_referer_server_side().
 *
 * Runs on every front request and reads the Marques de France signals straight
 * out of $_GET, with no JavaScript involved. This is what keeps attribution
 * working when the front tracker JS never runs: an adblocker matching the script
 * path, a CSP, a JS error raised by another module, or a bot-mitigation layer
 * that defers scripts. All of those would otherwise wipe out every client-side
 * signal at once, since the tracker writes the cookies, the localStorage entries
 * and the AJAX stamp from that single file.
 *
 * Two stores are written, for different reasons:
 *  - the PrestaShop session cookie, read back by AttributionService::readSignal();
 *  - a durable first-party cookie (60 days), because the PrestaShop session
 *    expires after PS_COOKIE_LIFETIME_FO (480 minutes by default), far too short
 *    for a conversion days after a newsletter click. A cookie set by the server
 *    is also more durable than the tracker's: Safari caps cookies written via
 *    document.cookie at 7 days, and that cap does not apply to Set-Cookie.
 *
 * Attribution is first-touch, exactly like the tracker JS: nothing is written
 * once an attribution is already stored, and nothing is written at all for a
 * request that carries no Marques de France signal — capturing a non-MDF
 * utm_source would otherwise claim the first-touch slot and lock out a genuine
 * Marques de France visit arriving later.
 */
class LandingCapture
{
    private const COOKIE_TTL_DAYS = 60;
    private const MDF_DOMAIN = 'marques-de-france';
    private const MDF_REFERRER = 'marques-de-france.fr';
    private const CLICK_ID_PATTERN = '/^[A-Za-z0-9_-]{8,128}$/';
    private const MAX_URL_LENGTH = 2048;

    /** @var AttributionService */
    private $attributionService;

    public function __construct(?AttributionService $attributionService = null)
    {
        $this->attributionService = $attributionService ?? new AttributionService();
    }

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    /**
     * Capture the Marques de France signals carried by the current front request.
     *
     * Never throws: a capture failure must not break the page it runs on.
     */
    public function capture(): void
    {
        try {
            $signals = $this->collectFromQuery();

            if ($signals === []) {
                return;
            }

            // First-touch: an attribution already stored (by this class or by the
            // tracker JS) is never overwritten.
            if ($this->attributionService->readSignal('mdf_attributed', 'mdf_attributed') !== '') {
                return;
            }

            // Resolve which keys are still free BEFORE writing anything: the session
            // write below would otherwise make every key look occupied to the durable
            // cookie pass, and no cookie would ever be set.
            $signals = $this->rejectOccupiedKeys($signals);

            if ($signals === []) {
                return;
            }

            $signals['mdf_attributed'] = '1';

            $this->writeSessionCookie($signals);
            $this->writeDurableCookies($signals);
        } catch (\Throwable $e) {
            // Swallowed on purpose — see the method docblock.
        }
    }

    // -----------------------------------------------------------------------
    // Signal collection
    // -----------------------------------------------------------------------

    /**
     * Returns the signals to store, or an empty array when this request carries
     * no Marques de France attribution.
     *
     * @return array<string, string>
     */
    private function collectFromQuery(): array
    {
        $utmSource = $this->readQuery('utm_source');
        $utmMedium = $this->readQuery('utm_medium');
        $utmCampaign = $this->readQuery('utm_campaign');
        $utmContent = $this->readQuery('utm_content');
        $utmTerm = $this->readQuery('utm_term');
        $clickId = $this->readClickId();

        // ?ref= then ?landing_ref=, matching the tracker JS.
        $landingRef = $this->readQuery('ref');
        if ($landingRef === '') {
            $landingRef = $this->readQuery('landing_ref');
        }

        $referringSite = $this->readReferer();

        $isMdf = $clickId !== ''
            || $this->containsMdfDomain($landingRef)
            || $this->containsMdfDomain($utmSource)
            || $this->containsMdfDomain($utmMedium)
            || $this->containsMdfDomain($utmCampaign)
            || (stripos($referringSite, self::MDF_REFERRER) !== false);

        if (!$isMdf) {
            return [];
        }

        $signals = [
            'mdf_click_id' => $clickId,
            'mdf_landing_ref' => $landingRef,
            'mdf_utm_source' => $utmSource,
            'mdf_utm_medium' => $utmMedium,
            'mdf_utm_campaign' => $utmCampaign,
            'mdf_utm_content' => $utmContent,
            'mdf_utm_term' => $utmTerm,
            'mdf_landing_site' => $this->currentUrl(),
            'mdf_referring_site' => $referringSite,
        ];

        return array_filter($signals, static function ($value): bool {
            return $value !== '';
        });
    }

    private function readQuery(string $key): string
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return '';
        }

        return $this->sanitize($_GET[$key]);
    }

    /**
     * Click ids are validated against the same pattern the tracker JS enforces,
     * so a malformed value never reaches storage.
     */
    private function readClickId(): string
    {
        $clickId = trim($this->readQuery('mdf_click_id'));

        return preg_match(self::CLICK_ID_PATTERN, $clickId) === 1 ? $clickId : '';
    }

    /**
     * External referrer of the current request, self-referrals excluded.
     *
     * Unlike the checkout-time fallback in AttributionService, this runs on the
     * landing request, where the referrer genuinely is marques-de-france.fr.
     */
    private function readReferer(): string
    {
        if (empty($_SERVER['HTTP_REFERER']) || !is_string($_SERVER['HTTP_REFERER'])) {
            return '';
        }

        // URL-aware: keeps "&" intact instead of storing "&amp;".
        $referer = AttributionService::sanitizeUrl((string) $_SERVER['HTTP_REFERER']);
        if ($referer === '') {
            return '';
        }

        $refererHost = $this->host($referer);
        $selfHost = $this->host((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($refererHost === '' || ($selfHost !== '' && $refererHost === $selfHost)) {
            return '';
        }

        return $referer;
    }

    private function currentUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

        $url = ($isSecure ? 'https://' : 'http://') . $host . (string) ($_SERVER['REQUEST_URI'] ?? '/');

        // URL-aware: the landing URL is reported to the Hub as data, so its query
        // string must survive verbatim — "&" separators, not "&amp;".
        return AttributionService::sanitizeUrl($url);
    }

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $signals
     */
    private function writeSessionCookie(array $signals): void
    {
        try {
            $this->attributionService->stampCookie(
                array_map([$this, 'stripCookieDelimiters'], $signals)
            );
        } catch (\Throwable $e) {
            // No PrestaShop cookie on this request (CLI, webservice): the durable
            // cookies below still carry the attribution.
        }
    }

    /**
     * Durable first-party cookies, matching the names and TTL the tracker JS uses
     * so both paths converge on one store and the JS can rehydrate from them.
     *
     * @param array<string, string> $signals
     */
    private function writeDurableCookies(array $signals): void
    {
        if (headers_sent()) {
            return;
        }

        $expires = time() + (self::COOKIE_TTL_DAYS * 86400);

        foreach ($signals as $name => $value) {
            if ($value === '') {
                continue;
            }

            setcookie($name, $value, [
                'expires' => $expires,
                'path' => '/',
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
                'httponly' => false,
            ]);

            // Make the value readable within this same request.
            $_COOKIE[$name] = $value;
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Drop the signals whose key is already held by either store, so a value
     * written by the tracker JS on an earlier visit is never replaced.
     *
     * @param array<string, string> $signals
     *
     * @return array<string, string>
     */
    private function rejectOccupiedKeys(array $signals): array
    {
        $free = [];

        foreach ($signals as $name => $value) {
            if ($this->attributionService->readSignal($name, $name) === '') {
                $free[$name] = $value;
            }
        }

        return $free;
    }

    private function containsMdfDomain(string $value): bool
    {
        return $value !== '' && stripos($value, self::MDF_DOMAIN) !== false;
    }

    private function host(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '//') === false) {
            $value = 'http://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);

        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    /**
     * PrestaShop's Cookie::__set() throws on values containing "|" or "¤", its
     * internal delimiters. Strip them rather than let one landing URL abort the
     * whole capture.
     */
    private function stripCookieDelimiters(string $value): string
    {
        return str_replace(['|', '¤'], '', $value);
    }

    private function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}
