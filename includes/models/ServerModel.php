<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Permissions.php';
require_once __DIR__ . '/../helpers.php';

class ServerModel
{
    /**
     * Create a new server, auto-create an @everyone default role,
     * a "general" text channel and a "General" voice channel, and
     * add the creator as a member.
     */
    public static function create(int $ownerId, string $name, ?string $iconUrl = null): array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();

        try {
            $inviteCode = generate_invite_code();
            $stmt = $pdo->prepare(
                'INSERT INTO servers (name, owner_id, icon_url, invite_code, created_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$name, $ownerId, $iconUrl, $inviteCode]);
            $serverId = (int) $pdo->lastInsertId();

            // Default @everyone role
            $stmt = $pdo->prepare(
                'INSERT INTO roles (server_id, name, color, permissions, position, is_default, created_at)
                 VALUES (?, "@everyone", "#99AAB5", ?, 0, 1, NOW())'
            );
            $stmt->execute([$serverId, Permissions::defaultEveryoneFlags()]);
            $everyoneRoleId = (int) $pdo->lastInsertId();

            // Add owner as member and assign default role
            $stmt = $pdo->prepare(
                'INSERT INTO server_members (server_id, user_id, joined_at) VALUES (?, ?, NOW())'
            );
            $stmt->execute([$serverId, $ownerId]);
            $memberId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO member_roles (member_id, role_id) VALUES (?, ?)');
            $stmt->execute([$memberId, $everyoneRoleId]);

            // Default channels
            $stmt = $pdo->prepare(
                'INSERT INTO channels (server_id, name, type, position, created_at) VALUES (?, "general", "text", 0, NOW())'
            );
            $stmt->execute([$serverId]);

            $stmt = $pdo->prepare(
                'INSERT INTO channels (server_id, name, type, position, created_at) VALUES (?, "General", "voice", 1, NOW())'
            );
            $stmt->execute([$serverId]);

            $pdo->commit();
            return self::findById($serverId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        return $server ?: null;
    }

    public static function findByInviteCode(string $code): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM servers WHERE invite_code = ?');
        $stmt->execute([$code]);
        $server = $stmt->fetch();
        return $server ?: null;
    }

    /**
     * Resolves ANY invite code — the server's original single invite_code,
     * or one of its (possibly limited-use/expiring) server_invites rows.
     * Returns ['server' => array, 'invite' => array|null] or null if the
     * code doesn't match anything usable right now.
     */
    public static function resolveInviteCode(string $code): ?array
    {
        $server = self::findByInviteCode($code);
        if ($server) {
            return ['server' => $server, 'invite' => null];
        }

        require_once __DIR__ . '/ServerInviteModel.php';
        $invite = ServerInviteModel::findByCode($code);
        if ($invite && ServerInviteModel::isValid($invite)) {
            $server = self::findById((int) $invite['server_id']);
            if ($server) {
                return ['server' => $server, 'invite' => $invite];
            }
        }
        return null;
    }

    /** All servers a user belongs to. */
    public static function listForUser(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT s.* FROM servers s
             JOIN server_members sm ON sm.server_id = s.id
             WHERE sm.user_id = ?
             ORDER BY sm.joined_at ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function join(int $serverId, int $userId): array
    {
        $pdo = Database::get();

        if (Permissions::isMember($pdo, $serverId, $userId)) {
            return ['success' => false, 'error' => 'You are already a member of this server.'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO server_members (server_id, user_id, joined_at) VALUES (?, ?, NOW())'
            );
            $stmt->execute([$serverId, $userId]);
            $memberId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('SELECT id FROM roles WHERE server_id = ? AND is_default = 1 LIMIT 1');
            $stmt->execute([$serverId]);
            $role = $stmt->fetch();
            if ($role) {
                $stmt = $pdo->prepare('INSERT INTO member_roles (member_id, role_id) VALUES (?, ?)');
                $stmt->execute([$memberId, $role['id']]);
            }

            $pdo->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function listMembers(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT sm.id AS member_id, sm.nickname, sm.joined_at,
                    u.id AS user_id, u.username, u.avatar_url, u.status, u.site_badge, u.nitro_until, u.is_bot
             FROM server_members sm
             JOIN users u ON u.id = sm.user_id
             WHERE sm.server_id = ?
             ORDER BY u.username ASC'
        );
        $stmt->execute([$serverId]);
        $members = $stmt->fetchAll();

        foreach ($members as &$member) {
            $roleStmt = $pdo->prepare(
                'SELECT r.id, r.name, r.color, r.position
                 FROM roles r
                 JOIN member_roles mr ON mr.role_id = r.id
                 WHERE mr.member_id = ?
                 ORDER BY r.position DESC'
            );
            $roleStmt->execute([$member['member_id']]);
            $member['roles'] = $roleStmt->fetchAll();
        }

        return $members;
    }

    public static function removeMember(int $serverId, int $userId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM server_members WHERE server_id = ? AND user_id = ?');
        $stmt->execute([$serverId, $userId]);
    }

    public static function updateIcon(int $serverId, string $url): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE servers SET icon_url = ? WHERE id = ?');
        $stmt->execute([$url, $serverId]);
    }

    public static function rename(int $serverId, string $name): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE servers SET name = ? WHERE id = ?');
        $stmt->execute([$name, $serverId]);
    }

    public static function regenerateInvite(int $serverId): string
    {
        $pdo = Database::get();
        $code = generate_invite_code();
        $stmt = $pdo->prepare('UPDATE servers SET invite_code = ? WHERE id = ?');
        $stmt->execute([$code, $serverId]);
        return $code;
    }

    public static function delete(int $serverId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM servers WHERE id = ?');
        $stmt->execute([$serverId]);
    }

    public static function memberCount(int $serverId): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM server_members WHERE server_id = ?');
        $stmt->execute([$serverId]);
        return (int) $stmt->fetchColumn();
    }
}
