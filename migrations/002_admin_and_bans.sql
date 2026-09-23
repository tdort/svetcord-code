-- Migration: add site-admin flag + site-wide ban support
-- Only needed if your `discord_clone` database was created before this
-- migration existed (schema.sql already includes these for fresh
-- installs). Run with:
--   mysql -u root -p discord_clone < migrations/002_admin_and_bans.sql

USE discord_clone;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_admin  TINYINT(1) NOT NULL DEFAULT 0 AFTER last_seen,
  ADD COLUMN IF NOT EXISTS is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;

CREATE TABLE IF NOT EXISTS site_bans (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    banned_by   INT NOT NULL,
    banned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lifted_at   TIMESTAMP NULL DEFAULT NULL,
    lifted_by   INT NULL DEFAULT NULL,
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lifted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_active (user_id, lifted_at)
) ENGINE=InnoDB;

-- Make yourself the first admin (edit the username, then run):
-- UPDATE users SET is_admin = 1 WHERE username = 'your-username';
