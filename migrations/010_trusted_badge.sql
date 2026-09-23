-- Migration: adds "trusted" as a site_badge option — a lightweight
-- verification badge for trusted/verified users, assigned the same way
-- as Owner/Co-Owner/Staff from the admin panel. Run with:
--   mysql -u root -p discord_clone < migrations/010_trusted_badge.sql

USE discord_clone;

ALTER TABLE users
    MODIFY COLUMN site_badge ENUM('none','owner','co_owner','staff','trusted') NOT NULL DEFAULT 'none';
