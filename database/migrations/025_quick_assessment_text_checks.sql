-- Migration 025: Extend quick_assessments
-- • Change check columns from ENUM to VARCHAR(255) so free text can be entered
-- • Add new check columns: jack, dents, items_left, mileage, fuel_level, radio
-- • Add client_email column

ALTER TABLE quick_assessments
    MODIFY COLUMN check_tyres      VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_lights     VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_exterior   VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_engine     VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_interior   VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_brakes     VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_fluids     VARCHAR(255) NULL DEFAULT NULL,
    MODIFY COLUMN check_electrical VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN check_jack          VARCHAR(255)  NULL AFTER check_electrical,
    ADD COLUMN check_dents         TEXT          NULL AFTER check_jack,
    ADD COLUMN check_items_left    TEXT          NULL AFTER check_dents,
    ADD COLUMN check_mileage       VARCHAR(50)   NULL AFTER check_items_left,
    ADD COLUMN check_fuel_level    VARCHAR(50)   NULL AFTER check_mileage,
    ADD COLUMN check_radio         VARCHAR(255)  NULL AFTER check_fuel_level,
    ADD COLUMN client_email        VARCHAR(150)  NULL AFTER client_phone;
