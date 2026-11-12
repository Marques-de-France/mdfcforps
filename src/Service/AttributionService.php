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
 * Attribution service — mirrors the WooCommerce plugin's attribution logic
 * (class-mdf-wc-attribution.php) and, through it, the Shopify tracker.
 *
 * Each signal is read from two stores, in order:
 *  1. the PrestaShop session cookie — stamped by the AJAX tracker (stampCookie)
 *     and by the server-side landing capture (LandingCapture), so it survives an
 *     adblocker or a browser pruning the first-party cookies;
 *  2. the first-party `mdf_*` cookie written by the front tracker JS.
 *
 * `referring_site` has a third fallback on $_SERVER['HTTP_REFERER'], with
 * self-referrals excluded.
 *
 * Source priority (highest first):
 *  1. mdf_click       — a Marques de France click id
 *  2. mdf_landing_ref — ?ref= / ?landing_ref= containing "marques-de-france"
 *  3. mdf_utm         — utm_source, utm_medium OR utm_campaign containing "marques-de-france"
 *  4. mdf_referrer    — referrer on marques-de-france.fr
 *
 * Anything else resolves to "unknown", which is never recorded: an unattributed
 * order is not a Marques de France sale.
 */
class AttributionService
{
    private const MDF_DOMAIN = 'marques-de-france';
    private const MDF_REFERRER = 'marques-de-france.fr';

    /**
     * The source values that count as a Marques de France attribution.
     *
     * @var array<int, string>
     */
    public const MDF_SOURCES = ['mdf_click', 'mdf_landing_ref', 'mdf_utm', 'mdf_referrer'];

    /**
     * Signals whose value is a URL. They must not be HTML-encoded: these are stored
     * and transmitted to the Hub as data, never rendered as markup, and running a URL
     * through htmlspecialchars() turns every "&" separator into "&amp;", corrupting the
     * query string that analytics later reads.
     *
     * @var array<int, string>
     */
    private const URL_SIGNALS = ['mdf_landing_site', 'mdf_referring_site'];

    private const MAX_URL_LENGTH = 2048;

    /**
     * Cookie key memoising the cart whose attribution has already been frozen, so a
     * visitor browsing an attributed session does not re-issue an INSERT on every
     * page view. Declared here because this class owns the cookie schema; it is
     * CartAttributionFreezer that gives it meaning.
     */
    public const CART_FROZEN_KEY = 'mdf_cart_frozen';

    /**
     * A campaign's click code (a Marques de France dedicated newsletter's link):
     * mdf-campagne-{campaign}-{hex}. It replaces an older click code — the visitor came
     * back through the campaign — while any other code stays first-touch.
     */
    public const CAMPAIGN_CLICK_ID_PATTERN = '/^mdf-campagne-[1-9][0-9]{0,9}-[0-9a-f]{8,64}$/';

    public static function isCampaignClickId(string $clickId): bool
    {
        return preg_match(self::CAMPAIGN_CLICK_ID_PATTERN, $clickId) === 1;
    }

    /**
     * Maximum length of a URL signal *inside the PrestaShop session cookie*.
     *
     * PrestaShop serialises every key into a single encrypted cookie, and the
     * official PS 9 documentation puts the practical payload at roughly 1000
     * characters before encryption, against the browser's 4096-byte per-cookie
     * floor. Two URL signals capped at MAX_URL_LENGTH would exhaust that budget on
     * their own, and when the cookie overflows the browser drops all of it —
     * id_customer, id_cart and passwd included. Losing the visitor's session is a
     * far worse failure than losing the tail of a landing URL.
     *
     * The untruncated values are not lost: they live in the durable first-party
     * cookies and in mdfcforps_cart_attribution, neither of which shares this
     * budget. And truncation cannot affect attribution itself — resolveSource()
     * keys off click_id, landing_ref and the utm_* values, never off these two,
     * which travel to the Hub as reporting metadata only.
     */
    private const SESSION_URL_MAX_LENGTH = 255;

    // -----------------------------------------------------------------------
    // Attribution test
    // -----------------------------------------------------------------------

    /**
     * Static twin of isMdfAttributed(), for call sites that hold a raw source
     * string rather than a signal array (reconciliation, backfill).
     */
    public static function isMdfSource(string $source): bool
    {
        return in_array($source, self::MDF_SOURCES, true);
    }

    /**
     * Returns true when the collected signals represent a Marques de France sale.
     *
     * @param array<string, string> $attribution
     */
    public function isMdfAttributed(array $attribution): bool
    {
        return self::isMdfSource((string) ($attribution['source'] ?? ''));
    }

