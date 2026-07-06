-- Migration 031: Convert role column from ENUM to VARCHAR(50)
-- ENUM was missing 'supervisor' and other newer roles, causing MySQL to
-- silently drop any unrecognised value on INSERT/UPDATE. VARCHAR is flexible
-- and requires no schema change when new roles are introduced.

ALTER TABLE `users`
MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'mechanic';
