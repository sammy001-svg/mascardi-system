-- Migration: Vehicle Photo Management
-- Adds support for multiple images per vehicle with primary image selection.

CREATE TABLE car_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    caption VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Log activity
-- INSERT INTO audit_logs (action, module, details) VALUES ('migration', 'media', 'Created car_images table');
