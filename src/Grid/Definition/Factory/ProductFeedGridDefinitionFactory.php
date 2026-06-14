<?php

declare(strict_types=1);

namespace Mdfcforps\Grid\Definition\Factory;

use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ImageColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShopBundle\Form\Admin\Type\NumberMinMaxFilterType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Defines the "Products in Feed" grid (SERVERLIST default view).
 */
final class ProductFeedGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    protected function getId(): string
    {
        return 'product_feed';
    }

    protected function getName(): string
    {
        return $this->trans('Products in Feed', [], 'Modules.Mdfcforps.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add((new ImageColumn('image'))
                ->setName($this->trans('Photo', [], 'Admin.Global'))
                ->setOptions([
                    'src_field' => 'image',
                ])
            )
            ->add((new HtmlColumn('name'))
                ->setName($this->trans('Name', [], 'Admin.Global'))
                ->setOptions([
                    'field' => 'linked_name',
                    'sortable' => true,
                ])
            )
            ->add((new DataColumn('brand'))
                ->setName($this->trans('Brand', [], 'Admin.Catalog.Feature'))
                ->setOptions(['field' => 'brand'])
            )
            ->add((new DataColumn('reference'))
                ->setName($this->trans('Reference', [], 'Admin.Catalog.Feature'))
                ->setOptions(['field' => 'reference'])
            )
            ->add((new DataColumn('price'))
                ->setName($this->trans('Price', [], 'Admin.Global'))
                ->setOptions([
                    'field'    => 'price',
                    'sortable' => true,
                ])
            )
            ->add((new HtmlColumn('availability'))
                ->setName($this->trans('Availability', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field'    => 'availability',
                    'sortable' => true,
                ])
            )
            ->add((new HtmlColumn('status_badge'))
                ->setName($this->trans('Status', [], 'Admin.Global'))
                ->setOptions([
                    'field'    => 'status_badge',
                    'sortable' => false,
                ])
            );
    }

    protected function getFilters(): FilterCollection
    {
        return (new FilterCollection())
            ->add((new Filter('name', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Search name', [], 'Admin.Catalog.Help')],
                ])
                ->setAssociatedColumn('name')
            )
            ->add((new Filter('brand', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Search brand', [], 'Modules.Mdfcforps.Admin')],
                ])
                ->setAssociatedColumn('brand')
            )
            ->add((new Filter('reference', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Search reference', [], 'Admin.Catalog.Help')],
                ])
                ->setAssociatedColumn('reference')
            )
            ->add((new Filter('availability', ChoiceType::class))
                ->setTypeOptions([
                    'required' => false,
                    'placeholder' => $this->trans('All', [], 'Admin.Global'),
                    'choices' => [
                        $this->trans('In stock', [], 'Modules.Mdfcforps.Admin') => 'in_stock',
                        $this->trans('Out of stock but allow orders', [], 'Modules.Mdfcforps.Admin') => 'out_of_stock_allow_orders',
                        $this->trans('Out of stock', [], 'Modules.Mdfcforps.Admin') => 'out_of_stock',
                    ],
                ])
                ->setAssociatedColumn('availability')
            )
            ->add((new Filter('price', NumberMinMaxFilterType::class))
                ->setTypeOptions([
                    'required' => false,
                ])
                ->setAssociatedColumn('price')
            );
    }
}