    /**
     * Choose between the attribution read live from the current request and the one
     * frozen against the cart before payment.
     *
     * Live wins whenever it is attributed. It is the same-request truth, and on an
     * ordinary on-site checkout it is byte-identical to the frozen copy anyway —
     * both derive from the same first-touch cookies. Keeping live first means a
     * working shop takes exactly the code path it takes today, so this fallback
     * cannot regress it.
     *
     * The frozen copy only speaks when the live read produced nothing usable, which
     * is precisely the redirect-payment case: an IPN request carrying no cookies at
     * all, or a cross-site POST return on which SameSite=Lax withholds them.
     *
     * A frozen row that is itself unattributed is ignored rather than returned: it
     * must never be able to manufacture an attribution the live read did not find.
     *
     * @param array<string, string>      $live
     * @param array<string, string>|null $frozen
     *
     * @return array<string, string>
     */
    public static function preferAttributed(array $live, ?array $frozen): array
    {
        if (self::isMdfSource((string) ($live['source'] ?? ''))) {
            return $live;
        }

        if ($frozen !== null && self::isMdfSource((string) ($frozen['source'] ?? ''))) {
            return $frozen;
        }

        return $live;
    }

    // -----------------------------------------------------------------------
    // Collect attribution signals at checkout
    // -----------------------------------------------------------------------

    /**
     * Reads every signal from the PrestaShop session then the first-party cookies,
     * at the moment of order placement.
     *
     * @return array<string, string>
     */
    public function collectSignals(): array
    {
        $clickId = $this->readSignal('mdf_click_id', 'mdf_click_id');
        $landingRef = $this->readSignal('mdf_landing_ref', 'mdf_landing_ref');
        $utmSource = $this->readSignal('mdf_utm_source', 'mdf_utm_source');
        $utmMedium = $this->readSignal('mdf_utm_medium', 'mdf_utm_medium');
        $utmCampaign = $this->readSignal('mdf_utm_campaign', 'mdf_utm_campaign');
        $utmContent = $this->readSignal('mdf_utm_content', 'mdf_utm_content');
        $utmTerm = $this->readSignal('mdf_utm_term', 'mdf_utm_term');
        $landingSite = $this->readSignal('mdf_landing_site', 'mdf_landing_site');
        $referringSite = $this->readReferringSite();

        $source = $this->resolveSource(
            $clickId,
            $landingRef,
            $utmSource,
            $utmMedium,
            $utmCampaign,
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
    // Signal reading — PrestaShop session first, first-party cookie second
    // -----------------------------------------------------------------------

    /**
     * Mirrors MDFCFORWC_Attribution::read_signal().
     *
     * Public so LandingCapture can apply the same first-touch check against the
     * same two stores, rather than reimplementing the lookup.
     */
    public function readSignal(string $key, string $cookieName): string
    {
        // PrestaShop session cookie: written by stampCookie() from the AJAX tracker
        // and by LandingCapture on the landing request, so it is the only store that
        // survives the front tracker JS being blocked entirely.
        $sessionValue = $this->readFromShopCookie($key);
        if ($sessionValue !== '') {
            return $sessionValue;
        }

        // First-party cookie fallback (60-day TTL, written by the tracker JS and by
        // LandingCapture's durable Set-Cookie).
        if (isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName])) {
            return $this->sanitizeSignal($key, $_COOKIE[$cookieName]);
        }

        return '';
    }

