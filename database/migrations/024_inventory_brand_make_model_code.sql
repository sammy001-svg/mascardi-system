-- Migration 024: Add brand, make, model, code columns to inventory
-- Run once. All columns are nullable so existing records are unaffected.

ALTER TABLE inventory
    ADD COLUMN brand  VARCHAR(100) NULL AFTER category,
    ADD COLUMN make   VARCHAR(100) NULL AFTER brand,
    ADD COLUMN model  VARCHAR(100) NULL AFTER make,
    ADD COLUMN code   VARCHAR(100) NULL AFTER model;
