<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Grid\Query;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Builds SQL that reproduces Product::priceCalculation() for grid sorting and filtering.
 *
 * The grids display prices resolved in PHP by {@see \Mdfcforps\Service\PriceResolver},
 * which is authoritative because it is the same code the RSS feed uses. But sorting and
 * pagination have to happen in SQL, so the ordering key must be computed by the database.
 * This class emits that expression, mirroring core's order of operations:
 *
 *   1. base price            product_shop.price, falling back to product.price
 *   2. specific price        a `price` column >= 0 overrides the base price outright —
 *                            core applies this on both the regular and the reduced pass
 *   3. tax                   rate of the most specific matching ps_tax_rule
 *   4. ecotax                added after tax, only when PS_USE_ECOTAX is enabled
 *   5. reduction             percentage applies to the taxed price; amount is grossed up
 *                            when reduction_tax = 0; subtracted only on the reduced pass
 *
 * The specific price row is selected exactly as SpecificPrice::getSpecificPrice() does,
 * including its date window, from_quantity rule and the priority score derived from
 * PS_SPECIFIC_PRICE_PRIORITIES.
 *
 * Deliberate simplifications, none of which affect relative ordering:
 *  - group reduction (Group::getReductionByIdGroup) is skipped: core applies it to the
 *    regular and reduced price alike, so it scales both sides equally;
 *  - currency conversion is skipped: the back office runs in the default currency;
 *  - when several tax rules combine (behavior 1/2), only the most specific rate is used.
 *
 * Requires the host query to expose `p` (product) and `ps` (product_shop) aliases.
 */
final class ProductPriceSqlBuilder
{
    /** Fields core allows to carry a specific-price priority weight. */
    private const PRIORITY_FIELDS = ['id_shop', 'id_currency', 'id_country', 'id_group', 'id_customer'];

    /** @var string */
    private $dbPrefix;

    /** @var array<string, int|string> */
    private $context;

    public function __construct(string $dbPrefix)
    {
        $this->dbPrefix = $dbPrefix;
        $this->context = $this->resolveContext();
    }

    /**
     * Attach the specific-price and tax-rule joins, and bind every parameter the
     * price expressions depend on.
     */
    public function applyJoins(QueryBuilder $qb): void
    {
        $qb->leftJoin(
            'p',
            $this->dbPrefix . 'specific_price',
            'mdf_sp',
            'mdf_sp.id_specific_price = (' . $this->buildSpecificPriceSubQuery() . ')'
        );

        $qb->leftJoin(
            'p',
            $this->dbPrefix . 'tax_rule',
            'mdf_tr',
            'mdf_tr.id_tax_rule = (' . $this->buildTaxRuleSubQuery() . ')'
        );

        $qb->leftJoin(
            'mdf_tr',
            $this->dbPrefix . 'tax',
            'mdf_tax',
            'mdf_tax.id_tax = mdf_tr.id_tax AND mdf_tax.active = 1'
        );

        foreach ($this->getParameters() as $name => $value) {
            $qb->setParameter($name, $value);
        }
    }

    /**
     * Tax-included catalog price, before any specific-price reduction.
     */
    public function getRegularExpression(): string
    {
        return 'ROUND(' . $this->buildTaxedPrice() . ', 2)';
    }

    /**
     * Tax-included price actually charged today, reductions applied.
     */
    public function getEffectiveExpression(): string
    {
        $taxed = $this->buildTaxedPrice();

        return 'ROUND(GREATEST(' . $taxed . ' - ' . $this->buildReduction($taxed) . ', 0), 2)';
    }

    /**
     * @return array<string, int|string>
     */
    public function getParameters(): array
    {
        return $this->context;
    }

    // -----------------------------------------------------------------------
    // Expression fragments
    // -----------------------------------------------------------------------

    /**
     * Base price with the specific-price override applied, then taxed, then ecotax.
     */
    private function buildTaxedPrice(): string
    {
        $base = '(CASE WHEN mdf_sp.id_specific_price IS NOT NULL AND mdf_sp.price >= 0'
            . ' THEN mdf_sp.price'
            . ' ELSE COALESCE(ps.price, p.price, 0) END)';

        $price = '(' . $base . ' * ' . $this->buildTaxMultiplier() . ')';

        // Ecotax is taxed with its own rules group, which is a single shop-wide value —
        // resolved once in PHP and bound, rather than joined per row.
        if ((float) $this->context['mdf_ecotax_multiplier'] > 0) {
            $price = '(' . $price . ' + COALESCE(ps.ecotax, p.ecotax, 0) * :mdf_ecotax_multiplier)';
        }

        return $price;
    }

