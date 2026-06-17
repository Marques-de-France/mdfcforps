<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Grid\Column\Type;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Grid\Column\AbstractColumn;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Column that renders raw HTML content.
 * Compatible with both PS 1.7.8 and PS 8.
 * Returns type 'mdfcforps_html' so the module registers its own Twig template.
 */
final class HtmlColumn extends AbstractColumn
{
    public function getType()
    {
        return 'mdfcforps_html';
    }

    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setRequired(['field'])
            ->setDefaults(['clickable' => true])
            ->setAllowedTypes('field', 'string')
            ->setAllowedTypes('clickable', 'bool');
    }
}
