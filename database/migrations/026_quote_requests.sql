-- Migration 026: Rename Part Requests → Quote Requests
-- Add Quick Assessment link, client/vehicle fields, part_number on items
-- Make mechanic_id nullable (no longer required for Quote Requests)

ALTER TABLE parts_requests
    MODIFY COLUMN mechanic_id INT NULL,
    ADD COLUMN quick_assessment_id INT NULL    AFTER request_number,
    ADD COLUMN client_name         VARCHAR(150) NULL AFTER quick_assessment_id,
    ADD COLUMN client_phone        VARCHAR(50)  NULL AFTER client_name,
    ADD COLUMN client_email        VARCHAR(150) NULL AFTER client_phone,
    ADD COLUMN car_make            VARCHAR(100) NULL AFTER client_email,
    ADD COLUMN car_model           VARCHAR(100) NULL AFTER car_make,
    ADD COLUMN car_registration    VARCHAR(50)  NULL AFTER car_model,
    ADD COLUMN car_chassis         VARCHAR(100) NULL AFTER car_registration;

ALTER TABLE parts_request_items
    ADD COLUMN part_number VARCHAR(100) NULL AFTER id;
