-- Migration: points currency, "Nitro" premium perk, and quests (currently
-- just "watch an ad" for points, more quest types can reuse quest_claims).
-- Run with:
--   mysql -u root -p discord_clone < migrations/007_nitro_quests.sql

USE discord_clone;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS points      INT UNSIGNED NOT NULL DEFAULT 0 AFTER site_badge,
    ADD COLUMN IF NOT EXISTS nitro_until DATETIME NULL DEFAULT NULL AFTER points;

-- One row per completed quest action. Used to enforce per-quest cooldowns
-- and daily caps (e.g. "watch_ad" can only be claimed every N seconds, up
-- to a daily limit) by counting/looking-up rows for a user + quest_type.
CREATE TABLE IF NOT EXISTS quest_claims (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    quest_type  VARCHAR(32) NOT NULL DEFAULT 'watch_ad',
    reward      INT UNSIGNED NOT NULL,
    claimed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_quest_time (user_id, quest_type, claimed_at)
) ENGINE=InnoDB;
