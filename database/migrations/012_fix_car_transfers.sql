-- Migration 012: Fix car_transfers table
-- Adds transported_by column and makes driver_id nullable
-- to support both internal drivers and external transporters.

ALTER TABLE car_transfers
    MODIFY COLUMN driver_id INT NULL,
    ADD COLUMN transported_by VARCHAR(150) NULL AFTER driver_id;
