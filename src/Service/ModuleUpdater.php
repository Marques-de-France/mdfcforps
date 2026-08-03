<?php
/**
 * Module source file.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

namespace Mdfcforps\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Downloads and installs a new version of this module, in place, on a live shop.
 *
 * ── The two-phase design, and why it is not optional ────────────────────────
 *
 * Phase 1 (downloadValidateAndSwap) replaces the files on disk. Phase 2 (finalize)
 * runs PrestaShop's upgrade routine. They MUST be separate HTTP requests:
 *
 *  - OPcache still holds the OLD bytecode of every already-included file for the
 *    whole of the current request. "Run the new code's upgrade logic now" is simply
 *    not achievable in phase 1, at any cost.
 *  - The upgrade script would otherwise run against an in-memory Mdfcforps object
 *    built from the OLD class definition, and would fatal on any new method it uses.
 *  - The fallback autoloader resolves __DIR__ . '/src/...', where __DIR__ was baked
 *    in at compile time as the literal modules/mdfcforps path. After the swap that
 *    path exists again with the NEW tree, so any class autoloaded after the swap
 *    loads new code into a request otherwise running old code.
 *
 * The redirect between the phases buys a fresh PHP process with reset OPcache — the
 * only way to get genuinely new code executing.
 *
 * Because of the third point, there is a HARD RULE at the swap site: after
 * downloadValidateAndSwap() returns, the caller may only touch pre-resolved core
 * classes (Configuration, PrestaShopLogger, opcache_reset) and return a
 * RedirectResponse. It must never render a template — Twig would pull in extensions,
 * presenters and translation loaders, each one an autoload opportunity.
 *
 * ── The swap ────────────────────────────────────────────────────────────────
 *
 *   1. promote:  <staging>/mdfcforps           -> modules/mdfcforps__new_<rand>
 *   2. backup:   modules/mdfcforps             -> modules/mdfcforps_bak_<rand>
 *   3. activate: modules/mdfcforps__new_<rand> -> modules/mdfcforps
 *
 * Extracting in place was rejected: the shop is live and hookDisplayHeader fires on
 * every front-office page, so an in-place extraction exposes a half-old/half-new tree
 * for its whole duration — a storefront fatal. It also never deletes files the new
 * version removed, and offers no way back. With renames the exposure window is the
 * microseconds between steps 2 and 3, both atomic within a filesystem; a request
 * landing there gets `false` from Module::getInstanceByName() and Hook::exec skips
 * the module — one page without the tracker, not a 500.
 */
final class ModuleUpdater
{
    public const PHASE_NONE = '';
    /** Files replaced, PrestaShop upgrade still pending. */
    public const PHASE_SWAPPED = 'swapped';
    /** Failed and rolled back; the shop is on the previous version. */
    public const PHASE_FAILED = 'failed';
    /** Files are new but the DB upgrade could not be run automatically. */
    public const PHASE_MANUAL = 'manual_upgrade_required';
    /** Catastrophic: no live module directory, manual restore required. */
    public const PHASE_BROKEN = 'broken';

    /**
     * State key, written GLOBALLY via \Configuration rather than through ModuleConfig.
     *
     * ModuleConfig scopes to the current shop id. A multistore admin can start the
     * update in shop 2's context and land on the finalize route in shop 1's — with a
     * per-shop row the state would be invisible there and the update would hang
     * half-done, files new and DB old, with no banner offering to finish it.
     *
     * Do not "tidy" this into ModuleConfig.
     */
    public const KEY_STATE = 'MDFCFORPS_UPDATE_STATE';

    public const KEY_LAST_ERROR = 'MDFCFORPS_UPDATE_LAST_ERROR';

    /**
     * Fault injection for testing the failure paths. Honoured ONLY when
     * _PS_MODE_DEV_ is true, so it is inert in production. See README → Recovery.
     */
    public const KEY_FAULT = 'MDFCFORPS_UPDATE_FAULT';

    /** Dev-only extra download hosts, comma separated. Honoured ONLY when _PS_MODE_DEV_. */
    public const KEY_ALLOWED_HOSTS = 'MDFCFORPS_UPDATE_ALLOWED_HOSTS';

    public const MODULE_NAME = 'mdfcforps';

