<?php
/**
 * PHPUnit bootstrap for module tests.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.0.0');
}

require_once __DIR__ . '/../vendor/autoload.php';
