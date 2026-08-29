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
use Mdfcforps\Service\OrderAttributionResolver;
use PHPUnit\Framework\TestCase;

final class OrderAttributionResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $this->givenShopSession();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
        \Context::resetForTests();
        \Tools::$shopDomain = 'boutique.test';
    }

    public function testAnOnSiteCheckoutNeverConsultsTheFrozenRow(): void
    {
        $_COOKIE['mdf_utm_source'] = 'marques-de-france';

        $repository = $this->givenFrozenAttribution(null);
        $resolved = (new OrderAttributionResolver(null, $repository))
            ->resolveForOrder(new \Order(1001, 55));

        // The cost guarantee: an order that already resolves from cookies pays for no
        // extra query, so shops that work today are unaffected.
        self::assertSame([], $repository->lookups);
        self::assertSame('mdf_utm', $resolved['source']);
    }

    public function testRecoversAttributionFromTheCartWhenNoCookieSurvived(): void
    {
        // No mdf_* cookies at all: a payment provider's server-to-server IPN, or a
        // cross-site POST return on which the browser withheld them.
        $repository = $this->givenFrozenAttribution([
            'source' => 'mdf_click',
            'click_id' => 'CLICK_12345',
            'utm_source' => 'marques-de-france',
            'landing_site' => 'https://boutique.test/?utm_source=marques-de-france',
        ]);

        $resolved = (new OrderAttributionResolver(null, $repository))
            ->resolveForOrder(new \Order(1001, 55));

        self::assertSame([55], $repository->lookups);
        self::assertSame('mdf_click', $resolved['source']);
        self::assertSame('CLICK_12345', $resolved['click_id']);
    }

    public function testStaysUnattributedWhenTheCartHasNothingFrozen(): void
    {
        $repository = $this->givenFrozenAttribution(null);

        $resolved = (new OrderAttributionResolver(null, $repository))
            ->resolveForOrder(new \Order(1001, 55));

        self::assertSame([55], $repository->lookups);
        self::assertSame('unknown', $resolved['source']);
    }

    public function testDoesNotLookUpAnOrderWithoutACart(): void
    {
        $repository = $this->givenFrozenAttribution(null);

        $resolved = (new OrderAttributionResolver(null, $repository))
            ->resolveForOrder(new \Order(1001, 0));

        self::assertSame([], $repository->lookups);
        self::assertSame('unknown', $resolved['source']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, string>|null $frozen what findByCartId() should return
     */
    private function givenFrozenAttribution(?array $frozen): CartAttributionRepository
    {
        return new class($frozen) extends CartAttributionRepository {
            /** @var array<int, int> cart ids this repository was asked about */
            public $lookups = [];

            /** @var array<string, string>|null */
            private $frozen;

            /**
             * @param array<string, string>|null $frozen
             */
            public function __construct(?array $frozen)
            {
                $this->frozen = $frozen;
            }

            public function findByCartId(int $idCart): ?array
            {
                $this->lookups[] = $idCart;

                return $this->frozen;
            }
        };
    }

    private function givenShopSession(): void
    {
        $context = new \Context();
        $context->cookie = new \Cookie();
        \Context::$instance = $context;
    }
}
