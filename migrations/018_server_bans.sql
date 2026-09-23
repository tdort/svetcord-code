-- Migration: per-server bans. Different from the site-wide admin ban —
-- this just blocks someone from rejoining ONE server via its invite code.
-- Run with:
--   mysql -u root -p discord_clone < migrations/018_server_bans.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS server_bans (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT NOT NULL,
    user_id    INT NOT NULL,
    banned_by  INT NOT NULL,
    reason     VARCHAR(255) NULL DEFAULT NULL,
    banned_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_server_user (server_id, user_id),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (banned_by) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;
