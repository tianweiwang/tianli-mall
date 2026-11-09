-- Additive preview-only schema. Apply to the explicitly selected 18218 test database.
CREATE TABLE IF NOT EXISTS `eb_marketplace_combined_checkout` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `uid` INT UNSIGNED NOT NULL,
  `address_id` INT UNSIGNED NOT NULL,
  `quote_data` JSON NOT NULL,
  `total_amount` DECIMAL(12,2) UNSIGNED NOT NULL,
  `state` ENUM('quoted','pending','paid','cancelled') NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  `create_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payment_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `test_trade_no` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `paid_at` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `checkout_no` (`order_no`),
  UNIQUE KEY `test_trade_no` (`test_trade_no`),
  KEY `owner_history` (`uid`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_marketplace_combined_checkout_order` (
  `checkout_id` INT UNSIGNED NOT NULL,
  `root_order_id` INT UNSIGNED NOT NULL,
  `mer_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) UNSIGNED NOT NULL,
  PRIMARY KEY (`root_order_id`),
  UNIQUE KEY `checkout_shop` (`checkout_id`,`mer_id`),
  CONSTRAINT `checkout_order_group` FOREIGN KEY (`checkout_id`)
    REFERENCES `eb_marketplace_combined_checkout` (`id`),
  CONSTRAINT `checkout_order_root` FOREIGN KEY (`root_order_id`)
    REFERENCES `eb_store_order` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
