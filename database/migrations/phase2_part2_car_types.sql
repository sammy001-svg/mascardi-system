-- Migration: Add Car Type and Owner Details
-- This allows differentiating between Mascardi's inventory (for sale) and client vehicles (for service/repairs).

ALTER TABLE cars 
ADD COLUMN car_type ENUM('inventory', 'client') DEFAULT 'inventory' AFTER fuel_type,
ADD COLUMN owner_name VARCHAR(150) NULL AFTER car_type,
ADD COLUMN owner_phone VARCHAR(20) NULL AFTER owner_name;

-- Log activity for migration
-- INSERT INTO audit_logs (action, module, details) VALUES ('migration', 'database', 'Added car_type and owner fields to cars table');
