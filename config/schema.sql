-- ProVendor Database Schema
CREATE DATABASE provendor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE provendor;

CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(100) NOT NULL,
    `store_name` VARCHAR(100) NOT NULL,
    `email`      VARCHAR(150) NOT NULL,
    `password`   VARCHAR(255) NOT NULL,
    -- Global forecast horizon in days (1–60), chosen at onboarding and editable on
    -- the Settings page. Every product forecasts this many days out unless it has
    -- its own products.forecast_horizon_days override.
    `forecast_horizon_days` INT NOT NULL DEFAULT 30,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
    `id`                     INT           NOT NULL AUTO_INCREMENT,
    `user_id`                INT           NOT NULL,
    `name`                   VARCHAR(100)  NOT NULL,
    `sku`                    VARCHAR(100)  DEFAULT NULL,
    `category`               VARCHAR(50)   DEFAULT NULL,
    `subcategory`            VARCHAR(50)   DEFAULT NULL,
    `cost_price`             DECIMAL(10,2) DEFAULT NULL,
    `selling_price`          DECIMAL(10,2) DEFAULT NULL,
    -- The price the imported dataset originally provided. cost_price/selling_price
    -- above are the EFFECTIVE (editable) values used by the forecast/Newsvendor;
    -- orig_* preserve the imported value so the forecast page's "Reset to imported
    -- price" can restore it. Captured on import (upsertProduct).
    `orig_cost_price`        DECIMAL(10,2) DEFAULT NULL,
    `orig_selling_price`     DECIMAL(10,2) DEFAULT NULL,
    -- Per-product forecast horizon override in days. NULL = use the user's
    -- global users.forecast_horizon_days. Set from the inline "Forecast range"
    -- control on the forecast page.
    `forecast_horizon_days`  INT           DEFAULT NULL,
    -- Forecast accuracy cache, populated by /forecast/product/evaluate.
    -- accuracy_pct = 100 - MAPE on a held-out window. residual_rho is the
    -- lag-1 autocorrelation of backtest residuals, fed back into /optimize
    -- to widen σ for products whose demand actually clusters day-to-day.
    `accuracy_pct`           DECIMAL(5,2)  DEFAULT NULL,
    `accuracy_mape`          DECIMAL(8,2)  DEFAULT NULL,
    `accuracy_mae`           DECIMAL(8,2)  DEFAULT NULL,
    `accuracy_rmse`          DECIMAL(8,2)  DEFAULT NULL,
    `accuracy_horizon_days`  INT           DEFAULT NULL,
    `accuracy_residual_rho`  DECIMAL(6,4)  DEFAULT NULL,
    `accuracy_computed_at`   TIMESTAMP     NULL DEFAULT NULL,
    `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_products_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For existing databases, add the accuracy cache columns:
--   ALTER TABLE `products`
--     ADD COLUMN `accuracy_pct`          DECIMAL(5,2) DEFAULT NULL,
--     ADD COLUMN `accuracy_mape`         DECIMAL(8,2) DEFAULT NULL,
--     ADD COLUMN `accuracy_mae`          DECIMAL(8,2) DEFAULT NULL,
--     ADD COLUMN `accuracy_rmse`         DECIMAL(8,2) DEFAULT NULL,
--     ADD COLUMN `accuracy_horizon_days` INT          DEFAULT NULL,
--     ADD COLUMN `accuracy_residual_rho` DECIMAL(6,4) DEFAULT NULL,
--     ADD COLUMN `accuracy_computed_at`  TIMESTAMP    NULL DEFAULT NULL;
-- Forecast horizon columns (added later):
--   ALTER TABLE `users`    ADD COLUMN `forecast_horizon_days` INT NOT NULL DEFAULT 30;
--   ALTER TABLE `products` ADD COLUMN `forecast_horizon_days` INT DEFAULT NULL;
-- Original imported price columns (added later, for "Reset to imported price"):
--   ALTER TABLE `products`
--     ADD COLUMN `orig_cost_price`    DECIMAL(10,2) DEFAULT NULL,
--     ADD COLUMN `orig_selling_price` DECIMAL(10,2) DEFAULT NULL;
--   UPDATE `products` SET `orig_cost_price` = `cost_price`, `orig_selling_price` = `selling_price`;

CREATE TABLE IF NOT EXISTS `sales` (
    `id`            INT       NOT NULL AUTO_INCREMENT,
    `product_id`    INT       NOT NULL,
    `quantity_sold` INT       NOT NULL,
    `sale_date`     DATE      NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Daily aggregation invariant: one row per (product, day). The importer
    -- already enforces this in PHP, but a UNIQUE key makes it true at the DB
    -- level too — so a concurrent import or a stray INSERT can't sneak past it.
    UNIQUE KEY `sales_product_date_unique` (`product_id`, `sale_date`),
    CONSTRAINT `fk_sales_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For existing databases:
--   ALTER TABLE `sales` ADD UNIQUE KEY `sales_product_date_unique` (`product_id`, `sale_date`);
--   -- Drop the legacy import_session_id column (FK first, then the column).
--   ALTER TABLE `sales` DROP FOREIGN KEY `fk_sales_import`;
--   ALTER TABLE `sales` DROP COLUMN `import_session_id`;
--   DROP TABLE `import_sessions`;

