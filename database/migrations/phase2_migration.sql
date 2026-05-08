-- Phase 2: Audit Logs and Multi-Yard Support

-- 1. Locations Table
CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('yard','showroom','port','office') DEFAULT 'yard',
    address TEXT,
    phone VARCHAR(20),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default locations
INSERT INTO locations (name, type, address) VALUES 
('Nairobi HQ', 'office', 'Main Showroom, Nairobi'),
('Mombasa Port', 'port', 'Kilindini, Mombasa'),
('Workshop Yard', 'yard', 'Industrial Area, Nairobi');

-- 2. Update Cars Table
ALTER TABLE cars ADD COLUMN location_id INT DEFAULT 1 AFTER fuel_type;
ALTER TABLE cars ADD CONSTRAINT fk_car_location FOREIGN KEY (location_id) REFERENCES locations(id);

-- 3. Audit Logs Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action ENUM('create','update','delete','login','logout') NOT NULL,
    module VARCHAR(50) NOT NULL,
    record_id INT,
    details TEXT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
