<?php

declare(strict_types=1);

namespace Mdfcforps\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn;
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
        return (new ColumnCollection())
            ->add((new DataColumn('order_reference'))
                ->setName($this->trans('Reference', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'order_reference',
                    'sortable' => true,
                ])
            )
            ->add((new DataColumn('amount'))
                ->setName($this->trans('Amount', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'amount_display',
                    'sortable' => true,
                ])
            )
            ->add((new HtmlColumn('source'))
                ->setName($this->trans('Source', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'source_badge',
                    'sortable' => false,
                ])
            )
            ->add((new HtmlColumn('status'))
                ->setName($this->trans('Status', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'status_badge',
                    'sortable' => true,
                ])
            )
            ->add((new DataColumn('created_at'))
                ->setName($this->trans('Date', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field' => 'created_at',
                    'sortable' => true,
                ])
            );
    }

    protected function getFilters(): FilterCollection
    {
        return (new FilterCollection())
            ->add((new Filter('order_reference', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr' => ['placeholder' => $this->trans('Search reference', [], 'Modules.Mdfcforps.Admin')],
                ])
                ->setAssociatedColumn('order_reference')
            )
            ->add((new Filter('amount', NumberMinMaxFilterType::class))
                ->setTypeOptions([
                    'required' => false,
                ])
                ->setAssociatedColumn('amount')
            )
            ->add((new Filter('source', ChoiceType::class))
                ->setTypeOptions([
                    'required' => false,
                    'placeholder' => $this->trans('All', [], 'Admin.Global'),
                    'choices' => [
                        $this->trans('utm', [], 'Modules.Mdfcforps.Admin') => 'utm',
                        $this->trans('cart_attribute', [], 'Modules.Mdfcforps.Admin') => 'cart_attribute',
                        $this->trans('landing_site_ref', [], 'Modules.Mdfcforps.Admin') => 'landing_site_ref',
                        $this->trans('landing_site', [], 'Modules.Mdfcforps.Admin') => 'landing_site',
                        $this->trans('referring_site', [], 'Modules.Mdfcforps.Admin') => 'referring_site',
                        $this->trans('unknown', [], 'Modules.Mdfcforps.Admin') => 'unknown',
                    ],
                ])
                ->setAssociatedColumn('source')
            )
            ->add((new Filter('status', ChoiceType::class))
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
    }
}
