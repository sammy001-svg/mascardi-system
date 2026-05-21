-- Migration 009: M-Pesa integration fields
-- Run once to add M-Pesa columns and callback log table

-- Add M-Pesa columns to payments table
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS mpesa_checkout_id  VARCHAR(100) NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS mpesa_code         VARCHAR(20)  NULL AFTER mpesa_checkout_id,
    ADD COLUMN IF NOT EXISTS mpesa_phone        VARCHAR(20)  NULL AFTER mpesa_code,
    ADD COLUMN IF NOT EXISTS mpesa_result_desc  VARCHAR(255) NULL AFTER mpesa_phone,
    ADD COLUMN IF NOT EXISTS updated_at         TIMESTAMP    NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- M-Pesa raw callback log (useful for debugging and reconciliation)
CREATE TABLE IF NOT EXISTS mpesa_callbacks (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    checkout_request_id  VARCHAR(100) NOT NULL,
    merchant_request_id  VARCHAR(100) NULL,
    result_code          SMALLINT     NOT NULL,
    payload              LONGTEXT     NOT NULL,
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_checkout (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add M-Pesa settings (safe to run multiple times — INSERT IGNORE)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('mpesa_env',          'sandbox'),
    ('mpesa_consumer_key', ''),
    ('mpesa_consumer_secret',''),
    ('mpesa_shortcode',    ''),
    ('mpesa_passkey',      ''),
    ('mpesa_callback_url', ''),
    ('payment_prefix',     'PAY');
