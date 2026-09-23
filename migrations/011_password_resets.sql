-- Migration: password reset tokens (forgot-password via email). Only the
-- SHA-256 hash of the token is stored — the raw token only ever exists in
-- the emailed link, so a DB leak alone can't be used to reset accounts.
-- Run with:
--   mysql -u root -p discord_clone < migrations/011_password_resets.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash)
) ENGINE=InnoDB;