    /** Hosts the update package may be downloaded from. */
    private const ALLOWED_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'raw.githubusercontent.com',
        'flux.marques-de-france.fr',
    ];

    private const MIN_ZIP_BYTES = 10240;        // 10 KB
    private const MAX_ZIP_BYTES = 20971520;     // 20 MB
    private const MIN_FREE_BYTES = 20971520;    // 20 MB
    private const DOWNLOAD_TIMEOUT = 60;
    private const BACKUP_PREFIX = 'mdfcforps_bak_';
    private const INCOMING_PREFIX = 'mdfcforps__new_';
    private const STAGING_DIR = 'mdfcforps-update';
    private const LOCK_FILE = 'mdfcforps_update.lock';

    /** A half-finished update older than this is offered for completion. */
    public const STALE_STATE_SECONDS = 900; // 15 min

    /** @var resource|null */
    private $lockHandle;

    /** @var string */
    private $stagingRoot = '';

    /** @var UpdateChecker */
    private $checker;

    public function __construct(?UpdateChecker $checker = null)
    {
        $this->checker = $checker ?: new UpdateChecker();
    }

    // -----------------------------------------------------------------------
    // Preflight
    // -----------------------------------------------------------------------

    /**
     * Everything that would make this update fail, checked before offering the button.
     *
     * Returned as untranslated source strings plus placeholder params: this is a
     * service and has no translator, so the controller renders them via mdfTrans().
     * A non-empty result disables the update button and lists the reasons, which
     * gives the merchant a real diagnosis instead of a failed click.
     *
     * @param array<string, mixed> $announcement
     *
     * @return array<int, array{message: string, params: array<string, string>}>
     */
    public function preflight(array $announcement): array
    {
        $blockers = [];
        $moduleDir = $this->moduleDir();

        if (!is_writable(_PS_MODULE_DIR_)) {
            $blockers[] = $this->blocker('The modules directory is not writable by PHP.');
        }

        if (is_dir($moduleDir) && !is_writable($moduleDir)) {
            $blockers[] = $this->blocker('The module directory is not writable by PHP.');
        }

        if (!is_writable(_PS_CACHE_DIR_)) {
            $blockers[] = $this->blocker('The cache directory (var/cache) is not writable by PHP.');
        }

        if (!class_exists('ZipArchive')) {
            $blockers[] = $this->blocker('The PHP ZIP extension (ZipArchive) is not available on this server.');
        }

        if (!$this->allowUrlFopen()) {
            $blockers[] = $this->blocker('Outgoing HTTP requests are disabled on this server (allow_url_fopen).');
        }

        foreach (['rename', 'unlink', 'rmdir'] as $required) {
            if ($this->isFunctionDisabled($required)) {
                $blockers[] = $this->blocker(
                    'The PHP function %function% is disabled on this server.',
                    ['%function%' => $required]
                );
            }
        }

        $minPhp = (string) ($announcement['minPhpVersion'] ?? '');
        if ($minPhp !== '' && version_compare(PHP_VERSION, $minPhp, '<')) {
            $blockers[] = $this->blocker(
                'PHP %required% or newer is required for this update (this server runs %current%).',
                ['%required%' => $minPhp, '%current%' => PHP_VERSION]
            );
        }

        $minPs = (string) ($announcement['minPsVersion'] ?? '');
        if ($minPs !== '' && version_compare(_PS_VERSION_, $minPs, '<')) {
            $blockers[] = $this->blocker(
                'PrestaShop %required% or newer is required for this update (this store runs %current%).',
                ['%required%' => $minPs, '%current%' => _PS_VERSION_]
            );
        }

        // Zip + extracted tree + retained backup coexist for the duration of the swap.
        $announcedSize = (int) ($announcement['size'] ?? 0);
        $needed = max(self::MIN_FREE_BYTES, $announcedSize * 3);
        $free = @disk_free_space(_PS_MODULE_DIR_);
        if (is_float($free) && $free > 0 && $free < $needed) {
            $blockers[] = $this->blocker(
                'Not enough free disk space to install the update (%needed% MB required).',
                ['%needed%' => (string) (int) ceil($needed / 1048576)]
            );
        }

        if ($this->isOpcacheStuck()) {
            $blockers[] = $this->blocker(
                'PHP OPcache is configured without timestamp validation and cannot be reset from PHP. '
                . 'Ask your host to enable opcache.validate_timestamps, or to restart PHP after each update.'
            );
        }

        $state = $this->getState();
        if (
            ($state['phase'] ?? self::PHASE_NONE) === self::PHASE_SWAPPED
            && (time() - (int) ($state['startedAt'] ?? 0)) < self::STALE_STATE_SECONDS
        ) {
            $blockers[] = $this->blocker('An update is already in progress.');
        }

        return $blockers;
    }

    /**
     * OPcache is caching bytecode, will not notice the swapped files, and cannot be
     * reset from PHP.
     *
     * This is the one failure mode that corrupts silently: the new files never
     * execute, ps_module.version advances anyway, and the upgrade scripts for that
     * version are then skipped forever with nothing looking wrong. Managed French
     * hosting (o2switch, OVH Performance) tunes exactly this way, so it is worth a
     * dedicated check rather than a generic "update failed".
     */
    private function isOpcacheStuck(): bool
    {
        if (!function_exists('opcache_get_configuration') || !ini_get('opcache.enable')) {
            return false;
        }

        $validatesTimestamps = (bool) ini_get('opcache.validate_timestamps');
        if ($validatesTimestamps) {
            return false;
        }

        return !function_exists('opcache_reset') || $this->isFunctionDisabled('opcache_reset');
    }

    // -----------------------------------------------------------------------
    // Phase 1 — download, validate, swap
    // -----------------------------------------------------------------------

    /**
     * Replace the module files on disk with the announced version.
     *
     * On success the caller must immediately opcache_reset() and redirect to the
     * finalize route — see the class docblock for what may and may not be called
     * after this returns.
     *
     * On failure the previous tree is restored where possible and an UpdateException
     * carrying the failing step is thrown. The single state where the shop is left
     * degraded (PHASE_BROKEN) is written to the state key so the dashboard can print
     * the manual recovery command.
     *
     * @param array<string, mixed> $announcement
     *
     * @throws UpdateException
     */
    public function downloadValidateAndSwap(array $announcement): void
    {
        $installed = $this->checker->getInstalledVersion();
        $target = (string) ($announcement['latestVersion'] ?? '');

        // A merchant closing the tab between the two renames must not abort the
        // process mid-swap.
        @ignore_user_abort(true);
        @set_time_limit(180);

        $this->acquireLock();

        $backupDir = '';
        $incomingDir = '';

        try {
            $this->stagingRoot = $this->createStagingDir();

            $zipPath = $this->download((string) ($announcement['downloadUrl'] ?? ''));
            $this->verifyChecksum($zipPath, (string) ($announcement['sha256'] ?? ''));
            $extractedDir = $this->validateAndExtract($zipPath, $installed, $target);

            // From here on the live tree is touched. Each failure below is handled
            // by the rollback ladder in the catch blocks.
            $incomingDir = $this->promote($extractedDir);
            $backupDir = $this->activate($incomingDir);

            $this->setState([
                'phase' => self::PHASE_SWAPPED,
                'from' => $installed,
                'to' => $target,
                'backup' => basename($backupDir),
                'startedAt' => time(),
            ]);

            $this->log(sprintf('swap complete from=%s to=%s backup=%s', $installed, $target, basename($backupDir)));
        } catch (UpdateException $e) {
            $this->cleanupIncoming($incomingDir);
            throw $e;
        } catch (\Throwable $e) {
            $this->cleanupIncoming($incomingDir);
            throw new UpdateException(UpdateException::STEP_VALIDATE, $e->getMessage(), $e);
        } finally {
            $this->cleanupStaging();
            $this->releaseLock();
        }
    }

    // -----------------------------------------------------------------------
    // Staging
    // -----------------------------------------------------------------------

    /**
     * Private, unguessable, non-web-readable working directory.
     *
     * Not inside modules/mdfcforps (the directory being replaced, and web-reachable)
     * and not a bare modules/ subdirectory (PrestaShop's module scanner would list a
     * leftover as a broken module).
     */
    private function createStagingDir(): string
    {
        $root = rtrim((string) _PS_CACHE_DIR_, '/\\') . DIRECTORY_SEPARATOR . self::STAGING_DIR;

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            throw new UpdateException(UpdateException::STEP_STAGING, 'No source of randomness available.', $e);
        }

        $dir = $root . DIRECTORY_SEPARATOR . $suffix . DIRECTORY_SEPARATOR;

        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new UpdateException(
                UpdateException::STEP_STAGING,
                'Could not create the temporary directory ' . $dir
            );
        }

        // Belt and braces: var/cache is not meant to be web-served, but hosting
        // misconfigurations happen and this directory briefly holds executable PHP.
        @file_put_contents($root . DIRECTORY_SEPARATOR . 'index.php', "<?php\nheader('HTTP/1.0 404 Not Found');\n");
        @file_put_contents(
            $root . DIRECTORY_SEPARATOR . '.htaccess',
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
        );

        return $dir;
    }

    private function cleanupStaging(): void
    {
        if ($this->stagingRoot !== '' && is_dir($this->stagingRoot)) {
            self::removeDirectory($this->stagingRoot);
        }

        $this->stagingRoot = '';
    }

    private function cleanupIncoming(string $incomingDir): void
    {
        if ($incomingDir !== '' && is_dir($incomingDir)) {
            self::removeDirectory($incomingDir);
        }
    }

    // -----------------------------------------------------------------------
    // Download
    // -----------------------------------------------------------------------

    /**
     * Fetch the update package.
     *
     * Not on HubClient: the URL points at GitHub rather than the Hub, and HubClient's
     * 5s timeout is wrong for a megabyte transfer.
     *
     * @throws UpdateException
     */
    private function download(string $url): string
    {
        $this->assertNoFault('fail_download', UpdateException::STEP_DOWNLOAD, 'Injected download failure.');

        // The single most important control in this feature. downloadUrl arrives from
        // a remote server, so without an allowlist a compromised Hub — or a DNS hijack
        // of it — becomes arbitrary code drop into every partner's modules directory.
        // Checked BEFORE any network call is made.
        $this->assertAllowedUrl($url);

        $target = $this->stagingRoot . 'package.zip';

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/octet-stream\r\nUser-Agent: mdfcforps-updater\r\n",
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'follow_location' => 1,
                // GitHub's releases/latest/download/... 302s to an assets host, which
                // is why that host is in ALLOWED_HOSTS too.
                'max_redirects' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $started = microtime(true);
        $in = @fopen($url, 'rb', false, $context);

        if ($in === false) {
            throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'Could not open the download URL.');
        }

        // Read the status off the stream handle rather than $http_response_header:
        // that magic local is deprecated as of PHP 8.4, and referencing it at all
        // emits a notice. wrapper_data carries the same header lines.
        $meta = stream_get_meta_data($in);
        $status = $this->extractStatusCode(isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])
            ? $meta['wrapper_data']
            : []);

        if ($status < 200 || $status >= 300) {
            @fclose($in);
            throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'Download failed with HTTP ' . $status . '.');
        }

        $out = @fopen($target, 'wb');
        if ($out === false) {
            @fclose($in);
            throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'Could not write the downloaded package.');
        }

        // Chunked with a hard cap rather than file_get_contents: a hostile or broken
        // response must not be able to fill the merchant's disk.
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                break;
            }

            $written += strlen($chunk);
            if ($written > self::MAX_ZIP_BYTES) {
                @fclose($in);
                @fclose($out);
                throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'The update package is unexpectedly large.');
            }

            if (fwrite($out, $chunk) === false) {
                @fclose($in);
                @fclose($out);
                throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'Could not write the downloaded package.');
            }
        }

        @fclose($in);
        @fclose($out);

        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $this->log(sprintf(
            'download ok host=%s bytes=%d elapsed=%dms',
            (string) parse_url($url, PHP_URL_HOST),
            $written,
            $elapsed
        ));

        if ($written < self::MIN_ZIP_BYTES) {
            throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'The downloaded package is too small to be valid.');
        }

        return $target;
    }

    /**
     * @throws UpdateException
     */
    private function assertAllowedUrl(string $url): void
    {
        $isHttps = stripos($url, 'https://') === 0;

        // Plain HTTP is accepted ONLY for a host the developer explicitly opted into
        // via MDFCFORPS_UPDATE_ALLOWED_HOSTS, and only in dev mode. Local testing
        // serves packages off a throwaway HTTP server, and the alternative — a
        // self-signed certificate — fails verify_peer anyway, so requiring HTTPS here
        // would make the download path untestable rather than making it safer.
        // On a partner's shop _PS_MODE_DEV_ is false, so this is unreachable and
        // HTTPS remains mandatory.
        if (!$isHttps && !($this->isDevMode() && stripos($url, 'http://') === 0)) {
            throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'The update package URL is not HTTPS.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new UpdateException(UpdateException::STEP_DOWNLOAD, 'The update package URL is malformed.');
        }

        // A non-HTTPS URL must match an explicitly configured dev host — never one of
        // the built-in production hosts, so a downgrade attack on github.com is still
        // refused even with dev mode on.
        $allowed = $isHttps ? $this->allowedHosts() : $this->devAllowedHosts();

        foreach ($allowed as $candidate) {
            if ($host === $candidate || $this->endsWith($host, '.' . $candidate)) {
                return;
            }
        }

        throw new UpdateException(
            UpdateException::STEP_DOWNLOAD,
            'The update package URL points to an unexpected host: ' . $host
        );
    }

    private function isDevMode(): bool
    {
        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_;
    }

    /**
     * @return array<int, string>
     */
    private function allowedHosts(): array
    {
        return array_merge(self::ALLOWED_HOSTS, $this->devAllowedHosts());
    }

    /**
     * Extra download hosts opted into for local testing.
     *
     * Gating this on _PS_MODE_DEV_ is what lets the test seam exist without weakening
     * production: on a partner's shop the constant is false and this returns nothing.
     *
     * @return array<int, string>
     */
    private function devAllowedHosts(): array
    {
        if (!$this->isDevMode()) {
            return [];
        }

        $hosts = [];

        foreach (explode(',', ModuleConfig::get(self::KEY_ALLOWED_HOSTS, '')) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @throws UpdateException
     */
    private function verifyChecksum(string $zipPath, string $expected): void
    {
        $this->assertNoFault('fail_checksum', UpdateException::STEP_CHECKSUM, 'Injected checksum failure.');

        if ($expected === '') {
            // The Hub has no checksum recorded. The remaining validation (host
            // allowlist, HTTPS, ZIP structure, config.xml version equality) still
            // applies, but package integrity is unverified — see README.
            $this->log('checksum skipped: none announced', 2);

            return;
        }

        $actual = @hash_file('sha256', $zipPath);
        if (!is_string($actual) || !hash_equals(strtolower($expected), strtolower($actual))) {
            $this->log('checksum MISMATCH', 3);
            throw new UpdateException(
                UpdateException::STEP_CHECKSUM,
                'The update package does not match the expected checksum.'
            );
        }

        $this->log('checksum ok');
    }

    // -----------------------------------------------------------------------
    // Validation and extraction
    // -----------------------------------------------------------------------

    /**
     * Prove the archive is the module we asked for, then extract it to staging.
     *
     * Every check here runs before anything live is touched, and each one fails
     * closed.
     *
     * @throws UpdateException
     *
     * @return string path to the extracted <staging>/mdfcforps directory
     */
    private function validateAndExtract(string $zipPath, string $installed, string $announcedVersion): string
    {
        $size = (int) @filesize($zipPath);
        if ($size < self::MIN_ZIP_BYTES || $size > self::MAX_ZIP_BYTES) {
            throw new UpdateException(UpdateException::STEP_VALIDATE, 'The update package has an implausible size.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new UpdateException(UpdateException::STEP_VALIDATE, 'The update package is not a readable ZIP archive.');
        }

        try {
            // Zip-slip: every entry must stay inside mdfcforps/. Checked on the raw
            // entry names, before extraction, so a traversal path never reaches the
            // filesystem at all.
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);

                if (
                    $name === ''
                    || strpos($name, '..') !== false
                    || strpos($name, "\0") !== false
                    || strpos($name, '\\') !== false
                    || strpos($name, '/') === 0
                    || strpos($name, self::MODULE_NAME . '/') !== 0
                ) {
                    throw new UpdateException(
                        UpdateException::STEP_VALIDATE,
                        'The update package contains an unexpected entry.'
                    );
                }
            }

            $configXml = $zip->getFromName(self::MODULE_NAME . '/config.xml');
            if (!is_string($configXml) || trim($configXml) === '') {
                throw new UpdateException(UpdateException::STEP_VALIDATE, 'The update package has no config.xml.');
            }

            $zipVersion = $this->checker->parseConfigXmlVersion($configXml);
            if ($zipVersion === '') {
                throw new UpdateException(UpdateException::STEP_VALIDATE, 'The update package declares no version.');
            }

            if ($installed !== '' && version_compare($zipVersion, $installed, '<=')) {
                throw new UpdateException(
                    UpdateException::STEP_VALIDATE,
                    sprintf('The update package (%s) is not newer than the installed version (%s).', $zipVersion, $installed)
                );
            }

            // The Hub and the package must agree. A mismatch means one of them is
            // stale or the download was substituted.
            if ($announcedVersion !== '' && $zipVersion !== $announcedVersion) {
                throw new UpdateException(
                    UpdateException::STEP_VALIDATE,
                    sprintf('The update package is version %s but %s was announced.', $zipVersion, $announcedVersion)
                );
            }

            // Cheap smoke test that catches a truncated or simply wrong archive.
            $main = $zip->getFromName(self::MODULE_NAME . '/' . self::MODULE_NAME . '.php');
            if (!is_string($main) || strpos($main, 'class Mdfcforps extends Module') === false) {
                throw new UpdateException(UpdateException::STEP_VALIDATE, 'The update package does not contain the module.');
            }

            if ($zip->locateName(self::MODULE_NAME . '/upgrade/upgrade-' . $zipVersion . '.php') === false) {
                // Not fatal — a release with no schema, route or service change
                // legitimately has none — but worth knowing about when diagnosing a
                // stale container after an update.
                $this->log('package has no upgrade script for ' . $zipVersion, 2);
            }

            $this->assertNoFault('fail_extract', UpdateException::STEP_EXTRACT, 'Injected extraction failure.');

            if (!$zip->extractTo($this->stagingRoot)) {
                throw new UpdateException(UpdateException::STEP_EXTRACT, 'The update package could not be extracted.');
            }
        } finally {
            $zip->close();
        }

        $extracted = $this->stagingRoot . self::MODULE_NAME;

        if (!is_dir($extracted)) {
            throw new UpdateException(UpdateException::STEP_EXTRACT, 'The extracted package has no module directory.');
        }

        // Symlink entries survive the name sweep above, so the extracted tree gets
        // its own pass: a symlink pointing outside the module would otherwise be
        // renamed straight into modules/.
        if ($this->containsSymlink($extracted)) {
            throw new UpdateException(UpdateException::STEP_VALIDATE, 'The update package contains a symbolic link.');
        }

        // Re-assert against the file on disk in case ZipArchive::getFromName and the
        // extractor disagreed about which entry is which.
        $onDiskVersion = $this->checker->readConfigXmlVersion($extracted . '/config.xml');
        if ($onDiskVersion === '' || ($announcedVersion !== '' && $onDiskVersion !== $announcedVersion)) {
            throw new UpdateException(
                UpdateException::STEP_VALIDATE,
                'The extracted package version does not match the announced version.'
            );
        }

        $this->log('package validated version=' . $onDiskVersion);

        return $extracted;
    }

    private function containsSymlink(string $dir): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Unreadable tree — treat as suspicious rather than clean.
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // The swap
    // -----------------------------------------------------------------------

    /**
     * Move the validated tree from staging into modules/ under a temporary name.
     *
     * var/cache and modules/ are normally the same filesystem, but not on every host,
     * so a failed rename falls back to a recursive copy. After this step the actual
     * swap is two renames WITHIN modules/, which is guaranteed same-device and
     * therefore atomic.
     *
     * @throws UpdateException
     */
    private function promote(string $extractedDir): string
    {
        $this->assertNoFault('fail_promote', UpdateException::STEP_PROMOTE, 'Injected promote failure.');

        $incoming = _PS_MODULE_DIR_ . self::INCOMING_PREFIX . $this->randomSuffix();

        if (!@rename($extractedDir, $incoming)) {
            if (!self::copyDirectory($extractedDir, $incoming)) {
                self::removeDirectory($incoming);
                throw new UpdateException(
                    UpdateException::STEP_PROMOTE,
                    'The new module files could not be moved into the modules directory.'
                );
            }
        }

        $this->log('promote ok -> ' . basename($incoming));

        return $incoming;
    }

    /**
     * Back up the live directory and put the new one in its place.
     *
     * The rollback ladder below is the whole point of the rename-based design:
     * if the backup rename fails, nothing live has moved and the shop is untouched.
     * Only a failure of the second rename can leave the shop without a module
     * directory, and that path tries rename-restore, then copy-restore, then gives
     * the merchant a literal shell command.
     *
     * @throws UpdateException
     *
     * @return string path to the retained backup directory
     */
    private function activate(string $incomingDir): string
    {
        $live = $this->moduleDir();
        $backup = _PS_MODULE_DIR_ . self::BACKUP_PREFIX . $this->randomSuffix();

        $this->assertNoFault('fail_rename1', UpdateException::STEP_BACKUP, 'Injected backup-rename failure.');

        if (!@rename($live, $backup)) {
            throw new UpdateException(
                UpdateException::STEP_BACKUP,
                'The current module directory could not be moved aside. Nothing was changed.'
            );
        }

        $this->log('backup ok -> ' . basename($backup));

        try {
            $this->assertNoFault('fail_rename2', UpdateException::STEP_ACTIVATE, 'Injected activate-rename failure.');

            if (!@rename($incomingDir, $live)) {
                throw new UpdateException(UpdateException::STEP_ACTIVATE, 'The new module directory could not be activated.');
            }
        } catch (UpdateException $e) {
            $this->restoreOrBreak($backup, $live, $e);
        }

        $this->log('activate ok');

        return $backup;
    }

    /**
     * The only genuinely bad state: modules/mdfcforps does not exist.
     *
     * @throws UpdateException always
     */
    private function restoreOrBreak(string $backup, string $live, UpdateException $cause): void
    {
        $restored = false;

        if (!$this->faultActive('fail_restore')) {
            $restored = @rename($backup, $live);

            if (!$restored) {
                $restored = self::copyDirectory($backup, $live);
            }
        }

        if ($restored) {
            $this->log('activate failed, previous version restored', 4);

            throw new UpdateException(
                UpdateException::STEP_ACTIVATE,
                'The update could not be applied. The previous version was restored.'
            );
        }

        // Nothing left to try automatically. Record the exact shell command that
        // fixes it, so it can be shown in the back office and read out to a host.
        $command = sprintf('mv %s %s', $backup, rtrim($live, '/\\'));

        $this->setState([
            'phase' => self::PHASE_BROKEN,
            'from' => '',
            'to' => '',
            'backup' => basename($backup),
            'command' => $command,
            'startedAt' => time(),
        ]);

        $this->log('BROKEN: module directory missing. Recover with: ' . $command, 4);

        throw new UpdateException(
            UpdateException::STEP_RESTORE,
            'The update failed and the previous version could not be restored automatically. Recover with: ' . $command
        );
    }

    // -----------------------------------------------------------------------
    // Phase 2 — run the PrestaShop upgrade
    // -----------------------------------------------------------------------

    /**
     * Bring the database in line with the files swapped in by phase 1.
     *
     * Runs in a fresh request, so the code executing here is the NEW version.
     *
     * PrestaShop's upgrade APIs drifted across 1.7 / 8 / 9, so this is a
     * capability-detected chain rather than a single call. Each step is guarded and
     * each failure falls through to the next. The last step is not a failure mode
     * but a legitimate resting place: with new files on disk and an old ps_module
     * row, PrestaShop's own Module Manager offers an "Upgrade" action — the shop is
     * already running the new code and only the database lags. That terminal state
     * is safe on every PrestaShop version, which is what makes the chain acceptable.
     *
     * @return string PHASE_NONE on success, PHASE_MANUAL when the merchant must
     *                finish in Module Manager
     */
    public function finalize(): string
    {
        $state = $this->getState();
        $from = (string) ($state['from'] ?? '');
        $to = (string) ($state['to'] ?? '');

        $this->log(sprintf('finalize start from=%s to=%s db=%s', $from, $to, $this->readDbVersion()));

        $path = 'none';

        try {
            $this->assertNoFault('fail_upgrade', UpdateException::STEP_UPGRADE, 'Injected upgrade failure.');
            $path = $this->runUpgradeChain($from, $to);
        } catch (\Throwable $e) {
            $this->log('upgrade chain error: ' . $e->getMessage(), 3);
        }

        $this->log('upgrade path=' . $path . ' db=' . $this->readDbVersion());

        // One success criterion, whichever step ran: the database agrees with the
        // files. Checking the outcome rather than the return value of whichever API
        // happened to exist is what keeps this robust across versions.
        $onDisk = $this->checker->readConfigXmlVersion($this->moduleDir() . '/config.xml');
        $inDb = $this->readDbVersion();

        if ($onDisk !== '' && $inDb !== '' && version_compare($onDisk, $inDb, '<=')) {
            $this->deleteBackup((string) ($state['backup'] ?? ''));
            $this->clearState();
            ModuleConfig::update(self::KEY_LAST_ERROR, '');
            $this->checker->clear();
            $this->log('finalize ok version=' . $inDb);

            return self::PHASE_NONE;
        }

        $this->setState([
            'phase' => self::PHASE_MANUAL,
            'from' => $from,
            'to' => $to,
            'backup' => (string) ($state['backup'] ?? ''),
            'startedAt' => (int) ($state['startedAt'] ?? time()),
        ]);

        $this->log(sprintf('finalize incomplete: files=%s db=%s — manual upgrade required', $onDisk, $inDb), 3);

        return self::PHASE_MANUAL;
    }

    /**
     * @return string which mechanism ran, for the log
     */
    private function runUpgradeChain(string $from, string $to): string
    {
        // 1. The official service. Signature drifted (1.7: upgrade($name, $source = null);
        //    8/9: upgrade(string $name): bool), so pass only the name.
        try {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if ($container !== null && $container->has('prestashop.module.manager')) {
                $manager = $container->get('prestashop.module.manager');
                if (is_object($manager) && method_exists($manager, 'upgrade')) {
                    $manager->upgrade(self::MODULE_NAME);

                    if ($this->dbMatchesDisk()) {
                        return 'module_manager';
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('module manager upgrade failed: ' . $e->getMessage(), 2);
        }

        // 2. Legacy statics. runUpgradeModule() calls upgradeModuleVersion() itself on
        //    1.7/8, so do not also call it.
        try {
            $module = \Module::getInstanceByName(self::MODULE_NAME);
            if (
                $module instanceof \Module
                && method_exists('Module', 'initUpgradeModule')
                && method_exists($module, 'runUpgradeModule')
            ) {
                \Module::initUpgradeModule($module);
                $module->runUpgradeModule();

                if ($this->dbMatchesDisk()) {
                    return 'legacy';
                }
            }
        } catch (\Throwable $e) {
            $this->log('legacy upgrade failed: ' . $e->getMessage(), 2);
        }

        // 3. Run the upgrade scripts ourselves. Reproduces what runUpgradeModule does,
        //    so a rename or removal in a future PrestaShop cannot strand us.
        try {
            if ($this->runUpgradeScripts($from, $to) && $this->dbMatchesDisk()) {
                return 'inline_scripts';
            }
        } catch (\Throwable $e) {
            $this->log('inline upgrade scripts failed: ' . $e->getMessage(), 2);
        }

        return 'manual';
    }

    /**
     * Execute upgrade/upgrade-X.Y.Z.php for every version in (from, to], in order.
     */
    private function runUpgradeScripts(string $from, string $to): bool
    {
        $module = \Module::getInstanceByName(self::MODULE_NAME);
        if (!$module instanceof \Module) {
            return false;
        }

        $files = @glob($this->moduleDir() . '/upgrade/upgrade-*.php');
        if (!is_array($files)) {
            return false;
        }

        $applicable = [];
        foreach ($files as $file) {
            if (preg_match('/upgrade-([0-9]+(?:\.[0-9]+)*)\.php$/', basename($file), $m) !== 1) {
                continue;
            }

            $version = $m[1];
            if ($from !== '' && version_compare($version, $from, '<=')) {
                continue;
            }

            if ($to !== '' && version_compare($version, $to, '>')) {
                continue;
            }

            $applicable[$version] = $file;
        }

        uksort($applicable, 'version_compare');

        foreach ($applicable as $version => $file) {
            require_once $file;

            $function = 'upgrade_module_' . str_replace('.', '_', $version);
            if (!function_exists($function)) {
                $this->log('upgrade script ' . basename($file) . ' has no ' . $function . '()', 2);
                continue;
            }

            if ($function($module) === false) {
                $this->log('upgrade script ' . basename($file) . ' returned false', 3);

                return false;
            }

            $this->log('ran ' . basename($file));
        }

        $target = $this->checker->readConfigXmlVersion($this->moduleDir() . '/config.xml');
        if ($target === '') {
            return false;
        }

        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = "' . pSQL($target) . '" WHERE `name` = "' . pSQL(self::MODULE_NAME) . '"'
        );
    }

    private function dbMatchesDisk(): bool
    {
        $onDisk = $this->checker->readConfigXmlVersion($this->moduleDir() . '/config.xml');
        $inDb = $this->readDbVersion();

        return $onDisk !== '' && $inDb !== '' && version_compare($onDisk, $inDb, '<=');
    }

    private function readDbVersion(): string
    {
        try {
            $version = \Db::getInstance()->getValue(
                'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = "' . pSQL(self::MODULE_NAME) . '"'
            );

            return $version ? (string) $version : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // -----------------------------------------------------------------------
    // Rollback and housekeeping
    // -----------------------------------------------------------------------

    /**
     * Put the retained backup back in place.
     *
     * Offered in the back office after a failed finalize, and usable manually via
     * the recovery command printed for the PHASE_BROKEN state.
     */
    public function rollback(): bool
    {
        $state = $this->getState();
        $backupName = (string) ($state['backup'] ?? '');

        if ($backupName === '' || strpos($backupName, self::BACKUP_PREFIX) !== 0) {
            return false;
        }

        $backup = _PS_MODULE_DIR_ . $backupName;
        if (!is_dir($backup)) {
            return false;
        }

        $live = $this->moduleDir();
        $discard = _PS_MODULE_DIR_ . self::INCOMING_PREFIX . 'discard_' . $this->randomSuffix();

        @ignore_user_abort(true);

        if (is_dir($live) && !@rename($live, $discard)) {
            $this->log('rollback: could not move the failed version aside', 3);

            return false;
        }

        if (!@rename($backup, $live)) {
            // Put the failed-but-working version back rather than leaving no module.
            @rename($discard, $live);
            $this->log('rollback: could not restore the backup', 4);

            return false;
        }

        self::removeDirectory($discard);

        $version = $this->checker->readConfigXmlVersion($live . '/config.xml');
        if ($version !== '') {
            @\Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = "' . pSQL($version) . '" WHERE `name` = "' . pSQL(self::MODULE_NAME) . '"'
            );
        }

        $this->clearState();
        $this->clearCaches();
        $this->log('rollback ok version=' . $version);

        return true;
    }

    /**
     * Delete backups left behind by older updates.
     *
     * Called from the lazy cron. Backups are retained for a week so a problem that
     * only shows up days later can still be undone.
     */
    public function garbageCollectBackups(int $maxAgeSeconds = 604800): void
    {
        $current = (string) ($this->getState()['backup'] ?? '');

        foreach ([self::BACKUP_PREFIX, self::INCOMING_PREFIX] as $prefix) {
            $dirs = @glob(_PS_MODULE_DIR_ . $prefix . '*', GLOB_ONLYDIR);
            if (!is_array($dirs)) {
                continue;
            }

            foreach ($dirs as $dir) {
                if (basename($dir) === $current) {
                    continue;
                }

                $mtime = @filemtime($dir);
                if ($mtime === false || (time() - $mtime) < $maxAgeSeconds) {
                    continue;
                }

                self::removeDirectory($dir);
                $this->log('garbage-collected ' . basename($dir));
            }
        }
    }

    /**
     * Purge compiled bytecode and caches so the new files actually take effect.
     *
     * Never call this between the two renames — Symfony 4.4/6.4 split large compiled
     * containers into lazily-required fragment files, so deleting var/cache mid-request
     * can fatal the moment anything touches a not-yet-loaded service.
     */
    public function clearCaches(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        try {
            if (method_exists('Tools', 'clearSmartyCache')) {
                \Tools::clearSmartyCache();
            }

            if (method_exists('Tools', 'clearSf2Cache')) {
                \Tools::clearSf2Cache('dev');
                \Tools::clearSf2Cache('prod');
            }
        } catch (\Throwable $e) {
            $this->log('cache clear warning: ' . $e->getMessage(), 2);
        }
    }

    private function deleteBackup(string $backupName): void
    {
        if ($backupName === '' || strpos($backupName, self::BACKUP_PREFIX) !== 0) {
            return;
        }

        $dir = _PS_MODULE_DIR_ . $backupName;
        if (is_dir($dir)) {
            self::removeDirectory($dir);
            $this->log('backup deleted ' . $backupName);
        }
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $raw = (string) \Configuration::get(self::KEY_STATE);
        if ($raw === '') {
            return ['phase' => self::PHASE_NONE];
        }

        $json = base64_decode($raw, true);
        if ($json === false) {
            return ['phase' => self::PHASE_NONE];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['phase' => self::PHASE_NONE];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function setState(array $state): void
    {
        // Global on purpose — see KEY_STATE.
        \Configuration::updateValue(self::KEY_STATE, base64_encode((string) json_encode($state)));
    }

    public function clearState(): void
    {
        \Configuration::updateValue(self::KEY_STATE, '');
    }

    public function getPhase(): string
    {
        return (string) ($this->getState()['phase'] ?? self::PHASE_NONE);
    }

    /**
     * A swap that never got its finalize — the merchant is offered a "finish it" button.
     */
    public function hasStalledUpdate(): bool
    {
        $state = $this->getState();

        return ($state['phase'] ?? self::PHASE_NONE) === self::PHASE_SWAPPED
            && (time() - (int) ($state['startedAt'] ?? 0)) >= self::STALE_STATE_SECONDS;
    }

    public function recordError(string $message): void
    {
        ModuleConfig::update(self::KEY_LAST_ERROR, $this->sanitize($message));
    }

    public function getLastError(): string
    {
        return ModuleConfig::get(self::KEY_LAST_ERROR, '');
    }

    // -----------------------------------------------------------------------
    // Locking
    // -----------------------------------------------------------------------

    /**
     * @throws UpdateException
     */
    private function acquireLock(): void
    {
        $path = rtrim((string) _PS_CACHE_DIR_, '/\\') . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            // Not fatal: an unwritable lock file must not block an otherwise valid
            // update. The double-submit guard in the UI still applies.
            $this->log('could not open the update lock file', 2);

            return;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            $this->log('another update is already running', 2);

            throw new UpdateException(UpdateException::STEP_LOCK, 'Another update is already running.');
        }

        $this->lockHandle = $handle;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }

        $this->lockHandle = null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function moduleDir(): string
    {
        return rtrim(_PS_MODULE_DIR_ . self::MODULE_NAME, '/\\');
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{message: string, params: array<string, string>}
     */
    private function blocker(string $message, array $params = []): array
    {
        return ['message' => $message, 'params' => $params];
    }

    private function randomSuffix(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            return (string) time();
        }
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        $length = strlen($needle);

        return $length > 0 && substr($haystack, -$length) === $needle;
    }

    private function allowUrlFopen(): bool
    {
        $value = ini_get('allow_url_fopen');

        return $value !== false && $value !== '' && $value !== '0' && strtolower((string) $value) !== 'off';
    }

    private function isFunctionDisabled(string $function): bool
    {
        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return false;
        }

        foreach (explode(',', $disabled) as $item) {
            if (strtolower(trim($item)) === strtolower($function)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $headers
     */
    private function extractStatusCode(array $headers): int
    {
        // Follows redirects, so the LAST status line is the one that matters.
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    private function sanitize(string $message): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($normalized === '') {
            return 'unexpected error';
        }

        return substr($normalized, 0, 180);
    }

    private function log(string $message, int $severity = 1): void
    {
        \PrestaShopLogger::addLog('[MDF][update] ' . $this->sanitize($message), $severity, null, 'Mdfcforps');
    }

    // -----------------------------------------------------------------------
    // Fault injection (development only)
    // -----------------------------------------------------------------------

    private function faultActive(string $fault): bool
    {
        if (!defined('_PS_MODE_DEV_') || !_PS_MODE_DEV_) {
            return false;
        }

        $configured = ModuleConfig::get(self::KEY_FAULT, '');
        if ($configured === '') {
            return false;
        }

        foreach (explode(',', $configured) as $item) {
            if (trim($item) === $fault) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws UpdateException
     */
    private function assertNoFault(string $fault, string $step, string $message): void
    {
        if ($this->faultActive($fault)) {
            throw new UpdateException($step, $message);
        }
    }

    // -----------------------------------------------------------------------
    // Filesystem primitives
    // -----------------------------------------------------------------------

    /**
     * Recursive delete. Mirrors Mdfcforps::removeDirectoryRecursively(), which is
     * private to the module class and unavailable outside a legacy context.
     */
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }

            @rmdir($directory);
        } catch (\Throwable $e) {
            // Best effort: a leftover staging directory is harmless.
        }
    }

    /**
     * Cross-device fallback for rename(). Copies a tree, preserving permissions.
     */
    public static function copyDirectory(string $source, string $destination): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
            return false;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

                if ($item->isDir()) {
                    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                        return false;
                    }

                    continue;
                }

                if (!@copy($item->getPathname(), $target)) {
                    return false;
                }

                @chmod($target, 0644);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}
