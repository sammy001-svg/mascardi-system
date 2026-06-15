-- Add dedicated KRA PIN column to clients table
-- KRA PIN is separate from id_number (National ID / Passport)
ALTER TABLE clients ADD COLUMN kra_pin VARCHAR(20) NULL AFTER id_number;
