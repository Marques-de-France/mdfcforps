<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Bootstrap;

if (!defined('_PS_VERSION_')) {
    exit;
}

// ---------------------------------------------------------------------------
// Early PSR-4 autoloader registration (runs on file include).
//
// On PrestaShop 1.7 (Symfony 3.4) the controller resolver calls class_exists()
// on the route's _controller class BEFORE fetching the matching service from the
// container. Routes therefore reference the controller by its real FQCN
// (Mdfcforps\Controller\Admin\FeedController::xxxAction), and that FQCN must be
// autoloadable by the time routing resolves the controller.
//
// This file is pulled in via the 'file:' directive of the kernel.request listener
// declared in config/services.yml at a priority higher than RouterListener, so the
// autoloader below is registered before controller resolution on every back-office
// request — including ZIP installs that ship without a Composer vendor/ directory.
// On PS 8/9 (Symfony 4.4/6.4) the container is consulted before class_exists, so this
// is a harmless, idempotent safety net there.
// ---------------------------------------------------------------------------
if (!defined('MDFCFORPS_AUTOLOAD_REGISTERED')) {
    define('MDFCFORPS_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Mdfcforps\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === '') {
            return;
        }

        // __DIR__ is src/Bootstrap, so '/../' resolves the Mdfcforps\ prefix to src/.
        $path = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

/**
 * Tiny kernel.request listener whose sole purpose is to make the container
 * instantiate this service — and thus include this file (registering the
 * autoloader above) — early in the request lifecycle, before controller
 * resolution. The handler itself is intentionally a no-op.
 */
final class ModuleAutoloader
{
    /**
     * @param object $event Unused (RequestEvent on Symfony 4+/6, GetResponseEvent on 3.4)
     */
    public function onKernelRequest($event): void
    {
        // No-op: the autoloader is registered as a side effect of including this file.
    }
}
