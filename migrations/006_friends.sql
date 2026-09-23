-- Migration: friend groups.
-- The `friendships` table already existed in schema.sql (unused until
-- now) with columns user_id (requester) / friend_id (addressee) /
-- status. This migration adds friend groups: per-user folders for
-- organizing your friends list (e.g. "Close Friends", "Work"). Run
-- with:
--   mysql -u root -p discord_clone < migrations/006_friends.sql

USE discord_clone;

CREATE TABLE IF NOT EXISTS friend_groups (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(50) NOT NULL,
    position    INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- owner_user_id is whose friends list this grouping belongs to, so
-- the same friendship can be filed differently (or left ungrouped)
-- on each side -- grouping is personal organization, not a shared
-- property of the friendship itself.
CREATE TABLE IF NOT EXISTS friend_group_members (
    owner_user_id  INT NOT NULL,
    friend_user_id INT NOT NULL,
    group_id       INT NOT NULL,
    PRIMARY KEY (owner_user_id, friend_user_id),
    FOREIGN KEY (owner_user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)       REFERENCES friend_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB;
