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

use Mdfcforps\Service\ModuleUpdater;
use Mdfcforps\Service\UpdateChecker;
use Mdfcforps\Service\UpdateException;
use PHPUnit\Framework\TestCase;

/**
 * The self-updater rewrites files on a live merchant's server using a URL supplied
 * by a remote host, so the gate between "downloaded bytes" and "files on disk" is
 * the highest-risk code in the module and the part that cannot be checked by hand
 * on every release.
 *
 * These tests cover exactly that gate — the host allowlist and the package
 * validation — because both fail silently when wrong: an accepted zip-slip entry
 * writes outside the module directory, and an accepted foreign host turns a
 * compromised Hub into arbitrary code execution on every partner shop.
 *
 * The swap itself (rename ladder, rollback) is not covered here: it needs a real
 * PrestaShop filesystem layout. It is exercised through the _PS_MODE_DEV_ fault
 * injection switch documented in the README.
 */
final class ModuleUpdaterTest extends TestCase
{
    /** @var string */
    private $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/mdfcforps-updater-test-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->workDir, 0700, true);
        \PrestaShopLogger::$entries = [];
    }

    protected function tearDown(): void
    {
        ModuleUpdater::removeDirectory($this->workDir);
    }

    // -----------------------------------------------------------------------
    // Host allowlist
    // -----------------------------------------------------------------------

    /**
     * @dataProvider allowedUrlProvider
     */
    public function testAcceptsReleaseHosts(string $url): void
    {
        $this->expectNotToPerformAssertions();
        $this->invoke('assertAllowedUrl', [$url]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function allowedUrlProvider(): array
    {
        return [
            'release asset' => ['https://github.com/Marques-de-France/mdfcforps/releases/latest/download/mdfcforps.zip'],
            // GitHub 302-redirects release downloads here, so it must pass too.
            'redirect target' => ['https://objects.githubusercontent.com/github-production-release-asset/x'],
            'assets subdomain' => ['https://release-assets.githubusercontent.com/x'],
            'hub' => ['https://flux.marques-de-france.fr/api/ps/download'],
        ];
    }

    /**
     * @dataProvider rejectedUrlProvider
     */
    public function testRejectsForeignOrInsecureHosts(string $url): void
    {
        $this->expectException(UpdateException::class);
        $this->invoke('assertAllowedUrl', [$url]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rejectedUrlProvider(): array
    {
        return [
            'plain http' => ['http://github.com/Marques-de-France/mdfcforps/releases/latest/download/mdfcforps.zip'],
            'foreign host' => ['https://attacker.test/mdfcforps.zip'],
            // The allowlist matches on host, not on substring: a lookalike domain
            // that merely ends in the right letters must not slip through.
            'lookalike suffix' => ['https://evilgithub.com/mdfcforps.zip'],
            'host as path' => ['https://attacker.test/github.com/mdfcforps.zip'],
            'no scheme' => ['github.com/mdfcforps.zip'],
            'empty' => [''],
        ];
    }

    /**
     * Plain HTTP is only tolerated for a host explicitly opted into for local
     * testing. It must never apply to the built-in production hosts, or dev mode
     * would turn into a protocol-downgrade hole on github.com.
     */
    public function testPlainHttpIsRejectedForProductionHostsEvenInDevMode(): void
    {
        $this->expectException(UpdateException::class);
        $this->invoke('assertAllowedUrl', ['http://github.com/Marques-de-France/mdfcforps/x.zip']);
    }

    // -----------------------------------------------------------------------
    // Package validation
    // -----------------------------------------------------------------------

    public function testAcceptsAWellFormedPackage(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.4.1'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
            'mdfcforps/src/Service/HubClient.php' => "<?php\n",
        ]);

        $extracted = $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);

        $this->assertDirectoryExists($extracted);
        $this->assertFileExists($extracted . '/config.xml');
    }

    public function testRejectsZipSlipEntries(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.4.1'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
            // Would land in the PrestaShop root, outside the module directory.
            'mdfcforps/../../evil.php' => "<?php\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);
    }

    public function testRejectsEntriesOutsideTheModuleDirectory(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.4.1'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
            'anothermodule/hack.php' => "<?php\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);
    }

    public function testRejectsAbsolutePathEntries(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.4.1'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
            '/etc/cron.d/evil' => "* * * * * root sh\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);
    }

    public function testRejectsADowngrade(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.2.0'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.2.0']);
    }

    public function testRejectsReinstallingTheSameVersion(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.4.0'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.0']);
    }

    /**
     * The Hub says one version, the package contains another: one of them is stale
     * or the download was substituted, and neither is safe to install.
     */
    public function testRejectsAPackageThatDisagreesWithTheAnnouncement(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.5.0'),
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);
    }

    public function testRejectsAnArchiveThatIsNotThisModule(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/config.xml' => $this->configXml('1.4.1'),
            // Right name, wrong contents — a truncated or mispackaged archive.
            'mdfcforps/mdfcforps.php' => "<?php\n// nothing useful here\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);
    }

    public function testRejectsAPackageWithoutConfigXml(): void
    {
        $zip = $this->buildPackage([
            'mdfcforps/mdfcforps.php' => "<?php\nclass Mdfcforps extends Module\n{\n}\n",
        ]);

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$zip, '1.4.0', '1.4.1']);
    }

    public function testRejectsSomethingThatIsNotAZip(): void
    {
        $path = $this->workDir . 'package.zip';
        file_put_contents($path, str_repeat('not a zip at all ', 1024));

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$path, '1.4.0', '1.4.1']);
    }

    public function testRejectsAnImplausiblySmallPackage(): void
    {
        $path = $this->workDir . 'package.zip';
        file_put_contents($path, 'PK');

        $this->expectException(UpdateException::class);
        $this->invoke('validateAndExtract', [$path, '1.4.0', '1.4.1']);
    }

    // -----------------------------------------------------------------------
    // config.xml parsing
    // -----------------------------------------------------------------------

    public function testReadsTheVersionFromConfigXml(): void
    {
        $checker = new UpdateChecker();

        $this->assertSame('1.4.1', $checker->parseConfigXmlVersion($this->configXml('1.4.1')));
        $this->assertSame('', $checker->parseConfigXmlVersion(''));
        $this->assertSame('', $checker->parseConfigXmlVersion('<module><name>x</name></module>'));
        $this->assertSame('', $checker->parseConfigXmlVersion('this is not xml at all'));
    }

    /**
     * config.xml arrives inside a downloaded archive, so an external entity in it
     * must not be resolved — that would let a substituted package read files off
     * the merchant's server.
     */
    public function testDoesNotResolveExternalEntitiesInConfigXml(): void
    {
        $secret = $this->workDir . 'secret.txt';
        file_put_contents($secret, 'TOP-SECRET');

        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE module [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
            . '<module><name>mdfcforps</name><version><![CDATA[1.4.1]]></version>'
            . '<displayName>&xxe;</displayName></module>';

        $version = (new UpdateChecker())->parseConfigXmlVersion($xml);

        $this->assertNotSame('TOP-SECRET', $version);
        $this->assertContains($version, ['1.4.1', ''], 'Parsing must either succeed safely or fail closed.');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Call a private method on a ModuleUpdater whose staging directory points at the
     * test's throwaway working directory.
     *
     * Reflection rather than widening the API: these are internal steps of a single
     * transactional operation, and making them public would invite calling them out
     * of order in production code.
     *
     * @param array<int, mixed> $args
     *
     * @return mixed
     */
    private function invoke(string $method, array $args)
    {
        $updater = new ModuleUpdater(new UpdateChecker());

        $staging = new \ReflectionProperty(ModuleUpdater::class, 'stagingRoot');
        $reflection = new \ReflectionMethod(ModuleUpdater::class, $method);

        // Required on PHP 7.4/8.0 — the module's floor — but a no-op since 8.1 and
        // deprecated as of 8.5, where calling it would emit output and make every
        // test in this class risky.
        if (PHP_VERSION_ID < 80100) {
            $staging->setAccessible(true);
            $reflection->setAccessible(true);
        }

        $staging->setValue($updater, $this->workDir);

        return $reflection->invokeArgs($updater, $args);
    }

    /**
     * @param array<string, string> $entries path inside the archive => contents
     *
     * @return string path to the built ZIP
     */
    private function buildPackage(array $entries): string
    {
        $path = $this->workDir . 'package.zip';

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('Could not create the test archive.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        // Padding: the updater refuses implausibly small packages. It has to be
        // incompressible — repeated text deflates to a few hundred bytes, which is
        // still under the floor.
        $zip->addFromString('mdfcforps/views/padding.bin', random_bytes(16384));

        $zip->close();

        return $path;
    }

    private function configXml(string $version): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<module>'
            . '<name>mdfcforps</name>'
            . '<displayName><![CDATA[Marques de France]]></displayName>'
            . '<version><![CDATA[' . $version . ']]></version>'
            . '<is_configurable>1</is_configurable>'
            . '</module>';
    }
}
