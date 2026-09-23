-- Migration: site-wide profile badges (Owner / Co-Owner / Staff).
-- These are NOT server roles — they're a single global flag per user,
-- assigned from the admin panel, shown as a small icon next to their
-- name everywhere (chat, member list, profile). Run with:
--   mysql -u root -p discord_clone < migrations/005_badges.sql

USE discord_clone;

ALTER TABLE users
    ADD COLUMN site_badge ENUM('none','owner','co_owner','staff') NOT NULL DEFAULT 'none' AFTER is_admin;
