-- ============================================================
-- Migration 027: Logistics Movement System
-- Showroom transfers, key handovers, service location routing
-- ============================================================

-- ── 1. Add location fields to service_bookings ───────────────
ALTER TABLE service_bookings
    ADD COLUMN intake_location_id   INT NULL AFTER sales_person,
    ADD COLUMN return_location_id   INT NULL AFTER intake_location_id,
    ADD COLUMN return_transfer_id   INT NULL AFTER return_location_id,
    ADD COLUMN client_notified_at   DATETIME NULL AFTER return_transfer_id;

ALTER TABLE service_bookings
    ADD CONSTRAINT fk_sb_intake_loc  FOREIGN KEY (intake_location_id)  REFERENCES locations(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sb_return_loc  FOREIGN KEY (return_location_id)  REFERENCES locations(id) ON DELETE SET NULL;

-- ── 2. Add rotation tracking to cars ─────────────────────────
ALTER TABLE cars
    ADD COLUMN last_rotated_at  DATE NULL AFTER notes,
    ADD COLUMN rotation_notes   TEXT NULL AFTER last_rotated_at;

-- ── 3. Showroom transfers (inter-location stock movement) ────
CREATE TABLE IF NOT EXISTS showroom_transfers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    transfer_number     VARCHAR(20) UNIQUE NOT NULL,
    car_id              INT NOT NULL,
    driver_id           INT NULL,
    from_location_id    INT NOT NULL,
    to_location_id      INT NOT NULL,
    transfer_type       ENUM('transfer','stock_rotation','service_return','ad_hoc') DEFAULT 'transfer',
    status              ENUM('pending','approved','in_transit','arrived','cancelled') DEFAULT 'pending',
    requested_date      DATE NOT NULL,
    departure_at        DATETIME NULL,
    arrival_at          DATETIME NULL,
    departure_mileage   INT NULL,
    arrival_mileage     INT NULL,
    departure_condition TEXT NULL,
    arrival_condition   TEXT NULL,
    raised_by           VARCHAR(100) NOT NULL,
    approved_by         VARCHAR(100) NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id)           REFERENCES cars(id)      ON DELETE CASCADE,
    FOREIGN KEY (driver_id)        REFERENCES drivers(id)   ON DELETE SET NULL,
    FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    FOREIGN KEY (to_location_id)   REFERENCES locations(id) ON DELETE RESTRICT
);

-- ── 4. Car key register ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS car_keys (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    car_id              INT NOT NULL,
    key_label           VARCHAR(50) NOT NULL COMMENT 'e.g. KDA123Q-K1',
    current_location_id INT NULL,
    status              ENUM('at_showroom','in_transit','with_driver','missing') DEFAULT 'at_showroom',
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (car_id)              REFERENCES cars(id)      ON DELETE CASCADE,
    FOREIGN KEY (current_location_id) REFERENCES locations(id) ON DELETE SET NULL
);

-- ── 5. Key handover runs (morning/evening) ────────────────────
CREATE TABLE IF NOT EXISTS key_handovers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    handover_number     VARCHAR(20) UNIQUE NOT NULL,
    handover_date       DATE NOT NULL,
    run_type            ENUM('morning_run','evening_run','ad_hoc') DEFAULT 'morning_run',
    driver_id           INT NULL,
    driver_name         VARCHAR(150) NULL,
    from_location_id    INT NOT NULL,
    to_location_id      INT NOT NULL,
    status              ENUM('pending','checked_out','completed','cancelled') DEFAULT 'pending',
    checked_out_at      DATETIME NULL,
    checked_out_by      VARCHAR(100) NULL,
    checked_in_at       DATETIME NULL,
    checked_in_by       VARCHAR(100) NULL,
    notes               TEXT NULL,
    created_by          VARCHAR(100) NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id)        REFERENCES drivers(id)   ON DELETE SET NULL,
    FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
    FOREIGN KEY (to_location_id)   REFERENCES locations(id) ON DELETE RESTRICT
);

-- ── 6. Key handover line items ────────────────────────────────
CREATE TABLE IF NOT EXISTS key_handover_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    handover_id     INT NOT NULL,
    car_key_id      INT NOT NULL,
    car_id          INT NOT NULL,
    checked_out_at  DATETIME NULL,
    checked_out_by  VARCHAR(100) NULL,
    checked_in_at   DATETIME NULL,
    checked_in_by   VARCHAR(100) NULL,
    notes           TEXT NULL,
    FOREIGN KEY (handover_id) REFERENCES key_handovers(id) ON DELETE CASCADE,
    FOREIGN KEY (car_key_id)  REFERENCES car_keys(id)      ON DELETE CASCADE,
    FOREIGN KEY (car_id)      REFERENCES cars(id)          ON DELETE CASCADE
);
