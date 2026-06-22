-- ============================================================
-- ONE-SHOT ADMIN SETUP
-- Run this entire file in phpMyAdmin → select mascardi_db → SQL tab → Go
-- ============================================================
-- 1. Adds super_admin to the role ENUM
-- 2. Creates / updates both admin accounts
-- ============================================================
-- Credentials after running:
--   Super Admin  →  username: Mascardisuper   password: Mas@123@1s
--   Admin        →  username: Mascardiadmin   password: Mas@123@1s
-- ============================================================
-- DELETE this file from the server after running.
-- ============================================================

-- Step 1: Add super_admin to the role ENUM (safe to run multiple times)
ALTER TABLE `users`
MODIFY COLUMN `role` ENUM(
    'admin',
    'super_admin',
    'general_manager',
    'workshop_manager',
    'finance_manager',
    'accountant',
    'cashier',
    'sales_manager',
    'sales_officer',
    'sales_person',
    'customer_relations',
    'receptionist',
    'mechanic',
    'driver',
    'inventory_manager',
    'procurement_officer',
    'hr_manager',
    'manager'
) NOT NULL DEFAULT 'mechanic';

-- Step 2: Super Admin account (full original comprehensive dashboard)
-- Password: Mas@123@1s
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `status`)
VALUES (
    'Mascardi Super Admin',
    'Mascardisuper',
    'superadmin@mascardicaryard.com',
    '$2y$10$35prhpQQYuPx.TmbKmXOf.z5dGD1Tbn4y5CDjLpcCsYEn/pZ7CN16',
    'super_admin',
    'active'
)
ON DUPLICATE KEY UPDATE
    `name`     = VALUES(`name`),
    `password` = VALUES(`password`),
    `role`     = 'super_admin',
    `status`   = 'active';

-- Step 3: Admin account (simple portal — Workshop + Sales dashboards)
-- Password: Mas@123@1s
INSERT INTO `users` (`name`, `username`, `email`, `password`, `role`, `status`)
VALUES (
    'Mascardi Admin',
    'Mascardiadmin',
    'admin@mascardicaryard.com',
    '$2y$10$35prhpQQYuPx.TmbKmXOf.z5dGD1Tbn4y5CDjLpcCsYEn/pZ7CN16',
    'admin',
    'active'
)
ON DUPLICATE KEY UPDATE
    `name`     = VALUES(`name`),
    `password` = VALUES(`password`),
    `role`     = 'admin',
    `status`   = 'active';

-- Verify: should show both accounts
SELECT id, name, username, role, status FROM users WHERE role IN ('admin', 'super_admin');
