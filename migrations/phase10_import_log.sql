-- Phase 10: Bulk CSV Imports
-- Run in phpMyAdmin on mascardi_db

CREATE TABLE IF NOT EXISTS import_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    imported_by     INT NOT NULL,
    entity_type     ENUM('cars','clients','inventory') NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    rows_imported   INT DEFAULT 0,
    rows_skipped    INT DEFAULT 0,
    errors          JSON NULL COMMENT 'JSON array of row-level errors',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
