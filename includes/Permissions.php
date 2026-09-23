<?php
require_once __DIR__ . '/Database.php';

/**
 * Discord-style permission bitmask.
 * Each flag is one bit; a role's `permissions` column is the OR
 * of every flag it grants. A member's effective permissions are
 * the OR of every role they hold.
 */
class Permissions
{
    const VIEW_CHANNELS   = 1 << 0;
    const SEND_MESSAGES   = 1 << 1;
    const MANAGE_MESSAGES = 1 << 2; // delete/pin others' messages
    const MANAGE_CHANNELS = 1 << 3;
    const MANAGE_ROLES    = 1 << 4;
    const KICK_MEMBERS    = 1 << 5;
    const BAN_MEMBERS     = 1 << 6;
    const MANAGE_SERVER   = 1 << 7; // rename server, change icon
    const ADMINISTRATOR   = 1 << 8; // bypasses all checks

    const ALL_FLAGS = [
        'VIEW_CHANNELS'   => self::VIEW_CHANNELS,
        'SEND_MESSAGES'   => self::SEND_MESSAGES,
        'MANAGE_MESSAGES' => self::MANAGE_MESSAGES,
        'MANAGE_CHANNELS' => self::MANAGE_CHANNELS,
        'MANAGE_ROLES'    => self::MANAGE_ROLES,
        'KICK_MEMBERS'    => self::KICK_MEMBERS,
        'BAN_MEMBERS'     => self::BAN_MEMBERS,
        'MANAGE_SERVER'   => self::MANAGE_SERVER,
        'ADMINISTRATOR'   => self::ADMINISTRATOR,
    ];

    /**
     * Flags that make sense as a per-channel override. The rest
     * (roles/members/server-level stuff) are server-wide only.
     */
    const CHANNEL_OVERRIDABLE_FLAGS = [
        'VIEW_CHANNELS'   => self::VIEW_CHANNELS,
        'SEND_MESSAGES'   => self::SEND_MESSAGES,
        'MANAGE_MESSAGES' => self::MANAGE_MESSAGES,
        'MANAGE_CHANNELS' => self::MANAGE_CHANNELS,
    ];

    /** Sensible defaults granted to the @everyone role on server creation. */
    public static function defaultEveryoneFlags(): int
    {
        return self::VIEW_CHANNELS | self::SEND_MESSAGES;
    }

    /** Full permissions, granted to the auto-created "Owner" role. */
    public static function allFlags(): int
    {
        $sum = 0;
        foreach (self::ALL_FLAGS as $flag) {
            $sum |= $flag;
        }
        return $sum;
    }

    public static function has(int $userPermissions, int $flag): bool
    {
        if ($userPermissions & self::ADMINISTRATOR) {
            return true;
        }
        return ($userPermissions & $flag) === $flag;
    }

    /**
     * Compute a member's effective permission bitmask for a server:
     * OR of all their roles' permissions. Server owner always gets
     * full ADMINISTRATOR regardless of roles.
     */
    public static function effectiveFor(PDO $pdo, int $serverId, int $userId): int
    {
        $serverStmt = $pdo->prepare('SELECT owner_id FROM servers WHERE id = ?');
        $serverStmt->execute([$serverId]);
        $server = $serverStmt->fetch();
        if (!$server) {
            return 0;
        }
        if ((int) $server['owner_id'] === $userId) {
            return self::allFlags();
        }

        $stmt = $pdo->prepare(
            'SELECT COALESCE(BIT_OR(r.permissions), 0) AS mask
             FROM server_members sm
             JOIN member_roles mr ON mr.member_id = sm.id
             JOIN roles r ON r.id = mr.role_id
             WHERE sm.server_id = ? AND sm.user_id = ?'
        );
        $stmt->execute([$serverId, $userId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['mask'] : 0;
    }

    /** True if the user is a member of the given server at all. */
    public static function isMember(PDO $pdo, int $serverId, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM server_members WHERE server_id = ? AND user_id = ?');
        $stmt->execute([$serverId, $userId]);
        return (bool) $stmt->fetch();
    }

    /** The role ids a member holds in a server (empty array if not a member / no roles). */
    public static function roleIdsFor(PDO $pdo, int $serverId, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT mr.role_id
             FROM server_members sm
             JOIN member_roles mr ON mr.member_id = sm.id
             WHERE sm.server_id = ? AND sm.user_id = ?'
        );
        $stmt->execute([$serverId, $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * A member's effective permissions for one specific channel: their
     * server-wide role permissions, with that channel's role overrides
     * layered on top (deny removes a flag, allow grants it — allow wins
     * if the user holds multiple roles that disagree on a flag). The
     * server owner and anyone with ADMINISTRATOR always bypass overrides,
     * same as Discord.
     */
    public static function effectiveForChannel(PDO $pdo, int $channelId, int $serverId, int $userId): int
    {
        $base = self::effectiveFor($pdo, $serverId, $userId);
        if ($base & self::ADMINISTRATOR) {
            return $base;
        }

        $roleIds = self::roleIdsFor($pdo, $serverId, $userId);
        if (empty($roleIds)) {
            return $base;
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT COALESCE(BIT_OR(allow), 0) AS allow_mask, COALESCE(BIT_OR(deny), 0) AS deny_mask
             FROM channel_permission_overrides
             WHERE channel_id = ? AND role_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$channelId], $roleIds));
        $row = $stmt->fetch();
        if (!$row) {
            return $base;
        }

        $allow = (int) $row['allow_mask'];
        $deny = (int) $row['deny_mask'];
        return ($base & ~$deny) | $allow;
    }

    public static function isOwner(PDO $pdo, int $serverId, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM servers WHERE id = ? AND owner_id = ?');
        $stmt->execute([$serverId, $userId]);
        return (bool) $stmt->fetch();
    }
}
