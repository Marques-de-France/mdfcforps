<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Tests\Unit\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mdfcforps\Service\AttributionService;
use PHPUnit\Framework\TestCase;

final class AttributionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    public function testCollectFromCookiesUsesClickIdPriority(): void
    {
        $_COOKIE['mdf_click_id'] = 'CLICK_12345';
        $_COOKIE['mdf_utm_source'] = 'marques-de-france';
        $_COOKIE['mdf_landing_ref'] = 'https://example.test/?ref=marques-de-france';

        $service = new AttributionService();
        $result = $service->collectFromCookies();

        self::assertSame('mdf_click', $result['source']);
        self::assertSame('CLICK_12345', $result['click_id']);
    }

    public function testCollectFromCookiesFallsBackToUnknown(): void
    {
        $_COOKIE['mdf_utm_source'] = 'google';
        $_COOKIE['mdf_referring_site'] = 'https://example.org/';

        $service = new AttributionService();
        $result = $service->collectFromCookies();

        self::assertSame('unknown', $result['source']);
    }
}