    private function buildTaxMultiplier(): string
    {
        return '(1 + COALESCE(mdf_tax.rate, 0) / 100)';
    }

    /**
     * @param string $taxedPrice expression the percentage reduction applies to
     */
    private function buildReduction(string $taxedPrice): string
    {
        return '(CASE'
            . ' WHEN mdf_sp.id_specific_price IS NULL THEN 0'
            . ' WHEN mdf_sp.reduction_type = \'percentage\' THEN ' . $taxedPrice . ' * mdf_sp.reduction'
            . ' WHEN mdf_sp.reduction_type = \'amount\' THEN mdf_sp.reduction *'
                . ' (CASE WHEN mdf_sp.reduction_tax = 1 THEN 1 ELSE ' . $this->buildTaxMultiplier() . ' END)'
            . ' ELSE 0 END)';
    }

    // -----------------------------------------------------------------------
    // Correlated sub-queries
    // -----------------------------------------------------------------------

    /**
     * Mirrors SpecificPrice::getSpecificPrice() for an anonymous visitor, quantity 1,
     * no cart and no combination — the same conditions PriceResolver prices under.
     */
    private function buildSpecificPriceSubQuery(): string
    {
        return 'SELECT mdf_sp2.id_specific_price'
            . ' FROM ' . $this->dbPrefix . 'specific_price mdf_sp2'
            . ' WHERE mdf_sp2.id_product IN (0, p.id_product)'
            . ' AND mdf_sp2.id_product_attribute = 0'
            . ' AND mdf_sp2.id_customer = 0'
            . ' AND mdf_sp2.id_cart = 0'
            . ' AND mdf_sp2.id_shop IN (0, :mdf_prio_id_shop)'
            . ' AND mdf_sp2.id_currency IN (0, :mdf_prio_id_currency)'
            . ' AND mdf_sp2.id_country IN (0, :mdf_prio_id_country)'
            . ' AND mdf_sp2.id_group IN (0, :mdf_prio_id_group)'
            // Core writes these bounds as (`from` = '0000-00-00 00:00:00' OR now >= `from`).
            // We cannot repeat that literal: the Doctrine connection runs in strict mode,
            // where '0000-00-00 00:00:00' is rejected outright ("Incorrect DATETIME value").
            // An unset `from` sorts below every real date, so the comparison alone already
            // covers it; an unset `to` does not, so it is tested with YEAR() instead.
            . ' AND :mdf_now >= mdf_sp2.`from`'
            . ' AND (YEAR(mdf_sp2.`to`) = 0 OR :mdf_now <= mdf_sp2.`to`)'
            . ' AND IF(mdf_sp2.from_quantity > 1, mdf_sp2.from_quantity, 0) <= 1'
            . ' ORDER BY mdf_sp2.id_product_attribute DESC, mdf_sp2.id_cart DESC,'
                . ' mdf_sp2.from_quantity DESC, mdf_sp2.id_specific_price_rule ASC,'
                . ' ' . $this->buildPriorityScore() . ' DESC,'
                . ' mdf_sp2.`to` DESC, mdf_sp2.`from` DESC'
            . ' LIMIT 1';
    }

    /**
     * Reproduces SpecificPrice::_getScoreQuery(): each priority field contributes
     * 2^(k+1) when it matches, with k running over the reversed priority list.
     *
     * The field list comes from PS_SPECIFIC_PRICE_PRIORITIES, which is merchant-editable,
     * so it is whitelisted against PRIORITY_FIELDS before reaching the query.
     */
    private function buildPriorityScore(): string
    {
        $priorities = explode(';', (string) \Configuration::get('PS_SPECIFIC_PRICE_PRIORITIES'));
        // Core's getPriority() always prepends id_customer to the configured list.
        array_unshift($priorities, 'id_customer');

        $terms = [];
        // array_reverse re-indexes; core keeps the positional index even for entries it
        // skips, so the weights stay tied to the position, not to the emitted term count.
        foreach (array_reverse($priorities) as $k => $field) {
            $field = trim((string) $field);
            if ($field === '' || !in_array($field, self::PRIORITY_FIELDS, true)) {
                continue;
            }

            $terms[] = sprintf(
                'IF(mdf_sp2.`%s` = :mdf_prio_%s, %d, 0)',
                $field,
                $field,
                2 ** ($k + 1)
            );
        }

        if (empty($terms)) {
            return '0';
        }

        return '(' . implode(' + ', $terms) . ')';
    }

