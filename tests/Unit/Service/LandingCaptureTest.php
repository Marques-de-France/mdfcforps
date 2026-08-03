<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Tests\Unit\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mdfcforps\Service\AttributionService;
use Mdfcforps\Service\LandingCapture;
use PHPUnit\Framework\TestCase;

/**
 * The server-side capture is what keeps attribution alive when the front tracker
 * JS never runs. Every test here therefore asserts through collectSignals() with
 * an EMPTY $_COOKIE: that is the JS-blocked visitor the attribution gate must not
 * drop.
 */
final class LandingCaptureTest extends TestCase
{
    private const VALID_CLICK_ID = '1234abcd5678efgh9012ijkl3456mnop';

    protected function setUp(): void
    {
        $_GET = [];
        $_COOKIE = [];
        $_SERVER['HTTP_HOST'] = 'boutique.test';
        $_SERVER['REQUEST_URI'] = '/';
        $this->givenShopSession();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
        \Context::resetForTests();
        \Tools::$shopDomain = 'boutique.test';
    }

    // -----------------------------------------------------------------------
    // Capture → resolution, with no cookies at all
    // -----------------------------------------------------------------------

    public function testThemedLinkIsCapturedAndResolvesToUtm(): void
    {
        $_GET = [
            'utm_source' => 'marques-de-france',
            'utm_medium' => 'marques-de-france-siteweb',
            'utm_campaign' => 'fiche-marque',
            'mdf_click_id' => self::VALID_CLICK_ID,
        ];

        (new LandingCapture())->capture();

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_click', $result['source']);
        self::assertSame(self::VALID_CLICK_ID, $result['click_id']);
        self::assertSame('marques-de-france', $result['utm_source']);
    }

    public function testNewsletterRefIsCapturedAndResolvesToLandingRef(): void
    {
        $_GET = ['ref' => 'marques-de-france'];

        (new LandingCapture())->capture();

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_landing_ref', $result['source']);
        self::assertSame('marques-de-france', $result['landing_ref']);
    }

    public function testLandingRefFallsBackToTheLandingRefParam(): void
    {
        $_GET = ['landing_ref' => 'marques-de-france-newsletter'];

        (new LandingCapture())->capture();

        self::assertSame('mdf_landing_ref', (new AttributionService())->collectSignals()['source']);
    }

    public function testUtmMediumAloneIsEnough(): void
    {
        $_GET = ['utm_source' => 'newsletter', 'utm_medium' => 'marques-de-france-siteweb'];

        (new LandingCapture())->capture();

        self::assertSame('mdf_utm', (new AttributionService())->collectSignals()['source']);
    }

    public function testMdfRefererIsCaptured(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://www.marques-de-france.fr/marque/exemple';

        (new LandingCapture())->capture();

        self::assertSame('mdf_referrer', (new AttributionService())->collectSignals()['source']);
    }

    // -----------------------------------------------------------------------
    // Nothing is captured without a Marques de France signal
    // -----------------------------------------------------------------------

    public function testAbbreviatedRefIsNotCaptured(): void
    {
        // Documented constraint: the value must carry the full token.
        $_GET = ['ref' => 'mdf'];

        (new LandingCapture())->capture();

        self::assertSame('unknown', (new AttributionService())->collectSignals()['source']);
        self::assertFalse($this->sessionValue('mdf_attributed'));
    }

    public function testForeignCampaignIsNotCaptured(): void
    {
        // Capturing a non-MDF utm_source would claim the first-touch slot and lock
        // out a genuine Marques de France visit arriving later.
        $_GET = ['utm_source' => 'google', 'utm_medium' => 'cpc'];

        (new LandingCapture())->capture();

        self::assertSame('unknown', (new AttributionService())->collectSignals()['source']);
        self::assertFalse($this->sessionValue('mdf_utm_source'));
    }

    public function testPlainRequestCapturesNothing(): void
    {
        (new LandingCapture())->capture();

        self::assertFalse($this->sessionValue('mdf_attributed'));
    }

    public function testSelfReferralIsNotCaptured(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://boutique.test/panier';

        (new LandingCapture())->capture();

        self::assertSame('unknown', (new AttributionService())->collectSignals()['source']);
    }

    // -----------------------------------------------------------------------
    // First touch
    // -----------------------------------------------------------------------

    public function testFirstTouchIsNotOverwritten(): void
    {
        $_GET = ['ref' => 'marques-de-france-newsletter'];
        (new LandingCapture())->capture();

        $_GET = ['utm_source' => 'marques-de-france', 'utm_campaign' => 'seconde-visite'];
        (new LandingCapture())->capture();

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_landing_ref', $result['source']);
        self::assertSame('marques-de-france-newsletter', $result['landing_ref']);
        self::assertSame('', $result['utm_campaign']);
    }

    public function testExistingCookieValueIsNotReplaced(): void
    {
        $_COOKIE['mdf_landing_ref'] = 'marques-de-france-premiere-visite';
        $_GET = ['ref' => 'marques-de-france-seconde-visite'];

        (new LandingCapture())->capture();

        self::assertSame(
            'marques-de-france-premiere-visite',
            (new AttributionService())->collectSignals()['landing_ref']
        );
    }

    // -----------------------------------------------------------------------
    // Hardening
    // -----------------------------------------------------------------------

    public function testMalformedClickIdIsRejectedButOtherSignalsSurvive(): void
    {
        $_GET = ['mdf_click_id' => 'nope!', 'utm_source' => 'marques-de-france'];

        (new LandingCapture())->capture();

        $result = (new AttributionService())->collectSignals();

        self::assertSame('', $result['click_id']);
        self::assertSame('mdf_utm', $result['source']);
    }

    public function testCookieDelimiterDoesNotAbortTheCapture(): void
    {
        // PrestaShop's Cookie::__set() throws on "|" — one landing URL must not
        // take the whole capture down with it.
        $_SERVER['REQUEST_URI'] = '/promo?ref=marques-de-france&filter=a|b';
        $_GET = ['ref' => 'marques-de-france', 'filter' => 'a|b'];

        (new LandingCapture())->capture();

        self::assertSame('mdf_landing_ref', (new AttributionService())->collectSignals()['source']);
    }

    public function testCaptureNeverThrowsWithoutAShopContext(): void
    {
        \Context::$instance = null;
        $_GET = ['ref' => 'marques-de-france'];

        (new LandingCapture())->capture();

        // No session to write to; the assertion is simply that nothing exploded.
        self::assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function givenShopSession(): void
    {
        $context = new \Context();
        $context->cookie = new \Cookie();
        \Context::$instance = $context;
    }

    /**
     * @return mixed false when the key was never written
     */
    private function sessionValue(string $key)
    {
        $context = \Context::getContext();

        return ($context && $context->cookie) ? $context->cookie->__get($key) : false;
    }
}
