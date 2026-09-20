-- Stationery Inventory V2 -> V3 SAFE / RESTARTABLE UPGRADE
-- Designed for databases where an earlier V3 upgrade may have stopped part-way.
-- BACK UP YOUR DATABASE BEFORE RUNNING THIS FILE.

USE stationery_inventory;

-- -----------------------------------------------------------------------------
-- 1) transaction_order.sale_price
-- -----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'transaction_order'
          AND COLUMN_NAME = 'sale_price'
    ),
    'SELECT ''transaction_order.sale_price already exists'' AS info',
    'ALTER TABLE transaction_order ADD COLUMN sale_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER unit_price'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2) tst_sales.batch_id
-- -----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tst_sales'
          AND COLUMN_NAME = 'batch_id'
    ),
    'SELECT ''tst_sales.batch_id already exists'' AS info',
    'ALTER TABLE tst_sales ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER item_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3) tst_sales.cost_unit_price
-- -----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tst_sales'
          AND COLUMN_NAME = 'cost_unit_price'
    ),
    'SELECT ''tst_sales.cost_unit_price already exists'' AS info',
    'ALTER TABLE tst_sales ADD COLUMN cost_unit_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER unit_price'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4) tst_sales.cost_total
-- -----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tst_sales'
          AND COLUMN_NAME = 'cost_total'
    ),
    'SELECT ''tst_sales.cost_total already exists'' AS info',
    'ALTER TABLE tst_sales ADD COLUMN cost_total DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER line_total'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5) Index used by batch sales lookups
-- -----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tst_sales'
          AND INDEX_NAME = 'idx_sales_batch'
    ),
    'SELECT ''idx_sales_batch already exists'' AS info',
    'ALTER TABLE tst_sales ADD INDEX idx_sales_batch (batch_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 6) Batch inventory table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_batch (
    batch_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NULL UNIQUE,
    item_id BIGINT UNSIGNED NOT NULL,
    qty_received INT UNSIGNED NOT NULL,
    qty_remaining INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    sale_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    received_date DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch_item_stock (item_id, qty_remaining),
    INDEX idx_batch_received_date (received_date),
    CONSTRAINT fk_batch_receipt
        FOREIGN KEY (receipt_id) REFERENCES transaction_order(receipt_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_batch_item
        FOREIGN KEY (item_id) REFERENCES master_item(item_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 7) Migrate existing V2 aggregate stock only when that item has no batch yet.
--    The source V2 columns may not exist in a fresh V3 schema, so choose safe
--    expressions dynamically.
-- -----------------------------------------------------------------------------
SET @has_inventory_cost = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_inventory'
      AND COLUMN_NAME = 'unit_price'
);
SET @has_item_sale_price = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'master_item'
      AND COLUMN_NAME = 'sale_price'
);

SET @cost_expr = IF(@has_inventory_cost > 0, 'COALESCE(inv.unit_price,0)', '0.00');
SET @sale_expr = IF(@has_item_sale_price > 0, 'COALESCE(mi.sale_price,0)', '0.00');

SET @sql = CONCAT(
    'INSERT INTO inventory_batch ',
    '(receipt_id,item_id,qty_received,qty_remaining,unit_cost,sale_price,received_date) ',
    'SELECT NULL, inv.item_id, inv.qty, inv.qty, ', @cost_expr, ', ', @sale_expr, ', NOW() ',
    'FROM master_inventory inv ',
    'JOIN master_item mi ON mi.item_id = inv.item_id ',
    'WHERE inv.qty > 0 ',
    'AND NOT EXISTS (SELECT 1 FROM inventory_batch b WHERE b.item_id = inv.item_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 8) Approximate cost for historical V2 sales only. Do not overwrite V3 sales.
-- -----------------------------------------------------------------------------
SET @sql = IF(
    @has_inventory_cost > 0,
    'UPDATE tst_sales ts JOIN master_inventory inv ON inv.item_id = ts.item_id SET ts.cost_unit_price = COALESCE(inv.unit_price,0), ts.cost_total = ts.qty * COALESCE(inv.unit_price,0) WHERE ts.batch_id IS NULL AND ts.cost_unit_price = 0 AND ts.cost_total = 0',
    'SELECT ''No legacy master_inventory.unit_price column; historical cost migration skipped'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 9) Copy the V2 item-level selling price to existing receipts where possible.
-- -----------------------------------------------------------------------------
SET @sql = IF(
    @has_item_sale_price > 0,
    'UPDATE transaction_order tr JOIN master_item mi ON mi.item_id = tr.item_id SET tr.sale_price = COALESCE(mi.sale_price,0) WHERE tr.sale_price = 0',
    'SELECT ''No legacy master_item.sale_price column; receipt price migration skipped'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 10) Sales -> batch foreign key, only if not already present.
-- -----------------------------------------------------------------------------
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tst_sales'
          AND CONSTRAINT_NAME = 'fk_sales_batch'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ),
    'SELECT ''fk_sales_batch already exists'' AS info',
    'ALTER TABLE tst_sales ADD CONSTRAINT fk_sales_batch FOREIGN KEY (batch_id) REFERENCES inventory_batch(batch_id) ON UPDATE CASCADE ON DELETE RESTRICT'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'V3 safe upgrade completed.' AS result;