-- ── Dataset versions ────────────────────────────────────────────────────────
-- Each import auto-creates a version snapshotting the resulting sales table.
-- Restores create an extra pre-restore snapshot first so "undo the undo" works.
-- Versions are capped per user (oldest auto-versions are pruned on overflow).
CREATE TABLE IF NOT EXISTS `dataset_versions` (
    `id`                      INT          NOT NULL AUTO_INCREMENT,
    `user_id`                 INT          NOT NULL,
    `label`                   VARCHAR(150) NOT NULL,
    `note`                    TEXT         DEFAULT NULL,
    `rows_added`              INT          NOT NULL DEFAULT 0,
    `rows_changed`            INT          NOT NULL DEFAULT 0,
    `total_rows`              INT          NOT NULL DEFAULT 0,
    -- is_pre_restore_snapshot = 1 marks the safety snapshot taken right before
    -- a restore. The UI shows these with a distinct icon ("auto-saved before
    -- restoring v3") so they don't look like regular edits.
    `is_pre_restore_snapshot` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `dataset_versions_user_idx` (`user_id`, `created_at`),
    CONSTRAINT `fk_dataset_versions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sales_snapshots` (
    `version_id`    INT NOT NULL,
    `product_id`    INT NOT NULL,
    `sale_date`     DATE NOT NULL,
    `quantity_sold` INT NOT NULL,
    PRIMARY KEY (`version_id`, `product_id`, `sale_date`),
    KEY `sales_snapshots_version_idx` (`version_id`),
    CONSTRAINT `fk_sales_snapshots_version` FOREIGN KEY (`version_id`) REFERENCES `dataset_versions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sales_snapshots_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `forecasts` (
    `id`                    INT           NOT NULL AUTO_INCREMENT,
    `product_id`            INT           NOT NULL,
    `forecast_date`         DATE          NOT NULL,
    `predicted_demand`      DECIMAL(10,2) NOT NULL,
    -- Prophet confidence band per day (yhat_lower / yhat_upper). Persisted so the
    -- forecast page can draw the band inline from saved data, without re-running.
    `predicted_lower`       DECIMAL(10,2) DEFAULT NULL,
    `predicted_upper`       DECIMAL(10,2) DEFAULT NULL,
    `restock_qty`           INT           DEFAULT NULL,
    `cost_price`            DECIMAL(10,2) DEFAULT NULL,
    `selling_price`         DECIMAL(10,2) DEFAULT NULL,
    `current_stock`         INT           DEFAULT NULL,
    `total_std`             DECIMAL(10,2) DEFAULT NULL,
    `optimal_total`         INT           DEFAULT NULL,
    `est_profit`            DECIMAL(12,2) DEFAULT NULL,
    -- AR(1) variance correction inputs/outputs captured at save time so the
    -- reports page can show the same Newsvendor disclosure as the live modal.
    -- rho_used: lag-1 residual autocorrelation passed into /optimize.
    -- std_inflation_factor: how much σ was widened (1.0 = no correction).
    `rho_used`              DECIMAL(6,4)  DEFAULT NULL,
    `std_inflation_factor`  DECIMAL(6,4)  DEFAULT NULL,
    -- Per-day Prophet component breakdown (trend, weekly, yearly, events).
    -- Populated at save time from the Flask response; used by the "Why this
    -- forecast?" panel in the Reports page detail modal.
    `components`            JSON          DEFAULT NULL,
    `generated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_forecasts_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- For existing databases, run migrations in order:
--   ALTER TABLE `forecasts`
--     ADD COLUMN `rho_used`             DECIMAL(6,4) DEFAULT NULL,
--     ADD COLUMN `std_inflation_factor` DECIMAL(6,4) DEFAULT NULL;
--   ALTER TABLE `forecasts`
--     ADD COLUMN `components` JSON DEFAULT NULL;

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
  (103, NULL, 'End-of-month Payday', '2024-01-31', NULL, 'monthly', 1, 1, '#059669'),
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
-- ── Background forecast jobs ──────────────────────────────────────────────────
-- Forecasting the whole catalogue can take minutes (or much longer for hundreds
-- of products), so it runs in a detached CLI worker (cli/forecast_worker.php)
-- instead of blocking the browser. This table is the job's state + progress,
-- polled by the floating progress pill (includes/forecast_progress.php).
CREATE TABLE IF NOT EXISTS `forecast_jobs` (
    `id`              INT           NOT NULL AUTO_INCREMENT,
    `user_id`         INT           NOT NULL,
    `status`          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    `horizon_days`    INT           NOT NULL DEFAULT 30,
    `total`           INT           NOT NULL DEFAULT 0,
    `done`            INT           NOT NULL DEFAULT 0,
    `failed`          INT           NOT NULL DEFAULT 0,
    `current_product` VARCHAR(150)  DEFAULT NULL,
    `error`           TEXT          DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `finished_at`     TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_fj_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_fj_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
