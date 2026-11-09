-- Apply once to databases using the older category/subcategory and MAPE schema.
-- Adds the schema required by the current store setup, import and forecast code.
USE provendor;

ALTER TABLE users
    ADD COLUMN forecast_reminder_days INT NOT NULL DEFAULT 10 AFTER forecast_horizon_days,
    ADD COLUMN events_changed_at DATETIME DEFAULT NULL AFTER forecast_reminder_days;

ALTER TABLE products
    ADD COLUMN accuracy_wape DECIMAL(8,2) DEFAULT NULL,
    ADD COLUMN accuracy_actual_units DECIMAL(12,2) DEFAULT NULL,
    ADD COLUMN prophet_run_at DATETIME DEFAULT NULL;

ALTER TABLE backtest_runs
    ADD COLUMN weighted_wape DECIMAL(8,2) DEFAULT NULL;

ALTER TABLE dataset_versions
    ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0 AFTER is_pre_restore_snapshot;

CREATE TABLE IF NOT EXISTS product_categories (
    product_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, category),
    CONSTRAINT fk_product_categories_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preserve the categories already attached to existing products.
INSERT IGNORE INTO product_categories (product_id, category, position)
    SELECT id, category, 0 FROM products WHERE category IS NOT NULL AND category <> '';
INSERT IGNORE INTO product_categories (product_id, category, position)
    SELECT id, subcategory, 1 FROM products WHERE subcategory IS NOT NULL AND subcategory <> '';

CREATE TABLE IF NOT EXISTS version_product_snapshots (
    version_id INT NOT NULL,
    product_id INT NOT NULL,
    cost_price DECIMAL(10,2) DEFAULT NULL,
    selling_price DECIMAL(10,2) DEFAULT NULL,
    orig_cost_price DECIMAL(10,2) DEFAULT NULL,
    orig_selling_price DECIMAL(10,2) DEFAULT NULL,
    PRIMARY KEY (version_id, product_id),
    CONSTRAINT fk_vps_version FOREIGN KEY (version_id)
        REFERENCES dataset_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_vps_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
