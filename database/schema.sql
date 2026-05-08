-- ============================================================
-- Mascardi Car Yard Management System — Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS mascardi_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mascardi_db;

-- --------------------------------------------------------
-- Cars
-- --------------------------------------------------------
CREATE TABLE cars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chassis_number VARCHAR(50) UNIQUE NOT NULL,
    registration_number VARCHAR(20),
    make VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    year YEAR NOT NULL,
    color VARCHAR(30),
    engine_number VARCHAR(50),
    transmission ENUM('manual','automatic','cvt') DEFAULT 'manual',
    fuel_type ENUM('petrol','diesel','hybrid','electric') DEFAULT 'petrol',
    body_type VARCHAR(30),
    status ENUM('in_transit','arrived','in_assessment','in_workshop','completed','delivered') DEFAULT 'in_transit',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Drivers
-- --------------------------------------------------------
CREATE TABLE drivers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    id_number VARCHAR(20) UNIQUE NOT NULL,
    license_number VARCHAR(30) UNIQUE NOT NULL,
    license_class VARCHAR(10) DEFAULT 'BCE',
    license_expiry DATE,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Mechanics
-- --------------------------------------------------------
CREATE TABLE mechanics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    id_number VARCHAR(20),
    phone VARCHAR(20),
    email VARCHAR(100),
    specialization VARCHAR(100),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Car Intake (Mombasa Port)
-- --------------------------------------------------------
CREATE TABLE car_intake (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    intake_date DATE NOT NULL,
    port VARCHAR(100) DEFAULT 'Mombasa Port',
    shipping_line VARCHAR(100),
    bill_of_lading VARCHAR(100),
    container_number VARCHAR(50),
    clearing_agent VARCHAR(100),
    condition_on_arrival ENUM('excellent','good','fair','poor','damaged') DEFAULT 'good',
    condition_notes TEXT,
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Car Transfers (Mombasa → Nairobi)
-- --------------------------------------------------------
CREATE TABLE car_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    driver_id INT NOT NULL,
    departure_date DATETIME,
    estimated_arrival DATETIME,
    arrival_date DATETIME,
    from_location VARCHAR(100) DEFAULT 'Mombasa',
    to_location VARCHAR(100) DEFAULT 'Nairobi',
    departure_mileage INT,
    arrival_mileage INT,
    departure_condition TEXT,
    arrival_condition TEXT,
    status ENUM('pending','in_transit','arrived') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id),
    FOREIGN KEY (driver_id) REFERENCES drivers(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Car Assessments
-- --------------------------------------------------------
CREATE TABLE car_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    mechanic_id INT,
    assessment_date DATE NOT NULL,
    assessment_type ENUM('arrival','workshop','pre_delivery') DEFAULT 'arrival',
    overall_status ENUM('excellent','good','fair','poor','critical') DEFAULT 'fair',
    mileage INT,
    fuel_level ENUM('empty','quarter','half','three_quarter','full') DEFAULT 'half',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id),
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Assessment Items (per part)
-- --------------------------------------------------------
CREATE TABLE assessment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    part_category VARCHAR(50),
    part_name VARCHAR(100) NOT NULL,
    `condition` ENUM('good','minor_damage','major_damage','missing','needs_service') DEFAULT 'good',
    notes TEXT,
    FOREIGN KEY (assessment_id) REFERENCES car_assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Suppliers
-- --------------------------------------------------------
CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    pin_number VARCHAR(30),
    payment_terms VARCHAR(50) DEFAULT 'Cash on Delivery',
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Inventory (Parts & Materials)
-- --------------------------------------------------------
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_number VARCHAR(50) UNIQUE,
    part_name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    quantity DECIMAL(10,2) DEFAULT 0,
    unit VARCHAR(20) DEFAULT 'piece',
    unit_price DECIMAL(10,2) DEFAULT 0,
    selling_price DECIMAL(10,2) DEFAULT 0,
    reorder_level DECIMAL(10,2) DEFAULT 5,
    supplier_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Inventory Transactions
