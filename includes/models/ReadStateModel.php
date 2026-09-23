<?php
require_once __DIR__ . '/../Database.php';

class ReadStateModel
{
    /** Marks everything up to $messageId as read. No-ops backward (never un-reads). */
    public static function markRead(string $kind, int $targetId, int $userId, int $messageId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO read_state (kind, target_id, user_id, last_read_message_id, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
               updated_at = NOW()'
        );
        $stmt->execute([$kind, $targetId, $userId, $messageId]);
    }

    /**
     * Unread counts for every text channel the user can reach (across all
     * their servers), keyed by channel_id, omitting channels with 0 unread.
     * Uses server membership only (not per-channel permission overrides) —
     * a reasonable simplification since most channels are open to all members.
     */
    public static function channelUnreadCounts(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT c.id AS channel_id, COUNT(m.id) AS unread_count
             FROM channels c
             JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = ?
             LEFT JOIN read_state rs ON rs.kind = \'channel\' AND rs.target_id = c.id AND rs.user_id = ?
             LEFT JOIN messages m ON m.channel_id = c.id
               AND m.id > IFNULL(rs.last_read_message_id, 0)
               AND m.user_id != ?
             WHERE c.type = \'text\'
             GROUP BY c.id
             HAVING unread_count > 0'
        );
        $stmt->execute([$userId, $userId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['channel_id']] = (int) $row['unread_count'];
        }
        return $out;
    }

    /** Unread counts for every DM conversation the user is in, keyed by conversation_id. */
    public static function dmUnreadCounts(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT dc.id AS conversation_id, COUNT(dm.id) AS unread_count
             FROM dm_conversations dc
             LEFT JOIN read_state rs ON rs.kind = \'dm\' AND rs.target_id = dc.id AND rs.user_id = ?
             LEFT JOIN dm_messages dm ON dm.conversation_id = dc.id
               AND dm.id > IFNULL(rs.last_read_message_id, 0)
               AND dm.sender_id != ?
             WHERE dc.user_one_id = ? OR dc.user_two_id = ?
             GROUP BY dc.id
             HAVING unread_count > 0'
        );
        $stmt->execute([$userId, $userId, $userId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['conversation_id']] = (int) $row['unread_count'];
        }
        return $out;
    }

    /** server_id => true/false, for the little unread dot on server-rail icons. */
    public static function serverHasUnread(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.server_id
             FROM channels c
             JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = ?
             LEFT JOIN read_state rs ON rs.kind = \'channel\' AND rs.target_id = c.id AND rs.user_id = ?
             JOIN messages m ON m.channel_id = c.id
               AND m.id > IFNULL(rs.last_read_message_id, 0)
               AND m.user_id != ?
             WHERE c.type = \'text\''
        );
        $stmt->execute([$userId, $userId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['server_id']] = true;
        }
        return $out;
    }
}
