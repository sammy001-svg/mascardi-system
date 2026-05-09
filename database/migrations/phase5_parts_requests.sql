-- Phase 5: Parts Request system for mechanics
-- Run once against mascardi_db

CREATE TABLE IF NOT EXISTS parts_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    request_number  VARCHAR(20) UNIQUE NOT NULL,
    job_id          INT NULL,
    mechanic_id     INT NOT NULL,
    requested_by    INT NOT NULL COMMENT 'users.id',
    status          ENUM('pending','approved','rejected','issued') DEFAULT 'pending',
    notes           TEXT,
    admin_notes     TEXT,
    approved_by     VARCHAR(100),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id)      REFERENCES workshop_jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parts_request_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    request_id          INT NOT NULL,
    inventory_id        INT NULL,
    part_name           VARCHAR(200) NOT NULL,
    quantity_requested  DECIMAL(10,2) NOT NULL DEFAULT 1,
    quantity_issued     DECIMAL(10,2) DEFAULT 0,
    unit                VARCHAR(20) DEFAULT 'piece',
    notes               TEXT,
    FOREIGN KEY (request_id)   REFERENCES parts_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE SET NULL
) ENGINE=InnoDB;
