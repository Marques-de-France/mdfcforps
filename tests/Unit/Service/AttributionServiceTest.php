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
use PHPUnit\Framework\TestCase;

final class AttributionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        \Context::resetForTests();
        \Tools::$shopDomain = 'boutique.test';
    }

    // -----------------------------------------------------------------------
    // Source resolution
    // -----------------------------------------------------------------------

    public function testCollectSignalsUsesClickIdPriority(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';
        $_COOKIE['mdf_utm_source'] = 'marques-de-france';
        $_COOKIE['mdf_landing_ref'] = 'https://example.test/?ref=marques-de-france';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_click', $result['source']);
        self::assertSame('CLICK_12345', $result['click_id']);
    }

    public function testCollectSignalsFallsBackToUnknown(): void
    {
        $_COOKIE['mdf_utm_source'] = 'google';
        $_COOKIE['mdf_referring_site'] = 'https://example.org/';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('unknown', $result['source']);
    }

    public function testLandingRefAttributesTheNewsletterChannel(): void
    {
        $_COOKIE['mdf_landing_ref'] = 'marques-de-france';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_landing_ref', $result['source']);
    }

    public function testAbbreviatedLandingRefIsNotAttributed(): void
    {
        // The value must carry the full token: ?ref=mdf is not an MDF attribution.
        $_COOKIE['mdf_landing_ref'] = 'mdf';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('unknown', $result['source']);
    }

    public function testUtmMediumAttributesTheSale(): void
    {
        $_COOKIE['mdf_utm_source'] = 'newsletter';
        $_COOKIE['mdf_utm_medium'] = 'marques-de-france-siteweb';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_utm', $result['source']);
    }

    public function testUtmCampaignAttributesTheSale(): void
    {
        $_COOKIE['mdf_utm_campaign'] = 'marques-de-france-fiche-marque';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('mdf_utm', $result['source']);
    }

    // -----------------------------------------------------------------------
    // isMdfAttributed
    // -----------------------------------------------------------------------

    /**
     * @dataProvider mdfSourceProvider
     */
    public function testIsMdfAttributedAcceptsEveryMdfSource(string $source): void
    {
        self::assertTrue((new AttributionService())->isMdfAttributed(['source' => $source]));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function mdfSourceProvider(): array
    {
        return [
            ['mdf_click'],
            ['mdf_landing_ref'],
            ['mdf_utm'],
            ['mdf_referrer'],
        ];
    }

    /**
     * @dataProvider nonMdfAttributionProvider
     *
     * @param array<string, string> $attribution
     */
    public function testIsMdfAttributedRejectsAnythingElse(array $attribution): void
    {
        self::assertFalse((new AttributionService())->isMdfAttributed($attribution));
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public function nonMdfAttributionProvider(): array
    {
        return [
            'unknown' => [['source' => 'unknown']],
            'empty' => [['source' => '']],
            'missing key' => [[]],
            'other referral' => [['source' => 'referral']],
        ];
    }

    // -----------------------------------------------------------------------
    // Store precedence: PrestaShop session → cookie → HTTP_REFERER
    // -----------------------------------------------------------------------

    public function testSessionValueTakesPrecedenceOverCookie(): void
    {
        $this->givenShopSession(['mdf_click_id' => 'SESSION_CLICK']);
        $_COOKIE['mdf_click_id'] = 'COOKIE_CLICK';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('SESSION_CLICK', $result['click_id']);
    }

    public function testCookieIsUsedWhenSessionHasNoValue(): void
    {
        $this->givenShopSession();
        $_COOKIE['mdf_click_id'] = 'COOKIE_CLICK';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('COOKIE_CLICK', $result['click_id']);
    }

    public function testCookieIsUsedWhenNoShopContextExists(): void
    {
        \Context::$instance = null;
        $_COOKIE['mdf_click_id'] = 'COOKIE_CLICK';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('COOKIE_CLICK', $result['click_id']);
        self::assertSame('mdf_click', $result['source']);
    }

    // -----------------------------------------------------------------------
    // HTTP_REFERER fallback
    // -----------------------------------------------------------------------

    public function testRefererIsUsedWhenNoStoredReferringSite(): void
    {
        $_SERVER['HTTP_HOST'] = 'boutique.test';
        $_SERVER['HTTP_REFERER'] = 'https://www.marques-de-france.fr/marque/exemple';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('https://www.marques-de-france.fr/marque/exemple', $result['referring_site']);
        self::assertSame('mdf_referrer', $result['source']);
    }

    /**
     * @dataProvider selfRefererProvider
     */
    public function testSelfReferralIsNotUsedAsReferringSite(string $referer): void
    {
        \Tools::$shopDomain = 'boutique.test';
        $_SERVER['HTTP_HOST'] = 'boutique.test';
        $_SERVER['HTTP_REFERER'] = $referer;

        $result = (new AttributionService())->collectSignals();

        self::assertSame('', $result['referring_site']);
        self::assertSame('unknown', $result['source']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function selfRefererProvider(): array
    {
        return [
            'bare host' => ['https://boutique.test/commande'],
            'www prefixed' => ['https://www.boutique.test/commande'],
        ];
    }

    public function testStoredReferringSiteWinsOverReferer(): void
    {
        $_COOKIE['mdf_referring_site'] = 'https://www.marques-de-france.fr/marque/exemple';
        $_SERVER['HTTP_HOST'] = 'boutique.test';
        $_SERVER['HTTP_REFERER'] = 'https://example.org/autre';

        $result = (new AttributionService())->collectSignals();

        self::assertSame('https://www.marques-de-france.fr/marque/exemple', $result['referring_site']);
        self::assertSame('mdf_referrer', $result['source']);
    }

    // -----------------------------------------------------------------------
    // URL signals keep their punctuation
    // -----------------------------------------------------------------------

    public function testUrlSignalsAreNotHtmlEncoded(): void
    {
        $landing = 'https://boutique.test/fr/?utm_source=marques-de-france&utm_campaign=test';
        $referring = 'https://www.marques-de-france.fr/marque/x?a=1&b=2';

        $_COOKIE['mdf_landing_site'] = $landing;
        $_COOKIE['mdf_referring_site'] = $referring;

        $result = (new AttributionService())->collectSignals();

        // "&" must survive: these values are stored and sent to the Hub as data,
        // never rendered as markup.
        self::assertSame($landing, $result['landing_site']);
        self::assertSame($referring, $result['referring_site']);
        self::assertStringNotContainsString('&amp;', $result['landing_site']);
    }

    public function testUrlSignalsComingFromTheSessionKeepTheirSeparators(): void
    {
        $landing = 'https://boutique.test/fr/?a=1&b=2';
        $this->givenShopSession(['mdf_landing_site' => $landing]);

        self::assertSame($landing, (new AttributionService())->collectSignals()['landing_site']);
    }

    /**
     * @dataProvider unsafeUrlProvider
     */
    public function testNonHttpUrlSignalsAreRejected(string $value): void
    {
        $_COOKIE['mdf_referring_site'] = $value;

        $result = (new AttributionService())->collectSignals();

        self::assertSame('', $result['referring_site']);
        self::assertSame('unknown', $result['source']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function unsafeUrlProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html;base64,PHNjcmlwdD4='],
            'protocol relative' => ['//marques-de-france.fr/x'],
            'bare token' => ['marques-de-france.fr'],
        ];
    }

    public function testAngleBracketsAreStrippedFromUrlSignals(): void
    {
        $_COOKIE['mdf_landing_site'] = 'https://boutique.test/fr/?q=<script>alert(1)</script>';

        $landingSite = (new AttributionService())->collectSignals()['landing_site'];

        self::assertStringNotContainsString('<', $landingSite);
        self::assertStringNotContainsString('>', $landingSite);
    }

    public function testNonUrlSignalsAreStillHtmlEscaped(): void
    {
        $_COOKIE['mdf_utm_campaign'] = 'marques-de-france "été" <b>';

        $utmCampaign = (new AttributionService())->collectSignals()['utm_campaign'];

        self::assertStringNotContainsString('<b>', $utmCampaign);
        self::assertStringContainsString('&quot;', $utmCampaign);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $values
     */
    private function givenShopSession(array $values = []): void
    {
        $context = new \Context();
        $context->cookie = new \Cookie();

        foreach ($values as $key => $value) {
            $context->cookie->__set($key, $value);
        }

        \Context::$instance = $context;
    }
}
