-- ProVendor Database Schema
--   CREATE DATABASE provendor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   USE provendor;

CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT           NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100)  NOT NULL,
    `store_name` VARCHAR(100)  NOT NULL,
    `email`      VARCHAR(150)  NOT NULL,
    `password`   VARCHAR(255)  NOT NULL,
    `lat`        DECIMAL(10,7) DEFAULT NULL,
    `lng`        DECIMAL(10,7) DEFAULT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
    `id`            INT           NOT NULL AUTO_INCREMENT,
    `user_id`       INT           NOT NULL,
    `name`          VARCHAR(100)  NOT NULL,
    `sku`           VARCHAR(100)  DEFAULT NULL,
    `category`      VARCHAR(50)   DEFAULT NULL,
    `subcategory`   VARCHAR(50)   DEFAULT NULL,
    `cost_price`    DECIMAL(10,2) DEFAULT NULL,
    `selling_price` DECIMAL(10,2) DEFAULT NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_products_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- import_sessions defined before sales so the FK reference is valid
CREATE TABLE IF NOT EXISTS `import_sessions` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `user_id`        INT          NOT NULL,
    `filename`       VARCHAR(255) NOT NULL,
    `column_mapping` JSON         DEFAULT NULL,
    `granularity`    VARCHAR(20)  DEFAULT NULL,
    `imported_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_import_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sales` (
    `id`                INT       NOT NULL AUTO_INCREMENT,
    `product_id`        INT       NOT NULL,
    `import_session_id` INT       DEFAULT NULL,
    `quantity_sold`     INT       NOT NULL,
    `sale_date`         DATE      NOT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_sales_product` FOREIGN KEY (`product_id`)        REFERENCES `products` (`id`)        ON DELETE CASCADE,
    CONSTRAINT `fk_sales_import`  FOREIGN KEY (`import_session_id`) REFERENCES `import_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `forecasts` (
    `id`               INT           NOT NULL AUTO_INCREMENT,
    `product_id`       INT           NOT NULL,
    `forecast_date`    DATE          NOT NULL,
    `predicted_demand` DECIMAL(10,2) NOT NULL,
    `restock_qty`      INT           DEFAULT NULL,
    `cost_price`       DECIMAL(10,2) DEFAULT NULL,
    `selling_price`    DECIMAL(10,2) DEFAULT NULL,
    `current_stock`    INT           DEFAULT NULL,
    `total_std`        DECIMAL(10,2) DEFAULT NULL,
    `optimal_total`    INT           DEFAULT NULL,
    `est_profit`       DECIMAL(12,2) DEFAULT NULL,
    `generated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_forecasts_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `seasonal_events` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `user_id`     INT          NULL,                              -- NULL = global preset
    `name`        VARCHAR(100) NOT NULL,
    `event_start` DATE         NOT NULL,
    `event_end`   DATE         NULL,                             -- NULL = single-day event
    `recurrence`  ENUM('none','yearly','monthly') NOT NULL DEFAULT 'none',
    `is_last_day` TINYINT(1)   NOT NULL DEFAULT 0,               -- monthly: use last day of month
    `is_seeded`   TINYINT(1)   NOT NULL DEFAULT 0,
    `color`       VARCHAR(7)   NOT NULL DEFAULT '#FF5722',
    `impact_note`    TEXT         DEFAULT NULL,
    `avg_impact_pct` DECIMAL(6,1) DEFAULT NULL,  -- cached: avg % impact across all products
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_seasonal_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seeded global events (user_id IS NULL, is_seeded = 1)
INSERT IGNORE INTO `seasonal_events` (`id`, `user_id`, `name`, `event_start`, `event_end`, `recurrence`, `is_last_day`, `is_seeded`, `color`) VALUES
  (101, NULL, 'New Year\'s Day',     '2024-01-01', NULL, 'yearly',  0, 1, '#3B82F6'),
  (102, NULL, 'Mid-month Payday',    '2024-01-15', NULL, 'monthly', 0, 1, '#10B981'),
  (103, NULL, 'End-of-month Payday', '2024-01-31', NULL, 'monthly', 1, 1, '#059669'),
  (104, NULL, 'All Souls Day',       '2024-11-02', NULL, 'yearly',  0, 1, '#8B5CF6'),
  (105, NULL, 'Christmas Eve',       '2024-12-24', NULL, 'yearly',  0, 1, '#F59E0B'),
  (106, NULL, 'Christmas Day',       '2024-12-25', NULL, 'yearly',  0, 1, '#EF4444'),
  (107, NULL, 'New Year\'s Eve',     '2024-12-31', NULL, 'yearly',  0, 1, '#6366F1');

-- Prophet regressor coefficient cache — populated during forecast runs, read by the events page.
-- coefficient is in additive mode (same units as daily sales quantity).
-- impact_pct = coefficient / mean_daily_sales * 100 (computed on read).
CREATE TABLE IF NOT EXISTS `event_impact_cache` (
    `event_id`         INT           NOT NULL,
    `product_id`       INT           NOT NULL,
    `coefficient`      DECIMAL(10,4) NOT NULL,
    `mean_daily_sales` DECIMAL(10,4) NOT NULL,
    `occurrence_count` INT           NOT NULL DEFAULT 0,
    `computed_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`, `product_id`),
    CONSTRAINT `fk_eic_event`   FOREIGN KEY (`event_id`)   REFERENCES `seasonal_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_eic_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user event hiding: lets users suppress preset events without global deletion.
CREATE TABLE IF NOT EXISTS `user_hidden_events` (
    `user_id`  INT NOT NULL,
    `event_id` INT NOT NULL,
    PRIMARY KEY (`user_id`, `event_id`),
    CONSTRAINT `fk_uhe_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`)           ON DELETE CASCADE,
    CONSTRAINT `fk_uhe_event` FOREIGN KEY (`event_id`) REFERENCES `seasonal_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;