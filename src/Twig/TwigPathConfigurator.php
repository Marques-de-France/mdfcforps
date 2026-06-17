<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Twig;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Twig\Loader\FilesystemLoader;

/**
 * Registers the module's views directory under the @PrestaShop Twig namespace
 * so custom column type templates (e.g. mdfcforps_html.html.twig) are found
 * by the grid's Twig resolver.
 *
 * Tagged as kernel.event_listener at high priority so the path is registered
 * before any grid rendering takes place.
 */
final class TwigPathConfigurator
{
    /** @var FilesystemLoader */
    private $loader;

    /** @var string */
    private $viewsPath;

    /** @var bool */
    private static $registered = false;

    public function __construct(FilesystemLoader $loader, string $viewsPath)
    {
        $this->loader = $loader;
        $this->viewsPath = $viewsPath;
    }

    /**
     * Called on kernel.request — adds the module views path to the @PrestaShop Twig namespace.
     * The path is prepended so module templates override core ones when names match.
     *
     * Note: no type-hint on $event — GetResponseEvent was removed in Symfony 5 (PS9),
     * replaced by RequestEvent. The event object is unused here so no type hint is safest.
     *
     * @param object $event
     */
    public function onKernelRequest($event): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        if (!is_dir($this->viewsPath)) {
            return;
        }

        try {
            $this->loader->prependPath($this->viewsPath, 'PrestaShop');
        } catch (\Twig\Error\LoaderError $e) {
            // Silently ignore if path registration fails (e.g., directory doesn't exist)
        }
    }
}
