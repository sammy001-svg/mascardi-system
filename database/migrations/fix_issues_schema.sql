-- Comprehensive Fix for Issues & Assessments Schema
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

-- 3. Procedure to add columns safely
DROP PROCEDURE IF EXISTS AddColumnIfMissing;
DELIMITER //
CREATE PROCEDURE AddColumnIfMissing(
    IN p_tablename VARCHAR(100),
    IN p_columnname VARCHAR(100),
    IN p_columndef TEXT
)
BEGIN
    SET @dbname = DATABASE();
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = @dbname 
        AND TABLE_NAME = p_tablename 
        AND COLUMN_NAME = p_columnname
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', p_tablename, ' ADD COLUMN ', p_columnname, ' ', p_columndef);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- 4. Apply column fixes
CALL AddColumnIfMissing('assessment_items', 'resolved_at', 'DATETIME NULL');
CALL AddColumnIfMissing('assessment_items', 'resolved_by', 'VARCHAR(100) NULL');

-- 5. Cleanup
DROP PROCEDURE IF EXISTS AddColumnIfMissing;
