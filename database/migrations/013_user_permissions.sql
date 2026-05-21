-- Migration 013: Per-user module permissions
-- Stores explicit access/write grants per user per module.
-- When any row exists for a user, the entire role-default map is bypassed.
-- Admin role always bypasses this table entirely.

CREATE TABLE IF NOT EXISTS user_permissions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    module     VARCHAR(50) NOT NULL,
    can_access TINYINT(1) NOT NULL DEFAULT 0,
    can_write  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_module (user_id, module),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
