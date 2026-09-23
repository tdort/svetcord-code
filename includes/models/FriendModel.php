<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/UserModel.php';

/**
 * Backs the Friends system: requests, accepted friendships, and
 * per-user friend groups (folders for organizing your friends list).
 *
 * The underlying `friendships` table stores one row per pair:
 * user_id is whoever sent the request, friend_id is who received it,
 * and status flips from 'pending' to 'accepted' once they accept.
 */
class FriendModel
{
    /**
     * Send a friend request by username. If the target already sent
     * *us* a pending request, this accepts it instead of creating a
     * duplicate (mirrors how Discord/most apps behave).
     */
    public static function sendRequest(int $fromId, string $targetUsername): array
    {
        $pdo = Database::get();
        $target = UserModel::findByUsername($targetUsername);
        if (!$target) {
            return ['success' => false, 'error' => 'No user found with that username.'];
        }
        $targetId = (int) $target['id'];
        if ($targetId === $fromId) {
            return ['success' => false, 'error' => "You can't friend yourself."];
        }

        $existing = self::findPairRow($fromId, $targetId);
        if ($existing) {
            if ($existing['status'] === 'accepted') {
                return ['success' => false, 'error' => 'You are already friends.'];
            }
            // A pending request already exists in one direction or the other.
            if ((int) $existing['friend_id'] === $fromId) {
                // They'd already asked us — accept it instead of duplicating.
                $pdo->prepare('UPDATE friendships SET status = ? WHERE id = ?')
                    ->execute(['accepted', $existing['id']]);
                return ['success' => true, 'accepted' => true];
            }
            return ['success' => false, 'error' => 'A friend request is already pending.'];
        }

        $pdo->prepare(
            'INSERT INTO friendships (user_id, friend_id, status, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$fromId, $targetId, 'pending']);

        return ['success' => true, 'accepted' => false];
    }

    /** Look up any existing row (either direction) between two users. */
    private static function findPairRow(int $a, int $b): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM friendships
             WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$a, $b, $b, $a]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Accept an incoming request addressed to us. */
    public static function acceptRequest(int $requestId, int $myId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM friendships WHERE id = ? AND friend_id = ? AND status = 'pending'"
        );
        $stmt->execute([$requestId, $myId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'That friend request no longer exists.'];
        }
        $pdo->prepare("UPDATE friendships SET status = 'accepted' WHERE id = ?")->execute([$requestId]);
        return ['success' => true];
    }

    /** Decline an incoming request, or cancel one we sent — same action either way (delete the row). */
    public static function removeRequest(int $requestId, int $myId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM friendships WHERE id = ? AND status = 'pending' AND (user_id = ? OR friend_id = ?)"
        );
        $stmt->execute([$requestId, $myId, $myId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'That friend request no longer exists.'];
        }
        $pdo->prepare('DELETE FROM friendships WHERE id = ?')->execute([$requestId]);
        return ['success' => true];
    }

    /** Remove an existing friendship (either direction) and clean up any group placement. */
    public static function removeFriend(int $myId, int $otherUserId): array
    {
        $pdo = Database::get();
        $row = self::findPairRow($myId, $otherUserId);
        if (!$row || $row['status'] !== 'accepted') {
            return ['success' => false, 'error' => 'You are not friends with that user.'];
        }
        $pdo->prepare('DELETE FROM friendships WHERE id = ?')->execute([$row['id']]);
        $pdo->prepare('DELETE FROM friend_group_members WHERE owner_user_id = ? AND friend_user_id = ?')
            ->execute([$myId, $otherUserId]);
        $pdo->prepare('DELETE FROM friend_group_members WHERE owner_user_id = ? AND friend_user_id = ?')
            ->execute([$otherUserId, $myId]);
        return ['success' => true];
    }

    /** All accepted friends for a user, with the group (if any) each is filed under on their list. */
    public static function listFriends(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT f.id, f.created_at,
                    CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END AS other_user_id
             FROM friendships f
             WHERE f.status = 'accepted' AND (f.user_id = ? OR f.friend_id = ?)"
        );
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll();
        if (!$rows) return [];

        $ids = array_column($rows, 'other_user_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $userStmt = $pdo->prepare(
            "SELECT id, username, avatar_url, status, site_badge FROM users WHERE id IN ($placeholders)"
        );
        $userStmt->execute($ids);
        $usersById = [];
        foreach ($userStmt->fetchAll() as $u) {
            $usersById[$u['id']] = $u;
        }

        $groupStmt = $pdo->prepare(
            "SELECT friend_user_id, group_id FROM friend_group_members WHERE owner_user_id = ? AND friend_user_id IN ($placeholders)"
        );
        $groupStmt->execute(array_merge([$userId], $ids));
        $groupByFriend = [];
        foreach ($groupStmt->fetchAll() as $g) {
            $groupByFriend[$g['friend_user_id']] = (int) $g['group_id'];
        }

        $friends = [];
        foreach ($rows as $row) {
            $otherId = (int) $row['other_user_id'];
            if (!isset($usersById[$otherId])) continue; // shouldn't happen, but stay defensive
            $friends[] = array_merge($usersById[$otherId], [
                'friendship_id' => (int) $row['id'],
                'friends_since' => $row['created_at'],
                'group_id' => $groupByFriend[$otherId] ?? null,
            ]);
        }
        usort($friends, fn($a, $b) => strcasecmp($a['username'], $b['username']));
        return $friends;
    }

    /** Pending requests sent TO us (need our accept/decline). */
    public static function listIncoming(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT f.id, f.created_at, u.id AS user_id, u.username, u.avatar_url, u.status AS presence, u.site_badge
             FROM friendships f JOIN users u ON u.id = f.user_id
             WHERE f.friend_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Pending requests we sent, awaiting the other side. */
    public static function listOutgoing(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT f.id, f.created_at, u.id AS user_id, u.username, u.avatar_url, u.status AS presence, u.site_badge
             FROM friendships f JOIN users u ON u.id = f.friend_id
             WHERE f.user_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * The relationship between $viewerId and $targetId, for the profile
     * modal's friend button: none / friends / incoming (they asked us)
     * / outgoing (we asked them) — plus the friendship row id so the
     * client knows what to act on.
     */
    public static function getRelationship(int $viewerId, int $targetId): array
    {
        if ($viewerId === $targetId) {
            return ['status' => 'self', 'request_id' => null];
        }
        $row = self::findPairRow($viewerId, $targetId);
        if (!$row) {
            return ['status' => 'none', 'request_id' => null];
        }
        if ($row['status'] === 'accepted') {
            return ['status' => 'friends', 'request_id' => (int) $row['id'], 'since' => $row['created_at']];
        }
        // pending
        if ((int) $row['user_id'] === $viewerId) {
            return ['status' => 'outgoing', 'request_id' => (int) $row['id']];
        }
        return ['status' => 'incoming', 'request_id' => (int) $row['id']];
    }

    /* -------------------- Friend groups -------------------- */

    public static function listGroups(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, name, position FROM friend_groups WHERE user_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function createGroup(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Group name cannot be empty.'];
        }
        if (mb_strlen($name) > 50) {
            return ['success' => false, 'error' => 'Group name must be 50 characters or fewer.'];
        }
        $pdo = Database::get();
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM friend_groups WHERE user_id = ?');
        $posStmt->execute([$userId]);
        $nextPos = (int) $posStmt->fetch()['next_pos'];

        $stmt = $pdo->prepare('INSERT INTO friend_groups (user_id, name, position, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$userId, $name, $nextPos]);
        return ['success' => true, 'group_id' => (int) $pdo->lastInsertId()];
    }

    public static function renameGroup(int $groupId, int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Group name cannot be empty.'];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE friend_groups SET name = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $groupId, $userId]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Group not found.'];
        }
        return ['success' => true];
    }

    /** Deleting a group just ungroups its members — it never touches the friendships themselves. */
    public static function deleteGroup(int $groupId, int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM friend_groups WHERE id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Group not found.'];
        }
        return ['success' => true];
    }

    /** File a friend under a group (or pass null groupId to ungroup them). */
    public static function assignToGroup(int $userId, int $friendUserId, ?int $groupId): array
    {
        $pdo = Database::get();

        if (!self::areFriends($userId, $friendUserId)) {
            return ['success' => false, 'error' => 'You are not friends with that user.'];
        }

        if ($groupId === null) {
            $pdo->prepare('DELETE FROM friend_group_members WHERE owner_user_id = ? AND friend_user_id = ?')
                ->execute([$userId, $friendUserId]);
            return ['success' => true];
        }

        $stmt = $pdo->prepare('SELECT id FROM friend_groups WHERE id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Group not found.'];
        }

        $pdo->prepare(
            'INSERT INTO friend_group_members (owner_user_id, friend_user_id, group_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)'
        )->execute([$userId, $friendUserId, $groupId]);
        return ['success' => true];
    }

    public static function areFriends(int $a, int $b): bool
    {
        $row = self::findPairRow($a, $b);
        return $row !== null && $row['status'] === 'accepted';
    }

    /** Count of people who are accepted friends with BOTH users — shown on profile cards. */
    public static function mutualFriendsCount(int $a, int $b): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT CASE WHEN user_id = ? THEN friend_id ELSE user_id END AS fid
                FROM friendships WHERE status = 'accepted' AND (user_id = ? OR friend_id = ?)
             ) af
             JOIN (
                SELECT CASE WHEN user_id = ? THEN friend_id ELSE user_id END AS fid
                FROM friendships WHERE status = 'accepted' AND (user_id = ? OR friend_id = ?)
             ) bf ON af.fid = bf.fid"
        );
        $stmt->execute([$a, $a, $a, $b, $b, $b]);
        return (int) $stmt->fetchColumn();
    }
}
