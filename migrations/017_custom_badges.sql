-- Migration: custom badges. Admins upload a small icon + name once, then
-- assign it to any number of users by id/username. A user can hold many
-- badges (unlike the single site_badge enum). Shown on profile cards.
-- Run with:
--   mysql -u root -p discord_clone < migrations/017_custom_badges.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS custom_badges (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50) NOT NULL,
    icon_url   VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_custom_badges (
    user_id     INT NOT NULL,
    badge_id    INT NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, badge_id),
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (badge_id) REFERENCES custom_badges(id) ON DELETE CASCADE
) ENGINE=InnoDB;
