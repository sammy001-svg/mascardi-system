-- Migration 023: Public Showroom
-- Adds showroom-facing fields to cars, creates inquiry table

-- Add showroom fields to cars (safe — column may not exist yet)
ALTER TABLE cars
    ADD COLUMN IF NOT EXISTS asking_price DECIMAL(12,2)  DEFAULT NULL   AFTER status,
    ADD COLUMN IF NOT EXISTS mileage      INT UNSIGNED   DEFAULT NULL   AFTER asking_price,
    ADD COLUMN IF NOT EXISTS engine_cc    INT UNSIGNED   DEFAULT NULL   AFTER mileage,
    ADD COLUMN IF NOT EXISTS featured     TINYINT(1)     NOT NULL DEFAULT 0 AFTER engine_cc;

-- Index for fast showroom queries (inventory cars with a price set)
CREATE INDEX IF NOT EXISTS idx_cars_showroom
    ON cars (car_type, status, asking_price);

-- Inquiry table: captures leads from the public showroom
CREATE TABLE IF NOT EXISTS showroom_inquiries (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    car_id         INT NOT NULL,
    inquiry_name   VARCHAR(150) NOT NULL,
    inquiry_phone  VARCHAR(30)  DEFAULT NULL,
    inquiry_email  VARCHAR(150) DEFAULT NULL,
    message        TEXT         DEFAULT NULL,
    status         ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
    notes          TEXT         DEFAULT NULL,
    responded_by   INT          DEFAULT NULL,
    responded_at   DATETIME     DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inq_car  FOREIGN KEY (car_id)       REFERENCES cars(id)  ON DELETE CASCADE,
    CONSTRAINT fk_inq_user FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_inq_car    ON showroom_inquiries (car_id);
CREATE INDEX IF NOT EXISTS idx_inq_status ON showroom_inquiries (status);
