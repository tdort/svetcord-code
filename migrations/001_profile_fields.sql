-- Migration: add profile personalization fields to users
-- Only needed if your `discord_clone` database was created before this
-- migration existed (schema.sql already includes these columns for fresh
-- installs). Run with:
--   mysql -u root -p discord_clone < migrations/001_profile_fields.sql

USE discord_clone;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS bio          VARCHAR(190) DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS pronouns     VARCHAR(40)  DEFAULT NULL AFTER bio,
  ADD COLUMN IF NOT EXISTS accent_color VARCHAR(7)   NOT NULL DEFAULT '#5865f2' AFTER pronouns;