-- --------------------------------------------------------
CREATE TABLE inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    transaction_type ENUM('in','out','adjustment') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    balance DECIMAL(10,2) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    notes TEXT,
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Workshop Jobs
-- --------------------------------------------------------
CREATE TABLE workshop_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_number VARCHAR(20) UNIQUE NOT NULL,
    car_id INT NOT NULL,
    mechanic_id INT,
    assessment_id INT,
    start_date DATE,
    end_date DATE,
    status ENUM('pending','in_progress','waiting_parts','on_hold','completed','cancelled') DEFAULT 'pending',
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    description TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id),
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Quotations
-- --------------------------------------------------------
CREATE TABLE quotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quotation_number VARCHAR(20) UNIQUE NOT NULL,
    car_id INT NOT NULL,
    job_id INT,
    date DATE NOT NULL,
    valid_until DATE,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_email VARCHAR(100),
    status ENUM('draft','sent','approved','rejected','converted') DEFAULT 'draft',
    subtotal DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(12,2) DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 16.00,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    terms TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id),
    FOREIGN KEY (job_id) REFERENCES workshop_jobs(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Quotation Items
-- --------------------------------------------------------
CREATE TABLE quotation_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT NOT NULL,
    item_type ENUM('part','labour','service') DEFAULT 'part',
    inventory_id INT,
    description VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1,
    unit_price DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(5,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Invoices
-- --------------------------------------------------------
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(20) UNIQUE NOT NULL,
    quotation_id INT,
    car_id INT NOT NULL,
    job_id INT,
    date DATE NOT NULL,
    due_date DATE,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_email VARCHAR(100),
    status ENUM('unpaid','partial','paid','cancelled') DEFAULT 'unpaid',
    subtotal DECIMAL(12,2) DEFAULT 0,
    discount DECIMAL(12,2) DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 16.00,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    amount_paid DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id) REFERENCES cars(id),
    FOREIGN KEY (quotation_id) REFERENCES quotations(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Invoice Items
-- --------------------------------------------------------
CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_type ENUM('part','labour','service') DEFAULT 'part',
    description VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1,
    unit_price DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- LPO (Local Purchase Orders)
-- --------------------------------------------------------
CREATE TABLE lpo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lpo_number VARCHAR(20) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    job_id INT,
    date DATE NOT NULL,
    expected_delivery DATE,
    delivery_date DATE,
    status ENUM('draft','sent','acknowledged','partial','received','cancelled') DEFAULT 'draft',
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 16.00,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    delivery_address TEXT,
    notes TEXT,
    approved_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (job_id) REFERENCES workshop_jobs(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- LPO Items
-- --------------------------------------------------------
CREATE TABLE lpo_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lpo_id INT NOT NULL,
    inventory_id INT,
    description VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1,
    unit VARCHAR(20) DEFAULT 'piece',
    unit_price DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    received_qty DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (lpo_id) REFERENCES lpo(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Settings
-- --------------------------------------------------------
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name', 'Mascardi Car Yard'),
('company_address', 'Nairobi, Kenya'),
('company_phone', '+254 700 000 000'),
('company_email', 'info@mascardi.co.ke'),
('company_pin', 'P051234567X'),
('vat_rate', '16'),
('currency', 'KES'),
('invoice_prefix', 'INV'),
('quotation_prefix', 'QT'),
('lpo_prefix', 'LPO'),
('job_prefix', 'JOB');

-- --------------------------------------------------------
-- Users (authentication)
-- --------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(150),
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','mechanic','driver') NOT NULL DEFAULT 'mechanic',
    linked_id INT NULL COMMENT 'ID in drivers or mechanics table',
    linked_type ENUM('driver','mechanic') NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Note: Run login.php first — the first-run setup creates the admin account via the web interface.
