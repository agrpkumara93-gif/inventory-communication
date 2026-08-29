-- Stationery Inventory v2 -> v3 upgrade
-- BACK UP YOUR DATABASE BEFORE RUNNING THIS FILE.
-- Run this once in phpMyAdmin after replacing the PHP files with v3.

USE stationery_inventory;

-- Add batch selling price to receipts.
ALTER TABLE transaction_order
    ADD COLUMN sale_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER unit_price;

-- Add cost/batch traceability to sales lines.
ALTER TABLE tst_sales
    ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER item_id,
    ADD COLUMN cost_unit_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER unit_price,
    ADD COLUMN cost_total DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER line_total,
    ADD INDEX idx_sales_batch (batch_id);

CREATE TABLE inventory_batch (
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

-- Existing v2 stock did not retain individual batch balances.
-- Create ONE migration batch per item using the current aggregate stock, current
-- inventory cost, and the v2 item selling price. Future receipts will be fully batch-aware.
INSERT INTO inventory_batch
    (receipt_id, item_id, qty_received, qty_remaining, unit_cost, sale_price, received_date)
SELECT
    NULL,
    inv.item_id,
    inv.qty,
    inv.qty,
    COALESCE(inv.unit_price, 0),
    COALESCE(mi.sale_price, 0),
    NOW()
FROM master_inventory inv
JOIN master_item mi ON mi.item_id = inv.item_id
WHERE inv.qty > 0;

-- Preserve a useful approximate cost for historical v2 sales.
-- Exact historical batch COGS cannot be reconstructed because v2 did not store it.
UPDATE tst_sales ts
JOIN master_inventory inv ON inv.item_id = ts.item_id
SET ts.cost_unit_price = COALESCE(inv.unit_price, 0),
    ts.cost_total = ts.qty * COALESCE(inv.unit_price, 0);

-- Existing receipts get their old item-level selling price for reference only.
UPDATE transaction_order tr
JOIN master_item mi ON mi.item_id = tr.item_id
SET tr.sale_price = COALESCE(mi.sale_price, 0);

-- Add FK after migration rows exist. Historical v2 sales keep batch_id = NULL.
ALTER TABLE tst_sales
    ADD CONSTRAINT fk_sales_batch
    FOREIGN KEY (batch_id) REFERENCES inventory_batch(batch_id)
    ON UPDATE CASCADE ON DELETE RESTRICT;

-- v3 no longer uses item-level selling price or aggregate inventory unit price.
-- They are intentionally left in place for backwards compatibility with an upgraded database.
