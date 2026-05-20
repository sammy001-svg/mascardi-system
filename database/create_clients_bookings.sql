CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150),
    phone VARCHAR(50),
    id_number VARCHAR(50),
    portal_password VARCHAR(255),
    portal_enabled TINYINT(1) DEFAULT 0,
    status ENUM('active','inactive') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_number VARCHAR(20) UNIQUE NOT NULL,
    client_id INT NULL,
    client_name VARCHAR(150) NOT NULL,
    client_email VARCHAR(150),
    client_phone VARCHAR(50) NOT NULL,
    car_id INT NULL,
    car_make VARCHAR(100),
    car_model VARCHAR(100),
    car_registration VARCHAR(50),
    car_description TEXT,
    service_type VARCHAR(255) NOT NULL,
    description TEXT,
    booking_date DATE NOT NULL,
    preferred_date DATE NULL,
    preferred_time VARCHAR(20) NULL,
    status ENUM('pending','confirmed','in_progress','completed','cancelled') DEFAULT 'pending',
    admin_notes TEXT,
    sales_person VARCHAR(100),
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cars 
ADD COLUMN client_id INT NULL AFTER id,
ADD COLUMN car_type ENUM('inventory','client') DEFAULT 'inventory' AFTER client_id,
ADD COLUMN owner_name VARCHAR(150) NULL AFTER car_type,
ADD CONSTRAINT fk_cars_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
