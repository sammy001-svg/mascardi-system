-- Migration 015: Standalone car issues table
-- Run once against the database

CREATE TABLE IF NOT EXISTS car_issues (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    issue_number    VARCHAR(30)  UNIQUE NOT NULL,
    car_id          INT          NOT NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT,
    category        VARCHAR(50),
    severity        ENUM('low','medium','high','critical') DEFAULT 'medium',
    status          ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    reported_by     VARCHAR(100),
    reported_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    assigned_to     INT          NULL,
    resolved_by     VARCHAR(100),
    resolved_at     DATETIME     NULL,
    resolution_notes TEXT,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_issue_car  FOREIGN KEY (car_id)      REFERENCES cars(id)      ON DELETE CASCADE,
    CONSTRAINT fk_issue_mech FOREIGN KEY (assigned_to) REFERENCES mechanics(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('issue_prefix', 'ISS');
