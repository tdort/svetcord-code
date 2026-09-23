-- Migration: emoji reactions on channel messages. One row per
-- user+message+emoji (toggle on/off); counts and "did I react" are
-- computed per request in MessageModel::attachReactions().
-- Run with:
--   mysql -u root -p discord_clone < migrations/016_reactions.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS message_reactions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id    INT NOT NULL,
    emoji      VARCHAR(16) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message_user_emoji (message_id, user_id, emoji),
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;
