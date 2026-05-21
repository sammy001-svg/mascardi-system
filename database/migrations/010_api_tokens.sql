-- Migration 010: API token support
-- Adds api_token column to users table for REST API authentication

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS api_token VARCHAR(64) NULL UNIQUE AFTER status,
    ADD INDEX IF NOT EXISTS idx_api_token (api_token);
