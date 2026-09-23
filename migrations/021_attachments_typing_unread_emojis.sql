USE discord_clone;

-- ---------------------------------------------------------------
-- File/image attachments on messages (one per message, mirrors
-- avatar/banner upload pattern — client uploads first via
-- upload.php?action=attachment, then references the URL when sending).
-- ---------------------------------------------------------------
ALTER TABLE messages
  ADD COLUMN attachment_url  VARCHAR(255) DEFAULT NULL AFTER content,
  ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_url,
  ADD COLUMN attachment_type VARCHAR(20)  DEFAULT NULL AFTER attachment_name; -- 'image' or 'file'

ALTER TABLE dm_messages
  ADD COLUMN attachment_url  VARCHAR(255) DEFAULT NULL AFTER content,
  ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_url,
  ADD COLUMN attachment_type VARCHAR(20)  DEFAULT NULL AFTER attachment_name;

-- ---------------------------------------------------------------
-- Typing indicators. Ephemeral by design — rows are just upserted on
-- every keystroke ping and filtered by updated_at in queries (a row
-- older than a few seconds means "no longer typing"). Occasionally
-- pruned rather than cleaned up per-row.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS typing_indicators (
    kind       ENUM('channel','dm') NOT NULL,
    target_id  INT NOT NULL,
    user_id    INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, target_id, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Read state, for unread badges. One row per user per channel/DM
-- tracking the highest message id they've seen.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS read_state (
    kind                ENUM('channel','dm') NOT NULL,
    target_id           INT NOT NULL,
    user_id             INT NOT NULL,
    last_read_message_id INT NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, target_id, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Custom server emojis. Referenced in message text as :name: and
-- rendered inline as an image (see renderMessageContentHtml in app.js).
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS custom_emojis (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    server_id  INT NOT NULL,
    name       VARCHAR(32) NOT NULL, -- alnum/underscore, matched case-insensitively
    image_url  VARCHAR(255) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_server_emoji_name (server_id, name),
    FOREIGN KEY (server_id)  REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;
