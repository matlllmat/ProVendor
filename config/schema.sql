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
    -- 'manual' = the owner presses "Re-forecast all"; 'auto' = the app tops the
    -- window back up to forecast_horizon_days by itself as days elapse.
    `forecast_mode`         ENUM('manual','auto') NOT NULL DEFAULT 'manual',
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
    -- Perishability — declared by the owner via the forecast page's batch
    -- editor, NOT derived from the CSV (a real POS export has no expiry column).
    -- is_perishable defaults off, matching most of a sari-sari catalogue.
    -- shelf_life_min/max hold the owner's estimated "usual range" (e.g. pandesal
    -- 2-4 days); the Newsvendor uses the MINIMUM as the effective shelf life —
    -- a conservative choice, since overordering a perishable item (spoilage) is
    -- the costly mistake this feature exists to prevent, and an occasional early
    -- stockout is the cheaper error. Used to cap the ordering window (a 3-day
    -- item is never stocked to a 30-day horizon) and decide whether leftover
    -- stock carries over (salvage credit) or is a total loss.
    `is_perishable`          TINYINT(1)    NOT NULL DEFAULT 0,
    `shelf_life_min_days`    INT           DEFAULT NULL,
    `shelf_life_max_days`    INT           DEFAULT NULL,
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
    -- A store has exactly ONE dataset: each upload replaces it rather than being
    -- stitched into what's there. A product that isn't in the newly uploaded file
    -- is therefore no longer sold — but deleting it would throw away the owner's
    -- pricing edits, horizon overrides and accuracy history, and orphan the
    -- snapshots that earlier versions still reference. So it's deactivated
    -- instead: kept in the catalogue (greyed, with an explanation) and excluded
    -- from forecasting. Re-appearing in a later upload reactivates it.
    `is_active`              TINYINT(1)    NOT NULL DEFAULT 1,
    `deactivated_at`         TIMESTAMP     NULL DEFAULT NULL,
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
-- Perishability (revised): owner-declared via the batch editor, not derived from
-- import. Supersedes an earlier `shelf_life_days` single-value column.
--   ALTER TABLE `products`
--     DROP COLUMN `shelf_life_days`,
--     ADD COLUMN `is_perishable`       TINYINT(1) NOT NULL DEFAULT 0,
--     ADD COLUMN `shelf_life_min_days` INT DEFAULT NULL,
--     ADD COLUMN `shelf_life_max_days` INT DEFAULT NULL;
-- Single-dataset model (added later): products absent from the current upload are
-- deactivated rather than deleted, so their settings and history survive.
--   ALTER TABLE `products`
--     ADD COLUMN `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
--     ADD COLUMN `deactivated_at` TIMESTAMP  NULL DEFAULT NULL;

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
    `recurrence`  ENUM('none','yearly','monthly','custom') NOT NULL DEFAULT 'none',
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

-- Concrete dates for `custom` events - irregular happenings that follow no
-- calendar rule (storms, movable holidays like Holy Week or Chinese New Year).
-- One row per occurrence; end_date NULL means a single-day occurrence.
-- Only read when seasonal_events.recurrence = 'custom'; the other recurrence
-- types generate their dates from event_start instead.
--
-- For existing databases, run:
--   ALTER TABLE `seasonal_events`
--     MODIFY `recurrence` ENUM('none','yearly','monthly','custom')
--     NOT NULL DEFAULT 'none';
--   (then the CREATE TABLE below)
CREATE TABLE IF NOT EXISTS `event_occurrences` (
    `id`         INT  NOT NULL AUTO_INCREMENT,
    `event_id`   INT  NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date`   DATE NULL,                    -- NULL = single-day occurrence
    PRIMARY KEY (`id`),
    KEY `idx_event_occurrences_event` (`event_id`, `start_date`),
    CONSTRAINT `fk_event_occurrences_event`
        FOREIGN KEY (`event_id`) REFERENCES `seasonal_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    -- 'full'   = refit Prophet for every product (import / horizon change)
    -- 'extend' = only forecast the days the saved window is missing
    `mode`            ENUM('full','extend') NOT NULL DEFAULT 'full',
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

-- ── Linked Google Sheet ───────────────────────────────────────────────────────
-- A store owner can link one Google Sheet as the live source of their daily
-- sales instead of re-uploading CSVs. The sheet is read through a service
-- account (creds/service-account.json) by the Flask server's /sheets/read.
-- While a link exists, CSV import is disabled for that owner — two writers into
-- the same sales table would fight over the same (product, date) rows.
CREATE TABLE IF NOT EXISTS `sheet_links` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `user_id`           INT          NOT NULL,
    `spreadsheet_id`    VARCHAR(120) NOT NULL,
    `sheet_url`         VARCHAR(500) NOT NULL,
    `sheet_title`       VARCHAR(255) DEFAULT NULL,
    `worksheet_title`   VARCHAR(255) DEFAULT NULL,
    -- The column mapping the owner confirmed at link time, as JSON
    -- ({"date":"Date","product":"Item",...}). Re-used verbatim by every sync so
    -- the 5-minute refresh never has to re-guess which column means what.
    `column_mapping`    TEXT         NOT NULL,
    `date_format`       VARCHAR(20)  DEFAULT NULL,
    -- 1 = the browser heartbeat may re-sync every 5 minutes; 0 = the owner
    -- refreshes by hand with the "Update Data" button.
    `auto_sync`         TINYINT(1)   NOT NULL DEFAULT 1,
    `last_synced_at`    DATETIME     DEFAULT NULL,
    `last_sync_status`  ENUM('ok','error') DEFAULT NULL,
    `last_sync_error`   VARCHAR(500) DEFAULT NULL,
    `last_sync_added`   INT          NOT NULL DEFAULT 0,
    `last_sync_updated` INT          NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One linked sheet per owner: the whole feature is "this sheet is my data".
    UNIQUE KEY `sheet_links_user_unique` (`user_id`),
    CONSTRAINT `fk_sl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Backtest-with-your-own-data runs (Reports tab, optional/secondary test) ──
-- Only the MOST RECENT upload-backtest is kept per owner — a new run overwrites
-- it, and "Clear" deletes it outright. The uploaded CSV itself is kept on disk
-- (uploads/backtest_<user_id>.csv) so the owner can re-download exactly what
-- they tested with; stored_path here is what api/download_backtest_csv.php and
-- api/clear_backtest.php act on.
CREATE TABLE IF NOT EXISTS `backtest_runs` (
    `id`                     INT           NOT NULL AUTO_INCREMENT,
    `user_id`                INT           NOT NULL,
    `original_filename`      VARCHAR(255)  NOT NULL,
    `stored_path`            VARCHAR(255)  NOT NULL,
    `evaluated_count`        INT           NOT NULL DEFAULT 0,
    `total_count`            INT           NOT NULL DEFAULT 0,
    `weighted_mape`          DECIMAL(8,2)  DEFAULT NULL,
    `weighted_mae`           DECIMAL(8,2)  DEFAULT NULL,
    `weighted_rmse`          DECIMAL(8,2)  DEFAULT NULL,
    `weighted_accuracy_pct`  DECIMAL(5,2)  DEFAULT NULL,
    `rows_parsed`            INT           NOT NULL DEFAULT 0,
    `rows_dropped`           INT           NOT NULL DEFAULT 0,
    `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `backtest_runs_user_unique` (`user_id`),
    CONSTRAINT `fk_br_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
