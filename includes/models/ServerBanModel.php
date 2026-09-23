<?php
require_once __DIR__ . '/../Database.php';

class ServerBanModel
{
    public static function isBanned(int $serverId, int $userId): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT 1 FROM server_bans WHERE server_id = ? AND user_id = ?');
        $stmt->execute([$serverId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function ban(int $serverId, int $userId, int $bannedBy, ?string $reason): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO server_bans (server_id, user_id, banned_by, reason, banned_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_by = VALUES(banned_by), banned_at = NOW()'
        );
        $stmt->execute([$serverId, $userId, $bannedBy, $reason]);
    }

    public static function unban(int $serverId, int $userId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM server_bans WHERE server_id = ? AND user_id = ?');
        $stmt->execute([$serverId, $userId]);
    }

    /** Newest first, joined with the banned user's + banning moderator's usernames for display. */
    public static function listForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT sb.*, u.username, u.avatar_url, moderator.username AS banned_by_username
             FROM server_bans sb
             JOIN users u ON u.id = sb.user_id
             JOIN users moderator ON moderator.id = sb.banned_by
             WHERE sb.server_id = ?
             ORDER BY sb.banned_at DESC'
        );
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }
}
