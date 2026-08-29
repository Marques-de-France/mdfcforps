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

use Mdfcforps\Repository\CartAttributionRepository;
use Mdfcforps\Service\AttributionService;
use Mdfcforps\Service\CartAttributionFreezer;
use PHPUnit\Framework\TestCase;

final class CartAttributionFreezerTest extends TestCase
{
    protected function setUp(): void
    {
        CartAttributionFreezer::resetForTests();
        $this->givenShopSession();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        CartAttributionFreezer::resetForTests();
        \Context::resetForTests();
        \Tools::$shopDomain = 'boutique.test';
    }

    // -----------------------------------------------------------------------
    // shouldFreeze() — the zero-query gate
    // -----------------------------------------------------------------------

    public function testDoesNotFreezeAVisitorWithoutAttribution(): void
    {
        // The exit taken by nearly all traffic, and the reason this hook can sit on
        // every cart mutation: it is reached without touching the database.
        self::assertFalse((new CartAttributionFreezer())->shouldFreeze(42));
    }

    public function testDoesNotFreezeWithoutACart(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';

        self::assertFalse((new CartAttributionFreezer())->shouldFreeze(0));
    }

    public function testFreezesAnAttributedVisitor(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';

        self::assertTrue((new CartAttributionFreezer())->shouldFreeze(42));
    }

    public function testFreezesOnUtmAttributionToo(): void
    {
        $_COOKIE['mdf_utm_source'] = 'marques-de-france';

        self::assertTrue((new CartAttributionFreezer())->shouldFreeze(42));
    }

    public function testDoesNotFreezeTwiceForTheSameCartInOneRequest(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';

        $freezer = new CartAttributionFreezer(null, $this->givenRecordingRepository());

        $freezer->freeze(42);

        self::assertFalse($freezer->shouldFreeze(42));
    }

    public function testDoesNotFreezeACartAlreadyMemoisedInTheSession(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';
        $this->givenShopSession([AttributionService::CART_FROZEN_KEY => '42']);

        self::assertFalse((new CartAttributionFreezer())->shouldFreeze(42));
    }

    public function testStillFreezesADifferentCartFromTheMemoisedOne(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';
        $this->givenShopSession([AttributionService::CART_FROZEN_KEY => '41']);

        self::assertTrue((new CartAttributionFreezer())->shouldFreeze(42));
    }

    // -----------------------------------------------------------------------
    // freeze()
    // -----------------------------------------------------------------------

    public function testWritesOnceAcrossRepeatedCalls(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';

        $repository = $this->givenRecordingRepository();
        $freezer = new CartAttributionFreezer(null, $repository);

        $freezer->freeze(42);
        $freezer->freeze(42);
        $freezer->freeze(42);

        self::assertCount(1, $repository->calls);
        self::assertSame(42, $repository->calls[0]['id_cart']);
        self::assertSame('mdf_click', $repository->calls[0]['attribution']['source']);
    }

    public function testMemoisesTheFrozenCartInTheSession(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';

        (new CartAttributionFreezer(null, $this->givenRecordingRepository()))->freeze(42);

        self::assertSame(
            '42',
            \Context::getContext()->cookie->__get(AttributionService::CART_FROZEN_KEY)
        );
    }

    public function testSwallowsARepositoryFailure(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';

        $repository = new class() extends CartAttributionRepository {
            public function freeze(int $idCart, array $attribution): bool
            {
                throw new \RuntimeException('database is down');
            }
        };

        // A freeze failure must never surface: it runs on cart mutations and page
        // renders, and the order confirmation page is the backstop.
        (new CartAttributionFreezer(null, $repository))->freeze(42);

        self::assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * A repository that records instead of hitting the database. Overriding every
     * method the freezer calls means \Db is never loaded, which is what lets this
     * suite keep working without a Db stub.
     */
    private function givenRecordingRepository(): CartAttributionRepository
    {
        return new class() extends CartAttributionRepository {
            /** @var array<int, array<string, mixed>> */
            public $calls = [];

            public function freeze(int $idCart, array $attribution): bool
            {
                $this->calls[] = ['id_cart' => $idCart, 'attribution' => $attribution];

                return true;
            }
        };
    }

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
