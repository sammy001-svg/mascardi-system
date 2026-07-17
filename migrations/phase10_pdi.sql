CREATE TABLE IF NOT EXISTS inspection_checklists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    sale_id INT DEFAULT NULL,
    checklist_type ENUM('pre_delivery','incoming','pre_sale') NOT NULL DEFAULT 'pre_delivery',
    status ENUM('draft','submitted','approved','failed') NOT NULL DEFAULT 'draft',
    inspector_id INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    overall_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (car_id),
    INDEX (sale_id),
    INDEX (status)
);

CREATE TABLE IF NOT EXISTS inspection_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checklist_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    item VARCHAR(255) NOT NULL,
    result ENUM('pending','ok','fail','na') NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    INDEX (checklist_id)
);
