-- Migration 014: Car Sales & Delivery workflow
-- Run this once against the production database

-- Add 'sold' to cars status ENUM
ALTER TABLE cars
    MODIFY COLUMN status
    ENUM('in_transit','arrived','in_assessment','in_workshop','completed','sold','delivered')
    DEFAULT 'in_transit';

-- Car sales table
CREATE TABLE IF NOT EXISTS car_sales (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    sale_number       VARCHAR(30)  UNIQUE NOT NULL,
    car_id            INT          NOT NULL,
    sale_date         DATE         NOT NULL,
    sale_price        DECIMAL(12,2) NOT NULL,
    buyer_name        VARCHAR(150) NOT NULL,
    buyer_phone       VARCHAR(30),
    buyer_email       VARCHAR(150),
    buyer_id_number   VARCHAR(30),
    payment_method    ENUM('cash','bank_transfer','financing','cheque','mpesa') DEFAULT 'cash',
    payment_status    ENUM('paid_full','partial','financed','pending') DEFAULT 'paid_full',
    deposit_amount    DECIMAL(12,2) DEFAULT 0.00,
    balance_amount    DECIMAL(12,2) DEFAULT 0.00,
    finance_company   VARCHAR(150),
    delivered_at      DATETIME     NULL,
    delivery_notes    TEXT,
    sold_by           INT          NULL,
    notes             TEXT,
    status            ENUM('active','cancelled') DEFAULT 'active',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_car  FOREIGN KEY (car_id)  REFERENCES cars(id)  ON DELETE RESTRICT,
    CONSTRAINT fk_sale_user FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add sale_prefix to settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('sale_prefix', 'SALE');
