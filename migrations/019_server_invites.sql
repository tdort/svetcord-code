-- Migration: proper multi-invite system, like Discord's. A server can have
-- many active invite links at once, each independently configurable
-- (limited or unlimited uses, expires or never). The original single
-- servers.invite_code keeps working as the server's "default" invite —
-- these are IN ADDITION to it, not a replacement.
-- Run with:
--   mysql -u root -p discord_clone < migrations/019_server_invites.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS server_invites (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT NOT NULL,
    code       VARCHAR(16) NOT NULL UNIQUE,
    created_by INT NOT NULL,
    max_uses   INT UNSIGNED NULL DEFAULT NULL, -- NULL = unlimited
    uses       INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NULL DEFAULT NULL,     -- NULL = never expires
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id)  REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;
