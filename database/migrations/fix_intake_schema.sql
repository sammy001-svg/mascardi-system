-- Fix missing columns and constraints for Mombasa Intake & Transfers
-- Run this in phpMyAdmin on mascardi_db

-- 1. Add transported_by to car_transfers (used for external transporters)
ALTER TABLE car_transfers ADD COLUMN transported_by VARCHAR(150) NULL AFTER driver_id;

-- 2. Make driver_id nullable in car_transfers
-- (To allow external transporters who aren't in the drivers table)
ALTER TABLE car_transfers MODIFY driver_id INT NULL;
