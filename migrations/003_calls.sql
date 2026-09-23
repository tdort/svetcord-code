-- Migration: add audio calling (DM + voice channels)
-- Only needed if your `discord_clone` database was created before this
-- migration existed (schema.sql already includes these for fresh
-- installs). Run with:
--   mysql -u root -p discord_clone < migrations/003_calls.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS calls (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    type                ENUM('dm','channel') NOT NULL,
    dm_conversation_id  INT NULL,
    channel_id          INT NULL,
    started_by          INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at            TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (dm_conversation_id) REFERENCES dm_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (channel_id)         REFERENCES channels(id)         ON DELETE CASCADE,
    FOREIGN KEY (started_by)         REFERENCES users(id)            ON DELETE CASCADE,
    INDEX idx_dm_active (dm_conversation_id, ended_at),
    INDEX idx_channel_active (channel_id, ended_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS call_participants (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    call_id    INT NOT NULL,
    user_id    INT NOT NULL,
    joined_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at    TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_call_active (call_id, left_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS call_signals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    call_id       INT NOT NULL,
    from_user_id  INT NOT NULL,
    to_user_id    INT NOT NULL,
    type          ENUM('offer','answer','candidate','leave') NOT NULL,
    payload       TEXT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (call_id)      REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (to_user_id)   REFERENCES users(id)  ON DELETE CASCADE,
    INDEX idx_signal_poll (call_id, to_user_id, id)
) ENGINE=InnoDB;
