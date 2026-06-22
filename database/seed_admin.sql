-- ============================================================
-- Admin Seed — Super Admin Account
-- ============================================================
-- Username : Mascardiadmin
-- Password : Mas@123@1s  (bcrypt hash below)
-- Role     : admin
-- ============================================================
-- HOW TO RUN:
--   Option A — phpMyAdmin: select 'mascardi_db', click SQL tab, paste & run.
--   Option B — MySQL CLI : mysql -u root -p mascardi_db < database/seed_admin.sql
-- ============================================================
-- DELETE this file from the server after running.
-- ============================================================

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
