<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Grid\Definition\Factory;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn;
use Mdfcforps\Grid\Column\Type\HtmlColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\NumberMinMaxFilterType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Defines the "Sales" grid.
 */
final class SalesGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    protected function getId(): string
    {
        return 'sales';
    }

    protected function getName(): string
    {
        return $this->trans('Attributed Sales', [], 'Modules.Mdfcforps.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        $columns = (new ColumnCollection())
            ->add(
                (new HtmlColumn('order_reference'))
                ->setName($this->trans('Reference', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'order_reference_display',
                    'sortable' => true,
                ])
            )
            ->add(
                (new DataColumn('amount'))
                ->setName($this->trans('Amount', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'amount_display',
                    'sortable' => true,
                ])
            );

        // Commission is only relevant for stores enrolled in the MDF affiliation
        // program; the flag is cached from the Hub on the last sales fetch.
        if (\Configuration::get('MDFCFORPS_AFFILIATION_ACTIVE') === '1') {
            $columns->add(
                (new DataColumn('commission'))
                ->setName($this->trans('Commission', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'commission_display',
                    'sortable' => true,
                ])
            );
        }

        return $columns
            ->add(
                (new HtmlColumn('source'))
                ->setName($this->trans('Source', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'source_badge',
                    'sortable' => false,
                ])
            )
            ->add(
                (new HtmlColumn('status'))
                ->setName($this->trans('Status', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'status_badge',
                    'sortable' => true,
                ])
            )
            ->add(
                (new DataColumn('created_at'))
                ->setName($this->trans('Date', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'created_at',
                    'sortable' => true,
                ])
            );
    }

    protected function getFilters(): FilterCollection
    {
        $filters = new FilterCollection();

        $filters
            ->add(
                (new Filter('order_reference', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr' => ['placeholder' => $this->trans('Search reference', [], 'Modules.Mdfcforps.Admin')],
                ])
                ->setAssociatedColumn('order_reference')
            )
            ->add(
                (new Filter('amount', NumberMinMaxFilterType::class))
                ->setTypeOptions([
                    'required' => false,
                ])
                ->setAssociatedColumn('amount')
            )
            ->add(
                (new Filter('source', ChoiceType::class))
                ->setTypeOptions([
                    'required' => false,
                    'placeholder' => $this->trans('All', [], 'Admin.Global'),
                    // Exactly the values AttributionService::resolveSource() writes
                    // (see AttributionService::MDF_SOURCES), in its resolution order.
                    // The previous list was the Shopify connector's vocabulary —
                    // cart_attribute, landing_site_ref, referring_site… — which this
                    // module never produces, so every one of those filters returned an
                    // empty grid while matching sales sat right there.
                    //
                    // Labels are the raw values on purpose: they are the same tokens
                    // shown in the Source column, so the filter and the grid read alike.
                    'choice_translation_domain' => false,
                    'choices' => [
                        'mdf_click' => 'mdf_click',
                        'mdf_landing_ref' => 'mdf_landing_ref',
                        'mdf_utm' => 'mdf_utm',
                        'mdf_referrer' => 'mdf_referrer',
                    ],
                ])
                ->setAssociatedColumn('source')
            )
            ->add(
                (new Filter('status', ChoiceType::class))
                ->setTypeOptions([
                    'required' => false,
                    'placeholder' => $this->trans('All', [], 'Admin.Global'),
                    'choices' => [
                        $this->trans('confirmed', [], 'Modules.Mdfcforps.Admin') => 'confirmed',
                        $this->trans('cancelled', [], 'Modules.Mdfcforps.Admin') => 'cancelled',
                        $this->trans('refunded', [], 'Modules.Mdfcforps.Admin') => 'refunded',
                        $this->trans('pending', [], 'Modules.Mdfcforps.Admin') => 'pending',
                    ],
                ])
                ->setAssociatedColumn('status')
            );

        return $filters;
    }
}
