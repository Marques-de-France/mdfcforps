<?php

/**
 * Module source file.
 *
 * @author Marques de France
 */

declare(strict_types=1);

namespace Mdfcforps\Install;

use Mdfcforps\Service\ModuleConfig;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    // -----------------------------------------------------------------------
    // Install
    // -----------------------------------------------------------------------

    public function install(): bool
    {
        if (!$this->installSql()) {
            return false;
        }

        if (!$this->installAdminTab()) {
            return false;
        }

        \Configuration::updateValue('MDFCFORPS_DB_VERSION', \Mdfcforps::DB_VERSION);
        ModuleConfig::update('MDFCFORPS_FEED_FILTER_MODE', 'TAG');
        ModuleConfig::update('MDFCFORPS_BACKFILL_DONE', 0);
        ModuleConfig::update('MDFCFORPS_LAST_FLUSH', 0);

        return true;
    }

    // -----------------------------------------------------------------------
    // Uninstall
    // -----------------------------------------------------------------------

    public function uninstall(): bool
    {
        $this->uninstallSql();
        $this->uninstallAdminTab();

        $keys = [
            'MDFCFORPS_SECURE_TOKEN',
            'MDFCFORPS_DB_VERSION',
            'MDFCFORPS_BACKFILL_DONE',
            'MDFCFORPS_FEED_FILTER_MODE',
            'MDFCFORPS_LAST_FLUSH',
        ];

        foreach ($keys as $key) {
            \Configuration::deleteByName($key);
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Self-registration with Hub
    // -----------------------------------------------------------------------

    public function selfRegister(): void
    {
        $hubClient = new \Mdfcforps\Service\HubClient();

        try {
            $result = $hubClient->selfRegister();

            if (!empty($result['secureToken'])) {
                ModuleConfig::update('MDFCFORPS_SECURE_TOKEN', $result['secureToken']);
            }
        } catch (\Throwable $e) {
            // Non-fatal: token can arrive later via lazy-cron retry
        }
    }

    // -----------------------------------------------------------------------
    // SQL helpers
    // -----------------------------------------------------------------------

    private function installSql(): bool
    {
        $sql = file_get_contents(__DIR__ . '/../../sql/install.sql');

        if ($sql === false) {
            return false;
        }

        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        // Split on semicolons to execute each statement separately
        $statements = array_filter(
            array_map('trim', explode(';', $sql))
        );

        foreach ($statements as $statement) {
            if (!\Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallSql(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../sql/uninstall.sql');

        if ($sql === false) {
            return;
        }

        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql))
        );

        foreach ($statements as $statement) {
            \Db::getInstance()->execute($statement);
        }
    }

    // -----------------------------------------------------------------------
    // Admin tab helpers
    // -----------------------------------------------------------------------

    private function installAdminTab(): bool
    {
        $tab = new \Tab();
        $tab->active = true;
        $tab->class_name = 'AdminMdfcforps';
        $tab->module = $this->module->name;
        $tab->id_parent = $this->getTabIdByClassName('DEFAULT'); // hidden tab
        $tab->icon = 'hexagon';

        foreach (\Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Marques de France';
        }

        return (bool) $tab->add();
    }

    private function uninstallAdminTab(): void
    {
        $tabId = $this->getTabIdByClassName('AdminMdfcforps');
        if ($tabId > 0) {
            $tab = new \Tab($tabId);
            $tab->delete();
        }
    }

    private function getTabIdByClassName(string $className): int
    {
        $query = new \DbQuery();
        $query->select('id_tab')
            ->from('tab')
            ->where("class_name = '" . pSQL($className) . "'")
            ->orderBy('id_tab ASC')
            ->limit(1);

        return (int) \Db::getInstance()->getValue($query);
    }
}
