-- Migration: per-channel permission overrides (Discord-style "channel
-- permissions"). Each row is one role's allow/deny bitmask override for
-- one channel; a flag not set in either mask just inherits the role's
-- server-wide permission. Run with:
--   mysql -u root -p discord_clone < migrations/009_channel_permissions.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS channel_permission_overrides (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    role_id    INT NOT NULL,
    allow      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    deny       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_channel_role (channel_id, role_id),
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id)    REFERENCES roles(id)    ON DELETE CASCADE
) ENGINE=InnoDB;
