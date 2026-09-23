-- Adds a custom profile banner image (Nitro perk). When NULL, the
-- profile card/modal falls back to the existing flat accent_color banner.
USE discord_clone;

ALTER TABLE users
  ADD COLUMN banner_url VARCHAR(255) DEFAULT NULL AFTER accent_color;
