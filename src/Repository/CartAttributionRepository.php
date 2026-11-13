<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Attribution frozen against the cart, before payment.
 *
 * The PrestaShop counterpart of the WooCommerce plugin's attach_to_order(), which
 * stamps attribution into order meta on woocommerce_checkout_create_order. PrestaShop
 * has no equivalent pre-payment order object, so the cart is the earliest durable
 * anchor available: it exists before the payment redirect and survives it, and
 * $order->id_cart links the two afterwards.
 *
 * Rows are written only for carts that already carry a Marques de France attribution
 * — see CartAttributionFreezer — so this table stays small on any real shop.
 */
class CartAttributionRepository
{
    public const TABLE = 'mdfcforps_cart_attribution';

    /**
     * Signals whose value is a URL. pSQL() must be told to keep their punctuation,
     * exactly as SaleRepository::recordSale() does for the same three columns.
     *
     * @var array<int, string>
     */
    private const URL_COLUMNS = ['landing_site', 'referring_site', 'landing_ref'];

    /**
     * Columns carrying a signal, in the order the schema declares them.
     * Keys are the column names, values the collectSignals() key they map from.
     *
     * @var array<string, string>
     */
    private const SIGNAL_COLUMNS = [
        'attribution_source' => 'source',
        'click_id' => 'click_id',
        'landing_ref' => 'landing_ref',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_content' => 'utm_content',
        'utm_term' => 'utm_term',
        'landing_site' => 'landing_site',
        'referring_site' => 'referring_site',
    ];

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * First-touch write: the first attribution seen for a cart wins and is never
     * replaced.
     *
     * INSERT IGNORE against uq_id_cart rather than a read-then-write in PHP, so the
     * guarantee is enforced by MySQL and holds under the concurrent requests a busy
     * checkout produces (an AJAX stamp and a cart update can land together).
     *
     * @param array<string, string> $attribution collectSignals()-shaped
     */
    public function freeze(int $idCart, array $attribution): bool
    {
        if ($idCart <= 0) {
            return false;
        }

        $data = ['id_cart' => (int) $idCart];

        foreach (self::SIGNAL_COLUMNS as $column => $signalKey) {
            $value = (string) ($attribution[$signalKey] ?? '');

            $data[$column] = in_array($column, self::URL_COLUMNS, true)
                ? pSQL($value, true)
                : pSQL($value);
        }

        $data['created_at'] = date('Y-m-d H:i:s');

        return (bool) \Db::getInstance()->insert(
            self::TABLE,
            $data,
            false,
            true,
            \Db::INSERT_IGNORE
        );
    }

    /**
     * Drop a cart's frozen attribution, so that freeze() writes it again (a campaign's
     * click code replacing an older one, CartAttributionFreezer::refreeze()).
     */
    public function forget(int $idCart): bool
    {
        if ($idCart <= 0) {
            return false;
        }

        return (bool) \Db::getInstance()->delete(self::TABLE, 'id_cart = ' . (int) $idCart);
    }

    /**
     * Drop rows old enough that no order can still reference them.
     *
     * LIMIT so a first purge on a long-running shop can never hold locks for long;
     * the remainder drains over subsequent runs.
     *
     * @return int rows deleted
     */
    public function purgeOlderThan(int $days, int $limit = 1000): int
    {
        $days = max(1, $days);
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        $deleted = \Db::getInstance()->delete(
            self::TABLE,
            "created_at < '" . pSQL($cutoff) . "'",
            max(1, $limit)
        );

        return $deleted ? (int) \Db::getInstance()->Affected_Rows() : 0;
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns the frozen attribution in collectSignals() shape, so the caller can hand
     * it straight to isMdfAttributed() and SaleRepository::recordSale() with no
     * adapter in between.
     *
     * @return array<string, string>|null null when the cart carries no frozen attribution
     */
    public function findByCartId(int $idCart): ?array
    {
        if ($idCart <= 0) {
            return null;
        }

        $query = new \DbQuery();
        $query->select('*')
              ->from(self::TABLE)
              ->where('id_cart = ' . (int) $idCart);

        $row = \Db::getInstance()->getRow($query);

        if (!is_array($row) || empty($row)) {
            return null;
        }

        $signals = [];
        foreach (self::SIGNAL_COLUMNS as $column => $signalKey) {
            $signals[$signalKey] = (string) ($row[$column] ?? '');
        }

        return $signals;
    }
}
