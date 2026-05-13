-- Fix missing columns for Issues & Assessments
-- Run this in phpMyAdmin on mascardi_db

-- 1. Ensure car_assessments table exists
CREATE TABLE IF NOT EXISTS car_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    assessment_type ENUM('arrival', 'pre_delivery', 'yard', 'client_service', 'workshop') NOT NULL,
    inspector_name VARCHAR(100),
    overall_condition ENUM('excellent', 'good', 'fair', 'poor', 'damaged') DEFAULT 'good',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id)
) ENGINE=InnoDB;

-- 2. Ensure assessment_items table exists
CREATE TABLE IF NOT EXISTS assessment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    part_category VARCHAR(100) NOT NULL,
    part_name VARCHAR(100) NOT NULL,
    `condition` ENUM('good', 'minor_damage', 'major_damage', 'missing', 'needs_service') DEFAULT 'good',
    notes TEXT,
    is_resolved TINYINT(1) DEFAULT 0,
    resolved_at DATETIME,
    FOREIGN KEY (assessment_id) REFERENCES car_assessments(id)
) ENGINE=InnoDB;

-- 3. Add resolved_by if missing
SET @dbname = DATABASE();
SET @tablename = 'assessment_items';
SET @columnname = 'resolved_by';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
  'SELECT 1',
  'ALTER TABLE assessment_items ADD COLUMN resolved_by VARCHAR(100) NULL AFTER resolved_at'
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
