-- Phase 10: Attendance Records + PIN Terminal
-- Run in phpMyAdmin on mascardi_db

-- Clock-in/out records table (referenced by hr_dashboard.php as attendance_records)
CREATE TABLE IF NOT EXISTS attendance_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    staff_type      ENUM('mechanic','driver') NOT NULL,
    staff_id        INT NOT NULL,
    attendance_date DATE NOT NULL,
    status          ENUM('present','late','absent','leave','half_day') NOT NULL DEFAULT 'present',
    clock_in        TIME NULL,
    clock_out       TIME NULL,
    notes           VARCHAR(255) NULL,
    recorded_by     INT NULL COMMENT 'user_id or NULL if self-clocked',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_staff_date (staff_type, staff_id, attendance_date),
    INDEX idx_date (attendance_date),
    INDEX idx_staff (staff_type, staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add PIN to mechanics
ALTER TABLE mechanics
    ADD COLUMN IF NOT EXISTS pin CHAR(4) NULL COMMENT '4-digit clock-in PIN' AFTER email;

-- Add PIN to drivers
ALTER TABLE drivers
    ADD COLUMN IF NOT EXISTS pin CHAR(4) NULL COMMENT '4-digit clock-in PIN' AFTER email;
