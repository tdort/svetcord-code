-- Migration: channel categories — named groups that channels can be sorted
-- into within a server sidebar (Discord-style). Uncategorized channels
-- (category_id NULL) keep behaving exactly as before. Run with:
--   mysql -u root -p discord_clone < migrations/008_channel_categories.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS channel_categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT NOT NULL,
    name       VARCHAR(100) NOT NULL,
    position   INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE channels
    ADD COLUMN IF NOT EXISTS category_id INT NULL DEFAULT NULL AFTER server_id;

ALTER TABLE channels
    ADD CONSTRAINT fk_channels_category
        FOREIGN KEY (category_id) REFERENCES channel_categories(id) ON DELETE SET NULL;
