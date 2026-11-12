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

use Mdfcforps\Repository\CartAttributionRepository;

/**
 * Freezes the current visitor's attribution onto their cart, while it is still
 * readable.
 *
 * This is the PrestaShop answer to a problem WooCommerce does not have. There,
 * attach_to_order() stamps attribution into order meta on
 * woocommerce_checkout_create_order — inside the customer's own request, before the
 * payment redirect. PrestaShop creates its order after the redirect, so by the time
 * actionValidateOrder fires the cookies may be gone entirely: a payment provider's
 * server-to-server IPN carries none, and a cross-site POST return causes the browser
 * to withhold the SameSite=Lax cookies this module writes. The cart is the earliest
 * durable anchor that survives both, and $order->id_cart links it back afterwards.
 *
 * The gate below is ordered so that an unattributed visitor — which is nearly all
 * traffic — costs zero database queries. Only a cart that already carries a Marques
 * de France attribution is ever written.
 */
class CartAttributionFreezer
{
    /**
     * Carts already handled in THIS request. actionObjectCartUpdateAfter genuinely
     * fires several times in one checkout request; this makes all but the first free.
     *
     * @var array<int, bool>
     */
    private static $handledThisRequest = [];

    /** @var AttributionService */
    private $attributionService;

    /** @var CartAttributionRepository */
    private $repository;

    public function __construct(
        ?AttributionService $attributionService = null,
        ?CartAttributionRepository $repository = null
    ) {
        $this->attributionService = $attributionService ?? new AttributionService();
        $this->repository = $repository ?? new CartAttributionRepository();
    }

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    /**
     * Freeze the attribution of the given cart, or of the context cart when omitted.
     *
     * Never throws: this runs on cart mutations, on storefront renders and inside the
     * tracker AJAX endpoint, none of which may break because attribution bookkeeping
     * failed. A missed freeze is recoverable — the order confirmation page catches it.
     */
    public function freeze(?int $idCart = null): void
    {
        try {
            $idCart = $idCart ?? $this->contextCartId();

            if (!$this->shouldFreeze($idCart)) {
                return;
            }

            $this->repository->freeze($idCart, $this->attributionService->collectSignals());

            self::$handledThisRequest[$idCart] = true;
            $this->attributionService->stampCookie([
                AttributionService::CART_FROZEN_KEY => (string) $idCart,
            ]);
        } catch (\Throwable $e) {
            // Swallowed on purpose — see the method docblock.
        }
    }

    /**
     * Freeze the cart's attribution again, replacing the frozen copy: a campaign's
     * click code arrived after the cart was frozen with an older one.
     *
     * Never throws, like freeze().
     */
    public function refreeze(?int $idCart = null): void
    {
        try {
            $idCart = $idCart ?? $this->contextCartId();
            if ($idCart <= 0) {
                return;
            }

            $this->repository->forget($idCart);
            unset(self::$handledThisRequest[$idCart]);
            $this->attributionService->stampCookie([AttributionService::CART_FROZEN_KEY => '']);
        } catch (\Throwable $e) {
            // Swallowed on purpose — see freeze().
            return;
        }

        $this->freeze($idCart);
    }

    // -----------------------------------------------------------------------
    // Decision
    // -----------------------------------------------------------------------

    /**
     * Whether this cart still needs its attribution frozen.
     *
     * Touches no database: every branch reads cookies, the PrestaShop session or
     * request-local state only. Ordered cheapest-first, and the exit taken by the
     * overwhelming majority of visitors — "carries no Marques de France attribution"
     * — is reached without a single query.
     */
    public function shouldFreeze(int $idCart): bool
    {
        if ($idCart <= 0) {
            return false;
        }

        if (isset(self::$handledThisRequest[$idCart])) {
            return false;
        }

        if (!$this->attributionService->isMdfAttributed($this->attributionService->collectSignals())) {
            return false;
        }

        // Already frozen earlier in this session. Without this the tracker AJAX, which
        // fires on every page view of an attributed visit, would issue a redundant
        // INSERT IGNORE each time.
        $memo = $this->attributionService->readSignal(
            AttributionService::CART_FROZEN_KEY,
            AttributionService::CART_FROZEN_KEY
        );

        return (int) $memo !== $idCart;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Id of the context cart, or 0 when there is none yet.
     *
     * Never throws: CLI, cron and webservice contexts have no cart, and the caller
     * treats 0 as "nothing to freeze".
     */
    private function contextCartId(): int
    {
        if (!class_exists('\Context')) {
            return 0;
        }

        try {
            $context = \Context::getContext();
            $cart = ($context instanceof \Context) ? ($context->cart ?? null) : null;

            return is_object($cart) ? (int) ($cart->id ?? 0) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @internal test seam — the request memo is static and would otherwise leak
     *           between test cases
     */
    public static function resetForTests(): void
    {
        self::$handledThisRequest = [];
    }
}
