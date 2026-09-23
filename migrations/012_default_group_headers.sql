-- Migration: lets a server "delete" the built-in Text Channels / Voice
-- Channels headers, same as deleting a real category — the label just
-- disappears, channels underneath are untouched (they simply render
-- without a group label, same as any other uncategorized channel).
-- Run with:
--   mysql -u root -p discord_clone < migrations/012_default_group_headers.sql

USE discord_clone;

ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS hide_text_header  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS hide_voice_header TINYINT(1) NOT NULL DEFAULT 0;
