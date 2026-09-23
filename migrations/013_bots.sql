-- Migration: bot accounts. A bot is a real row in `users` (so it can be a
-- server member, send messages, show up in member lists, etc. through all
-- the existing code paths) flagged is_bot=1, paired with a `bots` row that
-- holds who owns it and a hashed API token. Bots authenticate to the API
-- with an `Authorization: Bot <token>` header instead of a session cookie.
-- Run with:
--   mysql -u root -p discord_clone < migrations/013_bots.sql

USE discord_clone;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;

CREATE TABLE IF NOT EXISTS bots (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL UNIQUE,
    owner_id   INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
