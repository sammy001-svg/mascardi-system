-- Phase 6: Clients, Service Bookings, Client Portal, Email

-- ── Clients ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `clients` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `name`             VARCHAR(100) NOT NULL,
    `email`            VARCHAR(100) UNIQUE NOT NULL,
    `phone`            VARCHAR(20),
    `id_number`        VARCHAR(50) COMMENT 'National ID / KRA PIN',
    `portal_password`  VARCHAR(255) DEFAULT NULL,
    `portal_enabled`   TINYINT(1)  DEFAULT 0,
    `status`           ENUM('active','inactive') DEFAULT 'active',
    `notes`            TEXT,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Link clients to cars ────────────────────────────────────
ALTER TABLE `cars`
    ADD COLUMN IF NOT EXISTS `client_id`    INT  NULL AFTER `owner_phone`,
    ADD COLUMN IF NOT EXISTS `owner_email`  VARCHAR(100) NULL AFTER `owner_phone`;

ALTER TABLE `cars`
    ADD CONSTRAINT `fk_cars_client`
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL;

-- ── Service Bookings ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `service_bookings` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `booking_number`  VARCHAR(20) UNIQUE NOT NULL,
    `client_id`       INT NULL,
    `client_name`     VARCHAR(100) NOT NULL,
    `client_email`    VARCHAR(100),
    `client_phone`    VARCHAR(20),
    `car_id`          INT NULL,
    `car_description` VARCHAR(200),
    `service_type`    VARCHAR(100),
    `description`     TEXT,
    `booking_date`    DATE NOT NULL,
    `preferred_date`  DATE,
    `status`          ENUM('pending','confirmed','in_progress','completed','cancelled') DEFAULT 'pending',
    `admin_notes`     TEXT,
    `job_id`          INT NULL,
    `created_by`      VARCHAR(100),
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`car_id`)    REFERENCES `cars`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Client Notices (admin → client messages) ────────────────
CREATE TABLE IF NOT EXISTS `client_notices` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `client_id`  INT NOT NULL,
    `subject`    VARCHAR(200) NOT NULL,
    `message`    TEXT NOT NULL,
    `is_read`    TINYINT(1) DEFAULT 0,
    `sent_by`    VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Email Logs ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `email_logs` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `to_email`        VARCHAR(100) NOT NULL,
    `to_name`         VARCHAR(100),
    `subject`         VARCHAR(200) NOT NULL,
    `status`          ENUM('sent','failed') DEFAULT 'sent',
    `error_message`   TEXT,
    `reference_type`  VARCHAR(50),
    `reference_id`    INT,
    `sent_by`         VARCHAR(100),
    `sent_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Add client_id to invoices & quotations ──────────────────
ALTER TABLE `invoices`    ADD COLUMN IF NOT EXISTS `client_id` INT NULL AFTER `id`;
ALTER TABLE `quotations`  ADD COLUMN IF NOT EXISTS `client_id` INT NULL AFTER `id`;

ALTER TABLE `invoices`   ADD CONSTRAINT `fk_inv_client`  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL;
ALTER TABLE `quotations` ADD CONSTRAINT `fk_quot_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL;

-- ── SMTP / Email settings ───────────────────────────────────
INSERT IGNORE INTO `settings` (setting_key, setting_value) VALUES
    ('smtp_host',       ''),
    ('smtp_port',       '587'),
    ('smtp_user',       ''),
    ('smtp_pass',       ''),
    ('smtp_from_email', ''),
    ('smtp_from_name',  'Mascardi System'),
    ('smtp_encryption', 'tls');
