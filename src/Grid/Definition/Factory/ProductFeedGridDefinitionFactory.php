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
                ->setName($this->trans('Product name', [], 'Admin.Catalog.Feature'))
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
            ->add((new HtmlColumn('availability'))
                ->setName($this->trans('Availability', [], 'Admin.Catalog.Feature'))
                ->setOptions([
                    'field'    => 'availability',
                    'sortable' => true,
                ])
            )
            ->add((new DataColumn('price'))
                ->setName($this->trans('Price', [], 'Admin.Global'))
                ->setOptions([
                    'field'    => 'price',
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
                    'attr'     => ['placeholder' => $this->trans('Name', [], 'Admin.Global')],
                ])
                ->setAssociatedColumn('name')
            )
            ->add((new Filter('brand', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Brand', [], 'Admin.Catalog.Feature')],
                ])
                ->setAssociatedColumn('brand')
            )
            ->add((new Filter('reference', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Reference', [], 'Admin.Catalog.Feature')],
                ])
                ->setAssociatedColumn('reference')
            )
            ->add((new Filter('availability', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Availability', [], 'Admin.Catalog.Feature')],
                ])
                ->setAssociatedColumn('availability')
            )
            ->add((new Filter('price', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Price', [], 'Admin.Global')],
                ])
                ->setAssociatedColumn('price')
            )
            ->add((new Filter('status', TextType::class))
                ->setTypeOptions([
                    'required' => false,
                    'attr'     => ['placeholder' => $this->trans('Status', [], 'Admin.Global')],
                ])
                ->setAssociatedColumn('status_badge')
            );
    }
}
