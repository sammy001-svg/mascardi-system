-- Phase 10: Leave Management Module
-- Run in phpMyAdmin on mascardi_db

-- Leave Balances (Yearly entitlement vs taken)
CREATE TABLE IF NOT EXISTS leave_balances (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    staff_type    ENUM('user','mechanic','driver') NOT NULL,
    staff_id      INT NOT NULL,
    leave_year    YEAR(4) NOT NULL,
    annual_days   DECIMAL(5,1) DEFAULT 21.0,
    sick_days     DECIMAL(5,1) DEFAULT 14.0,
    taken_annual  DECIMAL(5,1) DEFAULT 0.0,
    taken_sick    DECIMAL(5,1) DEFAULT 0.0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_staff_year (staff_type, staff_id, leave_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leave Requests
CREATE TABLE IF NOT EXISTS leave_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    staff_type    ENUM('user','mechanic','driver') NOT NULL,
    staff_id      INT NOT NULL,
    user_name     VARCHAR(100) NOT NULL COMMENT 'Denormalized for fast HR view',
    leave_type    ENUM('annual','sick','emergency','maternity','paternity','unpaid','study') NOT NULL,
    start_date    DATE NOT NULL,
    end_date      DATE NOT NULL,
    days_count    DECIMAL(5,1) NOT NULL,
    reason        TEXT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by   INT NULL,
    approved_at   TIMESTAMP NULL,
    notes         TEXT NULL COMMENT 'HR notes/rejection reason',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dates (start_date, end_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
