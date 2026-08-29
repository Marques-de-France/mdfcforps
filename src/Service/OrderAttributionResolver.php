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
 * Resolves the attribution of an order from the two stores that can hold it: the
 * cookies of the current request, and the copy frozen against the cart before payment.
 *
 * The decision itself is AttributionService::preferAttributed(), which is pure and
 * unit-tested. This class only supplies it with the two inputs.
 */
class OrderAttributionResolver
{
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

    /**
     * @return array<string, string> collectSignals()-shaped
     */
    public function resolveForOrder(\Order $order): array
    {
        $live = $this->attributionService->collectSignals();

        // Fast path: an ordinary on-site checkout resolves here, with exactly the zero
        // database queries the pre-1.5.0 implementation used. The lookup below is only
        // ever paid for an order the module would otherwise have discarded outright.
        if ($this->attributionService->isMdfAttributed($live)) {
            return $live;
        }

        // Every order of a split cart shares the same id_cart, so each sibling
        // resolves against the same frozen row.
        $idCart = (int) ($order->id_cart ?? 0);
        $frozen = $idCart > 0 ? $this->repository->findByCartId($idCart) : null;

        return AttributionService::preferAttributed($live, $frozen);
    }
}
