-- Migration: Add missing columns to service_bookings
-- Run this on the live database via phpMyAdmin

ALTER TABLE `service_bookings`
    ADD COLUMN IF NOT EXISTS `car_make` VARCHAR(100) NULL AFTER `car_id`,
    ADD COLUMN IF NOT EXISTS `car_model` VARCHAR(100) NULL AFTER `car_make`,
    ADD COLUMN IF NOT EXISTS `car_registration` VARCHAR(50) NULL AFTER `car_model`,
    ADD COLUMN IF NOT EXISTS `preferred_time` VARCHAR(10) NULL AFTER `preferred_date`,
    ADD COLUMN IF NOT EXISTS `sales_person` VARCHAR(100) NULL AFTER `admin_notes`;

-- Increase size for multiple service selections
ALTER TABLE `service_bookings` MODIFY `service_type` VARCHAR(255);
