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
 * Defines the "Product Catalog" grid used in the manage panel.
 * Includes a checkbox column (in_feed toggle) and all product metadata columns.
 */
final class ProductCatalogGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    protected function getId(): string
    {
        return 'product_catalog';
    }

    protected function getName(): string
    {
        return $this->trans('Select products', [], 'Modules.Mdfcforps.Admin');
    }

    protected function getColumns(): ColumnCollection
    {
        return (new ColumnCollection())
            ->add((new HtmlColumn('checkbox_html'))
                ->setName('')
                ->setOptions([
                    'field'    => 'checkbox_html',
                    'sortable' => false,
                ])
            )
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
                ->setName($this->trans('Availability', [], 'Admin.Catalog.Feature'))
                ->setOptions([
                    'field'    => 'availability',
                    'sortable' => true,
                ])
            )
            ->add((new HtmlColumn('allow_orders_badge'))
                ->setName($this->trans('Allow orders', [], 'Modules.Mdfcforps.Admin'))
                ->setOptions([
                    'field'    => 'allow_orders_badge',
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
                        $this->trans('In stock', [], 'Admin.Catalog.Feature') => 'in_stock',
                        $this->trans('Out of stock', [], 'Admin.Catalog.Feature') => 'out_of_stock',
                    ],
                ])
                ->setAssociatedColumn('availability')
            )
            ->add((new Filter('allow_orders', ChoiceType::class))
                ->setTypeOptions([
                    'required' => false,
                    'placeholder' => $this->trans('All', [], 'Admin.Global'),
                    'choices' => [
                        $this->trans('Allow orders', [], 'Modules.Mdfcforps.Admin') => 'allow',
                        $this->trans('Deny orders', [], 'Modules.Mdfcforps.Admin') => 'deny',
                    ],
                ])
                ->setAssociatedColumn('allow_orders_badge')
            )
            ->add((new Filter('price', NumberMinMaxFilterType::class))
                ->setTypeOptions([
                    'required' => false,
                ])
                ->setAssociatedColumn('price')
            );
    }
}
