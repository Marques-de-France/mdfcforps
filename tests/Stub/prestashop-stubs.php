<?php
/**
 * Minimal PrestaShop doubles for the unit test suite.
 *
 * The module reads its attribution signals from the PrestaShop session cookie
 * before falling back to $_COOKIE, so the session path needs a stand-in to be
 * testable outside a real shop. Each double is guarded by class_exists so this
 * file is inert if a real PrestaShop is ever loaded alongside it.
 *
 * Configuration is deliberately NOT stubbed: its absence is what proves the
 * class_exists guard in AttributionService::shopHosts() does its job.
 *
 * tests/ is export-ignored, so none of this reaches the release archive.
 *
 * @author Marques de France
 * @copyright Copyright (c) Marques de France
 * @license   AFL-3.0 Academic Free License 3.0
 */

declare(strict_types=1);

if (!class_exists('Cookie')) {
    class Cookie
    {
        /** @var array<string, mixed> */
        private $content = [];

        /** @var int */
        public $writeCalls = 0;

        /**
         * @param string $key
         * @param mixed  $value
         */
        public function __set($key, $value)
        {
            // Mirrors PrestaShop: "|" and "¤" are the internal delimiters and throw.
            if (is_string($value) && (strpos($value, '|') !== false || strpos($value, '¤') !== false)) {
                throw new Exception('Forbidden character in cookie value');
            }

            $this->content[$key] = $value;
        }

        /**
         * @param string $key
         *
         * @return mixed false when absent, matching PrestaShop
         */
        public function __get($key)
        {
            return $this->content[$key] ?? false;
        }

        /**
         * @param string $key
         */
        public function __isset($key): bool
        {
            return isset($this->content[$key]);
        }

        public function write(): void
        {
            ++$this->writeCalls;
        }
    }
}

if (!class_exists('Context')) {
    class Context
    {
        /** @var Context|null */
        public static $instance;

        /** @var Cookie|null */
        public $cookie;

        /** @var object|null */
        public $shop;

        public static function getContext(): ?Context
        {
            return self::$instance;
        }

        public static function resetForTests(): void
        {
            self::$instance = null;
        }
    }
}

if (!class_exists('Tools')) {
    class Tools
    {
        /** @var string */
        public static $shopDomain = 'boutique.test';

        public static function getShopDomain(): string
        {
            return self::$shopDomain;
        }

        public static function getShopDomainSsl(): string
        {
            return self::$shopDomain;
        }
    }
}
