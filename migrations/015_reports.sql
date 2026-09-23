-- Migration: user reports. Anyone can report another user (optionally
-- attached to a specific message for context); admins review/resolve
-- them from the admin panel's new Reports tab.
-- Run with:
--   mysql -u root -p discord_clone < migrations/015_reports.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS user_reports (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id       INT NOT NULL,
    reported_user_id  INT NOT NULL,
    message_id        INT NULL DEFAULT NULL,
    message_snapshot  TEXT NULL DEFAULT NULL,
    reason            VARCHAR(500) NOT NULL,
    status            ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at       TIMESTAMP NULL DEFAULT NULL,
    resolved_by       INT NULL DEFAULT NULL,
    FOREIGN KEY (reporter_id)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by)      REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB;
