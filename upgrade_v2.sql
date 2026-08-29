USE stationery_inventory;

-- Run this once if you already installed the previous version.
ALTER TABLE master_item
    ADD COLUMN sale_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER item_name;

-- Existing items keep their current item codes. New items will be generated automatically as ITM-xxx.
-- Set the selling price for your existing items after running this script, either in Items page or using SQL.
