-- Phase 4: Expanded assessment types + driver-linked assessments
-- Run once against mascardi_db

-- Expand assessment_type ENUM to include pre_departure and pre_sales
ALTER TABLE car_assessments
    MODIFY COLUMN assessment_type
        ENUM('pre_departure','arrival','workshop','pre_sales','pre_delivery')
        NOT NULL DEFAULT 'arrival';

-- Allow assessments to be linked to a driver (pre_departure type)
ALTER TABLE car_assessments
    ADD COLUMN driver_id INT NULL AFTER mechanic_id,
    ADD CONSTRAINT fk_assess_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL;
