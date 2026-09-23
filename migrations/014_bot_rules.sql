-- Migration: no-code bot automations. Each row is one trigger/response
-- rule for a bot — evaluated server-side automatically whenever a human
-- sends a message in a channel that bot's a member of. No polling, no
-- separate process required. Also adds a `script` column to `bots` so the
-- Developer Portal's code editor can save your draft self-hosted bot
-- script between visits (it's just stored text — never executed on the
-- server, since running arbitrary uploaded code server-side would be a
-- serious security risk; you run it yourself, see the code editor's
-- Download button).
-- Run with:
--   mysql -u root -p discord_clone < migrations/014_bot_rules.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS bot_rules (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    bot_id         INT NOT NULL,
    trigger_type   ENUM('contains','equals','starts_with') NOT NULL DEFAULT 'contains',
    trigger_value  VARCHAR(200) NOT NULL,
    response_text  VARCHAR(2000) NOT NULL,
    enabled        TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE bots
    ADD COLUMN IF NOT EXISTS script MEDIUMTEXT NULL DEFAULT NULL;
