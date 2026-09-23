<?php
require_once __DIR__ . '/../Database.php';

class RoleModel
{
    public static function listForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM roles WHERE server_id = ? ORDER BY position DESC, id ASC');
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = ?');
        $stmt->execute([$id]);
        $role = $stmt->fetch();
        return $role ?: null;
    }

    public static function create(int $serverId, string $name, string $color, int $permissions): array
    {
        $pdo = Database::get();
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM roles WHERE server_id = ?');
        $posStmt->execute([$serverId]);
        $nextPos = (int) $posStmt->fetch()['next_pos'];

        $stmt = $pdo->prepare(
            'INSERT INTO roles (server_id, name, color, permissions, position, is_default, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())'
        );
        $stmt->execute([$serverId, $name, $color, $permissions, $nextPos]);
        $id = (int) $pdo->lastInsertId();
        return self::findById($id);
    }

    public static function update(int $roleId, string $name, string $color, int $permissions): void
    {
        $pdo = Database::get();
        $role = self::findById($roleId);
        if (!$role) return;
        // @everyone's name is fixed, but its color/permissions ARE meant to be
        // editable — that's how you configure the server's base permissions.
        $finalName = ((int) $role['is_default'] === 1) ? $role['name'] : $name;
        $stmt = $pdo->prepare('UPDATE roles SET name = ?, color = ?, permissions = ? WHERE id = ?');
        $stmt->execute([$finalName, $color, $permissions, $roleId]);
    }

    public static function delete(int $roleId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM roles WHERE id = ? AND is_default = 0');
        $stmt->execute([$roleId]);
    }

    public static function assignToMember(int $memberId, int $roleId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT IGNORE INTO member_roles (member_id, role_id) VALUES (?, ?)');
        $stmt->execute([$memberId, $roleId]);
    }

    public static function removeFromMember(int $memberId, int $roleId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM member_roles WHERE member_id = ? AND role_id = ?');
        $stmt->execute([$memberId, $roleId]);
    }

    public static function memberIdFor(int $serverId, int $userId): ?int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM server_members WHERE server_id = ? AND user_id = ?');
        $stmt->execute([$serverId, $userId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }
}
