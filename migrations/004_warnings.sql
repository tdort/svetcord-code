-- Migration: add site-wide warnings (a lighter action than a ban).
-- A warning never sets users.is_banned — it just shows up on the
-- user's own Account Standing screen and expires automatically after
-- 90 days. Run with:
--   mysql -u root -p discord_clone < migrations/004_warnings.sql

USE discord_clone;

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
