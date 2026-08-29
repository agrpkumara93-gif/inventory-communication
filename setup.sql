CREATE DATABASE IF NOT EXISTS stationery_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE stationery_inventory;

CREATE TABLE user_master (
    user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE master_item (
    item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(40) NOT NULL UNIQUE,
    item_name VARCHAR(150) NOT NULL,
    moq INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Aggregate stock quantity per item. Cost and selling price are maintained per batch.
CREATE TABLE master_inventory (
    item_id BIGINT UNSIGNED PRIMARY KEY,
    qty INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_item
        FOREIGN KEY (item_id) REFERENCES master_item(item_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE transaction_order (
    receipt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    received_quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    sale_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    received_date DATETIME NOT NULL,
    received_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_receipt_item (item_id),
    INDEX idx_receipt_date (received_date),
    CONSTRAINT fk_receipt_item
        FOREIGN KEY (item_id) REFERENCES master_item(item_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_receipt_user
        FOREIGN KEY (received_by) REFERENCES user_master(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Every receipt creates a distinct inventory batch. The same item may therefore
-- exist at multiple costs and selling prices at the same time.
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

CREATE TABLE sales_master (
    sale_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(40) NULL UNIQUE,
    sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_by BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(150) NULL,
    total_amount DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00,
    INDEX idx_sale_date (sale_date),
    CONSTRAINT fk_sale_user
        FOREIGN KEY (sold_by) REFERENCES user_master(user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE tst_sales (
    sale_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    qty INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) UNSIGNED NOT NULL,
    cost_unit_price DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    line_total DECIMAL(14,2) UNSIGNED NOT NULL,
    cost_total DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00,
    INDEX idx_sales_sale (sale_id),
    INDEX idx_sales_item (item_id),
    INDEX idx_sales_batch (batch_id),
    CONSTRAINT fk_sales_master
        FOREIGN KEY (sale_id) REFERENCES sales_master(sale_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sales_item
        FOREIGN KEY (item_id) REFERENCES master_item(item_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sales_batch
        FOREIGN KEY (batch_id) REFERENCES inventory_batch(batch_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Default administrator account
-- Username: admin
-- Password: Admin@123
-- CHANGE THIS PASSWORD after first login.
INSERT INTO user_master (name, username, password_hash, role)
VALUES ('System Administrator', 'admin', '$2y$12$an/yrZfReB7O1I2Jjq6mYO.H4cycvl8vOpLDdt9cE4TEaCF3sxdcm', 'admin');

-- Optional sample item master records. Prices are entered when stock is received.
INSERT INTO master_item (item_code, item_name, moq) VALUES
('ITM-001', 'Blue Ballpoint Pen', 20),
('ITM-002', 'Exercise Book 80 Pages', 15),
('ITM-003', 'HB Pencil', 20);

INSERT INTO master_inventory (item_id, qty)
SELECT item_id, 0 FROM master_item;
