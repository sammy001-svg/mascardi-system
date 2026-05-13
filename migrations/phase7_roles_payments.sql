-- Phase 7: Role redesign + Payments + Quick Assessments
-- Run in phpMyAdmin on mascardi_db

-- 1. Extend users role ENUM (keep old values for backward compat, add new)
ALTER TABLE users
    MODIFY role ENUM('admin','workshop_manager','sales_person','sales_officer','manager','mechanic')
    NOT NULL DEFAULT 'sales_person';

-- Migrate old roles to new
UPDATE users SET role = 'workshop_manager' WHERE role = 'manager';
UPDATE users SET role = 'workshop_manager' WHERE role = 'mechanic';

-- 2. Payments table
CREATE TABLE IF NOT EXISTS payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    payment_number      VARCHAR(30)  NOT NULL UNIQUE,
    payment_date        DATE         NOT NULL,
    client_id           INT          NULL,
    client_name         VARCHAR(150) NOT NULL,
    client_phone        VARCHAR(30)  NULL,
    invoice_id          INT          NULL,
    service_booking_id  INT          NULL,
    description         VARCHAR(255) NULL,
    amount              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method      ENUM('mpesa','bank','cheque','cash') NOT NULL,
    reference_number    VARCHAR(100) NULL,
    mpesa_phone         VARCHAR(20)  NULL,
    mpesa_name          VARCHAR(100) NULL,
    bank_name           VARCHAR(100) NULL,
    account_number      VARCHAR(50)  NULL,
    cheque_number       VARCHAR(50)  NULL,
    cheque_date         DATE         NULL,
    status              ENUM('pending','confirmed','reversed') NOT NULL DEFAULT 'pending',
    reversal_reason     VARCHAR(255) NULL,
    notes               TEXT         NULL,
    balance_adjustment  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    recorded_by         VARCHAR(100) NULL,
    confirmed_by        VARCHAR(100) NULL,
    confirmed_at        TIMESTAMP    NULL,
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_client  FOREIGN KEY (client_id)          REFERENCES clients(id)          ON DELETE SET NULL,
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id)         REFERENCES invoices(id)         ON DELETE SET NULL,
    CONSTRAINT fk_pay_booking FOREIGN KEY (service_booking_id) REFERENCES service_bookings(id) ON DELETE SET NULL
);

-- 3. Quick assessments table (light vehicle check by sales person)
CREATE TABLE IF NOT EXISTS quick_assessments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    assessment_number   VARCHAR(30)  NOT NULL UNIQUE,
    assessment_date     DATE         NOT NULL,
    car_id              INT          NULL,
    car_make            VARCHAR(100) NULL,
    car_model           VARCHAR(100) NULL,
    car_registration    VARCHAR(50)  NULL,
    car_year            YEAR         NULL,
    client_id           INT          NULL,
    client_name         VARCHAR(150) NULL,
    client_phone        VARCHAR(30)  NULL,
    service_booking_id  INT          NULL,
    check_tyres         ENUM('ok','issue','na') DEFAULT 'na',
    check_lights        ENUM('ok','issue','na') DEFAULT 'na',
    check_exterior      ENUM('ok','issue','na') DEFAULT 'na',
    check_engine        ENUM('ok','issue','na') DEFAULT 'na',
    check_interior      ENUM('ok','issue','na') DEFAULT 'na',
    check_brakes        ENUM('ok','issue','na') DEFAULT 'na',
    check_fluids        ENUM('ok','issue','na') DEFAULT 'na',
    check_electrical    ENUM('ok','issue','na') DEFAULT 'na',
    overall_condition   ENUM('good','fair','needs_attention','critical') NOT NULL DEFAULT 'fair',
    observations        TEXT         NULL,
    recommended_services TEXT        NULL,
    assessed_by         VARCHAR(100) NULL,
    created_by          INT          NULL,
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qa_car     FOREIGN KEY (car_id)             REFERENCES cars(id)             ON DELETE SET NULL,
    CONSTRAINT fk_qa_client  FOREIGN KEY (client_id)          REFERENCES clients(id)          ON DELETE SET NULL,
    CONSTRAINT fk_qa_booking FOREIGN KEY (service_booking_id) REFERENCES service_bookings(id) ON DELETE SET NULL
);
