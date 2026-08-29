-- Marques de France PrestaShop Connector — install schema
-- Table 1: tracked sales
CREATE TABLE IF NOT EXISTS `PREFIX_mdfcforps_sales` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`            INT UNSIGNED NOT NULL,
    `order_reference`     VARCHAR(64)  NOT NULL DEFAULT '',
    `amount`              DECIMAL(20,6) NOT NULL DEFAULT 0,
    `net_amount`          DECIMAL(20,6) NOT NULL DEFAULT 0,
    `currency`            VARCHAR(8)   NOT NULL DEFAULT '',
    `attribution_source`  VARCHAR(64)  NOT NULL DEFAULT 'unknown',
    `utm_source`          VARCHAR(255) NOT NULL DEFAULT '',
    `utm_medium`          VARCHAR(255) NOT NULL DEFAULT '',
    `utm_campaign`        VARCHAR(255) NOT NULL DEFAULT '',
    `utm_content`         VARCHAR(255) NOT NULL DEFAULT '',
    `utm_term`            VARCHAR(255) NOT NULL DEFAULT '',
    `landing_site`        VARCHAR(2048) NOT NULL DEFAULT '',
    `referring_site`      VARCHAR(2048) NOT NULL DEFAULT '',
    `landing_ref`         VARCHAR(2048) NOT NULL DEFAULT '',
    `click_id`            VARCHAR(255) NOT NULL DEFAULT '',
    `status`              VARCHAR(32)  NOT NULL DEFAULT 'confirmed',
    `hub_synced`          TINYINT(1)   NOT NULL DEFAULT 0,
    `hub_sync_attempts`   TINYINT(3)   NOT NULL DEFAULT 0,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_id` (`order_id`),
    INDEX `idx_hub_synced` (`hub_synced`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: manually curated feed product list (SERVERLIST mode)
CREATE TABLE IF NOT EXISTS `PREFIX_mdfcforps_feed_products` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 3: attribution frozen against the cart, before payment
--
-- Written in the customer's own request while the mdf_* cookies are still readable,
-- and read back at actionValidateOrder time via $order->id_cart. This is what keeps a
-- redirect payment provider from losing the sale: the order is validated either by a
-- server-to-server IPN carrying no browser cookies at all, or by a cross-site POST on
-- which the SameSite=Lax cookies this module writes are withheld. Both resolve to
-- "unknown" from cookies alone, and the attribution gate then discards the order for
-- good — no local row, nothing sent to the Hub, nothing retryable.
--
-- Only Marques de France attributed carts ever get a row here.
CREATE TABLE IF NOT EXISTS `PREFIX_mdfcforps_cart_attribution` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_cart`             INT UNSIGNED NOT NULL,
    `attribution_source`  VARCHAR(64)  NOT NULL DEFAULT 'unknown',
    `click_id`            VARCHAR(255) NOT NULL DEFAULT '',
    `landing_ref`         VARCHAR(2048) NOT NULL DEFAULT '',
    `utm_source`          VARCHAR(255) NOT NULL DEFAULT '',
    `utm_medium`          VARCHAR(255) NOT NULL DEFAULT '',
    `utm_campaign`        VARCHAR(255) NOT NULL DEFAULT '',
    `utm_content`         VARCHAR(255) NOT NULL DEFAULT '',
    `utm_term`            VARCHAR(255) NOT NULL DEFAULT '',
    `landing_site`        VARCHAR(2048) NOT NULL DEFAULT '',
    `referring_site`      VARCHAR(2048) NOT NULL DEFAULT '',
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_id_cart` (`id_cart`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
