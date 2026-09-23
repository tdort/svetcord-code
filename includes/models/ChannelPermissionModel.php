<?php
require_once __DIR__ . '/../Database.php';

class ChannelPermissionModel
{
    /** All overrides for a channel, joined with role name/color for display. */
    public static function listForChannel(int $channelId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT cpo.role_id, cpo.allow, cpo.deny, r.name AS role_name, r.color AS role_color
             FROM channel_permission_overrides cpo
             JOIN roles r ON r.id = cpo.role_id
             WHERE cpo.channel_id = ?
             ORDER BY r.position DESC, r.id ASC'
        );
        $stmt->execute([$channelId]);
        return $stmt->fetchAll();
    }

    /**
     * Set (or clear) one role's override for a channel. Only bits in
     * Permissions::CHANNEL_OVERRIDABLE_FLAGS are meaningful; a flag with
     * neither allow nor deny set just inherits the role's normal
     * server-wide permission. Passing allow=0 and deny=0 removes the row
     * entirely (back to "no override").
     */
    public static function setOverride(int $channelId, int $roleId, int $allow, int $deny): void
    {
        $pdo = Database::get();
        if ($allow === 0 && $deny === 0) {
            $stmt = $pdo->prepare('DELETE FROM channel_permission_overrides WHERE channel_id = ? AND role_id = ?');
            $stmt->execute([$channelId, $roleId]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO channel_permission_overrides (channel_id, role_id, allow, deny)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE allow = VALUES(allow), deny = VALUES(deny)'
        );
        $stmt->execute([$channelId, $roleId, $allow, $deny]);
    }
}
