-- Phase 10: KPI Targets Dashboard
-- Run in phpMyAdmin on mascardi_db

CREATE TABLE IF NOT EXISTS kpi_targets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    metric_key      ENUM('revenue','cars_sold','new_leads','jobs_closed') NOT NULL,
    target_month    TINYINT NOT NULL COMMENT '1-12',
    target_year     YEAR(4) NOT NULL,
    target_value    DECIMAL(15,2) NOT NULL,
    created_by      INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_target (metric_key, target_month, target_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
