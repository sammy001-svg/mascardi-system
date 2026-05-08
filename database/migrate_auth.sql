-- ============================================================
-- Mascardi Auth Migration — run this on an EXISTING database
-- (If you are doing a fresh install, use schema.sql instead)
-- ============================================================

USE mascardi_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(150),
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','mechanic','driver') NOT NULL DEFAULT 'mechanic',
    linked_id INT NULL COMMENT 'ID in drivers or mechanics table',
    linked_type ENUM('driver','mechanic') NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- After running this SQL, open your browser and navigate to /login.php
-- The first-run setup wizard will appear and let you create the admin account.
