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
 * Tracks which module version the Hub announces as the latest.
 *
 * The announcement rides along on GET /api/ps/status, which the dashboard already
 * calls on every load — so the common path costs no extra HTTP request. The result
 * is cached in Configuration so the "update available" banner still renders when
 * the Hub is unreachable, which is exactly when a merchant most needs to see it.
 *
 * Nothing here ever throws: an update check failing must never break a dashboard
 * render or a storefront request.
 */
final class UpdateChecker
{
    /** Announcement older than this is considered stale on the dashboard. */
    public const TTL_SECONDS = 21600; // 6h

    /** Announcement older than this triggers a refresh from the lazy cron. */
    public const CRON_TTL_SECONDS = 86400; // 24h

    public const KEY_INFO = 'MDFCFORPS_UPDATE_INFO';
    public const KEY_CHECKED_AT = 'MDFCFORPS_UPDATE_CHECKED_AT';

    /**
     * Store the `update` block from an already-fetched /status payload.
     *
     * Call this right after HubClient::getStatus() rather than issuing a second
     * request. An absent `update` key means "nothing announced" — never an error —
     * and clears any previously cached announcement so a Hub-side kill switch
     * takes effect on the next dashboard load.
     *
     * @param array<string, mixed> $status
     */
    public function ingestStatus(array $status): void
    {
        try {
            $update = isset($status['update']) && is_array($status['update']) ? $status['update'] : [];

            if (empty($update['latestVersion']) || empty($update['downloadUrl'])) {
                $this->clear();

                return;
            }

            $announcement = [
                'latestVersion' => (string) $update['latestVersion'],
                'downloadUrl' => (string) $update['downloadUrl'],
                'sha256' => (string) ($update['sha256'] ?? ''),
                'size' => (int) ($update['size'] ?? 0),
                'minPhpVersion' => (string) ($update['minPhpVersion'] ?? ''),
                'minPsVersion' => (string) ($update['minPsVersion'] ?? ''),
                'releaseNotesUrl' => (string) ($update['releaseNotesUrl'] ?? ''),
                'mandatory' => !empty($update['mandatory']),
                'checkedAt' => time(),
            ];

            ModuleConfig::update(self::KEY_INFO, base64_encode((string) json_encode($announcement)));
            ModuleConfig::update(self::KEY_CHECKED_AT, (string) time());
        } catch (\Throwable $e) {
            // An unusable announcement is indistinguishable from none.
        }
    }

    /**
     * Fetch /status and ingest the announcement.
     *
     * Used by the lazy cron and the manual "Check for updates" button — the
     * dashboard uses ingestStatus() instead, since it already has the payload.
     *
     * @return bool false on any failure (network, auth, malformed response)
     */
    public function refresh(): bool
    {
        try {
            $hubClient = new HubClient();
            $this->ingestStatus($hubClient->getStatus());

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isStale(int $ttl = self::TTL_SECONDS): bool
    {
        return (time() - ModuleConfig::getInt(self::KEY_CHECKED_AT, 0)) >= $ttl;
    }

    /**
     * @return array<string, mixed> empty when nothing is cached
     */
    public function getAnnouncement(): array
    {
        $raw = ModuleConfig::get(self::KEY_INFO, '');
        if ($raw === '') {
            return [];
        }

        $json = base64_decode($raw, true);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The version currently installed on disk.
     *
     * Deliberately does not reference \Mdfcforps::VERSION unguarded: that class lives
     * in mdfcforps.php and is loaded by PrestaShop only in a legacy module context,
     * so referencing it from a Symfony admin controller or the front controller
     * fatals with "Class \"Mdfcforps\" not found". Same trap documented in
     * HubClient::resolveModuleVersion().
     *
     * config.xml is preferred over the ps_module row as the second source: after a
     * self-update the files are new but the DB row still holds the old version until
     * the upgrade runs, and every caller here means "what is on disk".
     */
    public function getInstalledVersion(): string
    {
        if (class_exists('\Mdfcforps') && defined('\Mdfcforps::VERSION')) {
            return (string) \Mdfcforps::VERSION;
        }

        $version = $this->readConfigXmlVersion(_PS_MODULE_DIR_ . 'mdfcforps/config.xml');
        if ($version !== '') {
            return $version;
        }

        try {
            $dbVersion = \Db::getInstance()->getValue(
                'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = "mdfcforps"'
            );

            return $dbVersion ? (string) $dbVersion : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Read <version> out of a module config.xml.
     *
     * Shared with ModuleUpdater, which validates the version inside a downloaded
     * package before installing it.
     *
     * @param string $path absolute path to a config.xml file
     *
     * @return string '' when the file is missing or unparseable
     */
    public function readConfigXmlVersion(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $xml = (string) @file_get_contents($path);

        return $this->parseConfigXmlVersion($xml);
    }

    /**
     * @param string $xml raw config.xml contents
     */
    public function parseConfigXmlVersion(string $xml): string
    {
        if (trim($xml) === '') {
            return '';
        }

        // The XML comes from a downloaded archive, so entity expansion must be off:
        // a hostile config.xml could otherwise read local files via XXE. On PHP 8+
        // external entity loading is off by default and the loader function is a
        // deprecated no-op, hence the version guard.
        $previous = null;
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previous = libxml_disable_entity_loader(true);
        }

        $useInternal = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        } catch (\Throwable $e) {
            $element = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternal);
            if ($previous !== null) {
                libxml_disable_entity_loader($previous);
            }
        }

        if ($element === false || !isset($element->version)) {
            return '';
        }

        return trim((string) $element->version);
    }

    /**
     * True when the announced version is strictly newer than what is installed.
     */
    public function isUpdateAvailable(): bool
    {
        $announcement = $this->getAnnouncement();
        if (empty($announcement['latestVersion'])) {
            return false;
        }

        $installed = $this->getInstalledVersion();
        if ($installed === '') {
            // Version unknown — offering an update would risk a pointless reinstall
            // or a downgrade. Stay silent.
            return false;
        }

        return version_compare((string) $announcement['latestVersion'], $installed, '>');
    }

    public function isMandatory(): bool
    {
        $announcement = $this->getAnnouncement();

        return $this->isUpdateAvailable() && !empty($announcement['mandatory']);
    }

    /**
     * Forget the cached announcement. Called after a successful update, and when
     * the Hub stops announcing (kill switch).
     */
    public function clear(): void
    {
        ModuleConfig::update(self::KEY_INFO, '');
        ModuleConfig::update(self::KEY_CHECKED_AT, (string) time());
    }
}
