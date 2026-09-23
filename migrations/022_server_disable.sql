-- Migration: site-admin server disable ("ban a server"). Different from
-- server_bans (which bans a user from one server) — this disables the
-- WHOLE server for everyone. Disabled servers show a takeover screen
-- with the reason and a Leave Server button instead of channels/messages,
-- and all channel/message API access to them is blocked server-side.
-- Run with:
--   mysql -u root -p discord_clone < migrations/022_server_disable.sql

USE discord_clone;

ALTER TABLE servers
  ADD COLUMN IF NOT EXISTS is_disabled     TINYINT(1) NOT NULL DEFAULT 0 AFTER invite_code,
  ADD COLUMN IF NOT EXISTS disabled_reason VARCHAR(255) DEFAULT NULL AFTER is_disabled,
  ADD COLUMN IF NOT EXISTS disabled_at     TIMESTAMP NULL DEFAULT NULL AFTER disabled_reason,
  ADD COLUMN IF NOT EXISTS disabled_by     INT NULL DEFAULT NULL AFTER disabled_at,
  ADD CONSTRAINT fk_servers_disabled_by FOREIGN KEY (disabled_by) REFERENCES users(id) ON DELETE SET NULL;