    /**
     * Mirrors TaxRulesTaxManager: most specific rule for the product's tax rules group,
     * the context country/state and postcode.
     */
    private function buildTaxRuleSubQuery(): string
    {
        return 'SELECT mdf_tr2.id_tax_rule'
            . ' FROM ' . $this->dbPrefix . 'tax_rule mdf_tr2'
            . ' INNER JOIN ' . $this->dbPrefix . 'tax_rules_group mdf_trg'
                . ' ON mdf_trg.id_tax_rules_group = mdf_tr2.id_tax_rules_group AND mdf_trg.active = 1'
            . ' WHERE mdf_tr2.id_tax_rules_group = COALESCE(ps.id_tax_rules_group, p.id_tax_rules_group, 0)'
            . ' AND mdf_tr2.id_country = :mdf_prio_id_country'
            . ' AND mdf_tr2.id_state IN (0, :mdf_tax_state)'
            . ' AND (:mdf_tax_zip BETWEEN mdf_tr2.zipcode_from AND mdf_tr2.zipcode_to'
                . ' OR (mdf_tr2.zipcode_to = 0 AND mdf_tr2.zipcode_from IN (0, :mdf_tax_zip)))'
            . ' ORDER BY mdf_tr2.zipcode_from DESC, mdf_tr2.zipcode_to DESC,'
                . ' mdf_tr2.id_state DESC, mdf_tr2.id_country DESC'
            . ' LIMIT 1';
    }

    // -----------------------------------------------------------------------
    // Context
    // -----------------------------------------------------------------------

    /**
     * Resolve the same shop/currency/country/group values getPriceStatic() would use,
     * so the SQL ordering lines up with the prices PriceResolver renders.
     *
     * @return array<string, int|string>
     */
    private function resolveContext(): array
    {
        $context = \Context::getContext();

        $idShop = ($context && $context->shop) ? (int) $context->shop->id : 1;
        $idCurrency = ($context && $context->currency && $context->currency->id)
            ? (int) $context->currency->id
            : (int) \Configuration::get('PS_CURRENCY_DEFAULT');

        // getPriceStatic() falls back to Address::initialize(), which uses the default
        // country when no address is in context — which is the case in the back office.
        $idCountry = ($context && $context->country && $context->country->id)
            ? (int) $context->country->id
            : (int) \Configuration::get('PS_COUNTRY_DEFAULT');

        $idGroup = (int) \Group::getCurrent()->id;

        return [
            'mdf_prio_id_shop' => $idShop,
            'mdf_prio_id_currency' => $idCurrency,
            'mdf_prio_id_country' => $idCountry,
            'mdf_prio_id_group' => $idGroup,
            // Anonymous pricing: core filters specific prices on id_customer = 0.
            'mdf_prio_id_customer' => 0,
            'mdf_tax_state' => 0,
            'mdf_tax_zip' => '0',
            // Core compares against a minute-truncated timestamp.
            'mdf_now' => date('Y-m-d H:i:00'),
            'mdf_ecotax_multiplier' => $this->resolveEcotaxMultiplier(),
        ];
    }

    /**
     * Ecotax is charged under its own tax rules group. Returns 0 when ecotax is
     * disabled, which drops the term from the expression entirely.
     */
    private function resolveEcotaxMultiplier(): float
    {
        if (!\Configuration::get('PS_USE_ECOTAX')) {
            return 0.0;
        }

        $idGroup = (int) \Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID');
        if ($idGroup <= 0) {
            return 1.0;
        }

        $rate = (float) \Db::getInstance()->getValue(
            'SELECT t.rate FROM ' . _DB_PREFIX_ . 'tax_rule tr'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'tax t ON t.id_tax = tr.id_tax AND t.active = 1'
            . ' WHERE tr.id_tax_rules_group = ' . $idGroup
            . ' AND tr.id_country = ' . (int) \Configuration::get('PS_COUNTRY_DEFAULT')
            . ' ORDER BY tr.id_state DESC LIMIT 1'
        );

        return 1 + $rate / 100;
    }
}
