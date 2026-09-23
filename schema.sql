-- Discord Clone Database Schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS discord_clone
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE discord_clone;

-- ---------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(32)  NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    avatar_url    VARCHAR(255) DEFAULT NULL,
    status        ENUM('online','idle','dnd','offline') NOT NULL DEFAULT 'offline',
    bio           VARCHAR(190) DEFAULT NULL,
    pronouns      VARCHAR(40)  DEFAULT NULL,
    accent_color  VARCHAR(7)   NOT NULL DEFAULT '#5865f2',
    last_seen     TIMESTAMP NULL DEFAULT NULL,
    is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
    is_banned     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Servers ("guilds")
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS servers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    owner_id     INT NOT NULL,
    icon_url     VARCHAR(255) DEFAULT NULL,
    invite_code  VARCHAR(16) NOT NULL UNIQUE,
    is_disabled      TINYINT(1) NOT NULL DEFAULT 0,
    disabled_reason  VARCHAR(255) DEFAULT NULL,
    disabled_at      TIMESTAMP NULL DEFAULT NULL,
    disabled_by      INT NULL DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (disabled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Roles (per-server, with a permission bitmask)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    server_id   INT NOT NULL,
    name        VARCHAR(50) NOT NULL,
    color       VARCHAR(7) NOT NULL DEFAULT '#99AAB5',
    permissions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    position    INT NOT NULL DEFAULT 0,
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Server membership
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS server_members (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT NOT NULL,
    user_id    INT NOT NULL,
    nickname   VARCHAR(32) DEFAULT NULL,
    joined_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member (server_id, user_id),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- Many-to-many: members <-> roles
CREATE TABLE IF NOT EXISTS member_roles (
    member_id INT NOT NULL,
    role_id   INT NOT NULL,
    PRIMARY KEY (member_id, role_id),
    FOREIGN KEY (member_id) REFERENCES server_members(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id)   REFERENCES roles(id)           ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Channels
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS channels (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT NOT NULL,
    name       VARCHAR(100) NOT NULL,
    type       ENUM('text','voice') NOT NULL DEFAULT 'text',
    topic      VARCHAR(255) DEFAULT NULL,
    position   INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Channel messages
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id    INT NOT NULL,
    content    TEXT NOT NULL,
    edited_at  TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_channel_created (channel_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Direct messages
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dm_conversations (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_one_id  INT NOT NULL,
    user_two_id  INT NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_convo (user_one_id, user_two_id),
    FOREIGN KEY (user_one_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user_two_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dm_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id       INT NOT NULL,
    content         TEXT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES dm_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)            ON DELETE CASCADE,
    INDEX idx_convo_created (conversation_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Friendships. One row per pair: user_id sent the request to
-- friend_id; status flips to 'accepted' once friend_id accepts.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS friendships (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    friend_id   INT NOT NULL,
    status      ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_friendship (user_id, friend_id),
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Per-user folders for organizing your friends list (e.g. "Close
-- Friends", "Work"). A friend can sit in at most one group per owner.
CREATE TABLE IF NOT EXISTS friend_groups (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(50) NOT NULL,
    position    INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- owner_user_id is whose friends list this grouping belongs to, so
-- the same friendship can be filed differently (or left ungrouped)
-- on each side — grouping is personal organization, not a shared
-- property of the friendship itself.
CREATE TABLE IF NOT EXISTS friend_group_members (
    owner_user_id  INT NOT NULL,
    friend_user_id INT NOT NULL,
    group_id       INT NOT NULL,
    PRIMARY KEY (owner_user_id, friend_user_id),
    FOREIGN KEY (owner_user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)       REFERENCES friend_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Site-wide bans (admin panel). A user is currently banned iff
-- users.is_banned = 1; this table is the reason/audit trail and
-- keeps lifted bans around for history instead of deleting them.
-- ---------------------------------------------------------------
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

-- ---------------------------------------------------------------
-- Site-wide warnings (admin panel). Unlike a ban, a warning never
-- sets users.is_banned — it's a lighter-touch action that just
-- shows up on the user's own Account Standing screen and expires
-- automatically after 90 days.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_warnings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    warned_by   INT NOT NULL,
    warned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (warned_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, expires_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Voice calling (audio-only). A "call" is either attached to a DM
-- conversation (1:1) or a voice channel (group). Peers connect
-- directly over WebRTC; these tables only carry signaling
-- (offer/answer/ICE candidates) plus who is currently connected.
-- ---------------------------------------------------------------
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
