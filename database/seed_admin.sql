-- ============================================================
-- Admin Seed — Two-tier Admin Accounts
-- ============================================================
-- SUPER ADMIN (full comprehensive portal)
--   Username : Mascardisuper
--   Password : Mas@123@1s
--   Role     : super_admin
--
-- ADMIN (simple focused portal — Workshop + Sales dashboards)
--   Username : Mascardiadmin
--   Password : Mas@123@1s
--   Role     : admin
-- ============================================================
-- HOW TO RUN:
--   Option A — phpMyAdmin: select 'mascardi_db', SQL tab, paste & run.
--   Option B — MySQL CLI : mysql -u root -p mascardi_db < database/seed_admin.sql
-- ============================================================
-- Run migration 030 FIRST:
--   database/migrations/030_super_admin_role.sql
-- ============================================================
-- DELETE this file from the server after running.
-- ============================================================

-- Super Admin account (full original dashboard)
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

-- Admin account (simple portal — Workshop + Sales dashboards)
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