    /**
     * Reads a single key from the PrestaShop session cookie.
     *
     * Never throws: CLI, cron, webservice and unit-test contexts have no PrestaShop
     * cookie, and the caller must fall back to $_COOKIE rather than break checkout.
     */
    private function readFromShopCookie(string $key): string
    {
        if (!class_exists('\Context') || !class_exists('\Cookie')) {
            return '';
        }

        try {
            $context = \Context::getContext();
            $cookie = ($context instanceof \Context) ? ($context->cookie ?? null) : null;

            // Cookie::__get() returns false (not null) for an absent key, so __isset()
            // has to be consulted first.
            if (!$cookie instanceof \Cookie || !$cookie->__isset($key)) {
                return '';
            }

            $value = $cookie->__get($key);

            return is_scalar($value) ? $this->sanitizeSignal($key, (string) $value) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Session → cookie → server-side HTTP_REFERER, self-referrals excluded.
     *
     * At actionValidateOrder time the referrer is usually the shop's own checkout
     * page, so the exclusion is what keeps this fallback from manufacturing a bogus
     * external referral. It earns its keep on one-page and direct-POST checkouts.
     */
    private function readReferringSite(): string
    {
        $referringSite = $this->readSignal('mdf_referring_site', 'mdf_referring_site');
        if ($referringSite !== '') {
            return $referringSite;
        }

        if (empty($_SERVER['HTTP_REFERER']) || !is_string($_SERVER['HTTP_REFERER'])) {
            return '';
        }

        $rawReferer = (string) $_SERVER['HTTP_REFERER'];
        $refererHost = $this->extractHost($rawReferer);

        if ($refererHost === '' || in_array($refererHost, $this->shopHosts(), true)) {
            return '';
        }

        return self::sanitizeUrl($rawReferer);
    }

    // -----------------------------------------------------------------------
    // Source priority resolution
    // -----------------------------------------------------------------------

    private function resolveSource(
        string $clickId,
        string $landingRef,
        string $utmSource,
        string $utmMedium,
        string $utmCampaign,
        string $referringSite
    ): string {
        // 1. Direct MDF click ID
        if ($clickId !== '') {
            return 'mdf_click';
        }

        // 2. Landing referrer containing MDF domain — the newsletter channel
        //    (?ref=marques-de-france), where this is the only signal present.
        if (strpos(strtolower($landingRef), self::MDF_DOMAIN) !== false) {
            return 'mdf_landing_ref';
        }

        // 3. UTM containing MDF domain. The tracker attributes on utm_source OR
        //    utm_medium OR utm_campaign and only persists non-empty values, so
        //    checking utm_source alone dropped links carrying the marker elsewhere.
        foreach ([$utmSource, $utmMedium, $utmCampaign] as $utm) {
            if (strpos(strtolower($utm), self::MDF_DOMAIN) !== false) {
                return 'mdf_utm';
            }
        }

        // 4. Referring site on marques-de-france.fr
        if (strpos(strtolower($referringSite), self::MDF_REFERRER) !== false) {
            return 'mdf_referrer';
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Cookie stamp (called from AJAX front controller and LandingCapture)
    // -----------------------------------------------------------------------

    /**
     * Stamps the PrestaShop cookie object with attribution values.
     *
     * @param array<string, mixed> $data
     */
    public function stampCookie(array $data): void
    {
        $context = \Context::getContext();
        if (!$context || !isset($context->cookie) || !$context->cookie instanceof \Cookie) {
            throw new \RuntimeException('Unable to access PrestaShop cookie context');
        }

        $cookie = $context->cookie;

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
            self::CART_FROZEN_KEY,
        ];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $cookie->__set($field, $this->sanitizeForSessionCookie($field, (string) $data[$field]));
            }
        }

        $cookie->write();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Hosts that belong to this shop, used to reject self-referrals.
     * Covers multishop and separate SSL domains.
     *
     * @return array<int, string>
     */
    private function shopHosts(): array
    {
        $candidates = [];

        try {
            if (class_exists('\Tools')) {
                $candidates[] = (string) \Tools::getShopDomain();
                $candidates[] = (string) \Tools::getShopDomainSsl();
            }

            if (class_exists('\Configuration')) {
                $candidates[] = (string) \Configuration::get('PS_SHOP_DOMAIN');
                $candidates[] = (string) \Configuration::get('PS_SHOP_DOMAIN_SSL');
            }

            if (class_exists('\Context')) {
                $context = \Context::getContext();
                $shop = ($context instanceof \Context) ? ($context->shop ?? null) : null;
                if (is_object($shop)) {
                    $candidates[] = (string) ($shop->domain ?? '');
                    $candidates[] = (string) ($shop->domain_ssl ?? '');
                }
            }
        } catch (\Throwable $e) {
            // Fall through to HTTP_HOST only.
        }

        if (!empty($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
            $candidates[] = (string) $_SERVER['HTTP_HOST'];
        }

        $hosts = [];
        foreach ($candidates as $candidate) {
            $host = $this->extractHost($candidate);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Normalised host of a URL or of a bare domain, lowercased and without "www.".
     */
    private function extractHost(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Tools::getShopDomain() returns a bare host: parse_url() would put it in
        // `path`, not `host`, so a scheme has to be prepended first.
        if (strpos($url, '//') === false) {
            $url = 'http://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);

        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    /**
     * Sanitise a signal according to what it holds: URLs keep their punctuation,
     * everything else is HTML-escaped as before.
     */
    private function sanitizeSignal(string $key, string $value): string
    {
        return in_array($key, self::URL_SIGNALS, true)
            ? self::sanitizeUrl($value)
            : $this->sanitize($value);
    }

    /**
     * Sanitise a signal for storage in the PrestaShop session cookie, which unlike
     * the first-party cookies has a hard shared size budget — see
     * SESSION_URL_MAX_LENGTH for why the URL signals are capped here and nowhere else.
     */
    private function sanitizeForSessionCookie(string $key, string $value): string
    {
        $value = $this->sanitizeSignal($key, $value);

        if (!in_array($key, self::URL_SIGNALS, true)) {
            return $value;
        }

        return substr($value, 0, self::SESSION_URL_MAX_LENGTH);
    }

    private function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitise a URL without HTML-encoding it, so "&" separators survive.
     *
     * Safety is kept by construction rather than by escaping: only http(s) URLs are
     * accepted (so javascript:, data: and friends can never be stored), tags and
     * control characters are removed, and the angle brackets that could break markup
     * are dropped outright — they are not legal in a URL unencoded anyway.
     *
     * Shared with LandingCapture so both capture paths store identical values.
     */
    public static function sanitizeUrl(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = (string) preg_replace('/[\x00-\x1F\x7F<>"\']/u', '', $value);

        if ($value === '' || preg_match('#^https?://#i', $value) !== 1) {
            return '';
        }

        return substr($value, 0, self::MAX_URL_LENGTH);
    }
}
