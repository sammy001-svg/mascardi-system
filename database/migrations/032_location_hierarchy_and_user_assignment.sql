-- Migration 032: Location hierarchy + user location assignment
--
-- The supervisor portal (modules/supervisor/*.php) and the Locations admin
-- CRUD (modules/locations/add.php, edit.php) have always assumed:
--   - locations.parent_id (sub-location hierarchy, e.g. "Bay A" under "Main Yard")
--   - locations.type includes 'yard'/'showroom'/'port'/'office'
--   - users.location_id (which location a supervisor/staff member is assigned to)
-- None of these were ever added by a migration — this closes that gap.

ALTER TABLE locations ADD COLUMN IF NOT EXISTS parent_id INT NULL AFTER id;
ALTER TABLE locations ADD CONSTRAINT fk_loc_parent FOREIGN KEY (parent_id) REFERENCES locations(id) ON DELETE SET NULL;

ALTER TABLE locations MODIFY COLUMN type ENUM('yard','showroom','port','office') NOT NULL DEFAULT 'yard';

ALTER TABLE users ADD COLUMN IF NOT EXISTS location_id INT NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;
