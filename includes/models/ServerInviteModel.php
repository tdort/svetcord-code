<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../helpers.php';

class ServerInviteModel
{
    public static function create(int $serverId, int $createdBy, ?int $maxUses, ?int $expiresInMinutes): array
    {
        $pdo = Database::get();
        $code = generate_invite_code(10);
        $expiresAt = $expiresInMinutes !== null ? gmdate('Y-m-d H:i:s', time() + $expiresInMinutes * 60) : null;

        $stmt = $pdo->prepare(
            'INSERT INTO server_invites (server_id, code, created_by, max_uses, uses, expires_at, created_at)
             VALUES (?, ?, ?, ?, 0, ?, NOW())'
        );
        $stmt->execute([$serverId, $code, $createdBy, $maxUses, $expiresAt]);
        return self::findById((int) $pdo->lastInsertId());
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM server_invites WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM server_invites WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** True if this invite can still be used right now (not expired, not used up). */
    public static function isValid(array $invite): bool
    {
        if ($invite['expires_at'] !== null && strtotime($invite['expires_at'] . ' UTC') < time()) {
            return false;
        }
        if ($invite['max_uses'] !== null && (int) $invite['uses'] >= (int) $invite['max_uses']) {
            return false;
        }
        return true;
    }

    public static function consume(int $id): void
    {
        $pdo = Database::get();
        $pdo->prepare('UPDATE server_invites SET uses = uses + 1 WHERE id = ?')->execute([$id]);
    }

    public static function revoke(int $id): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM server_invites WHERE id = ?')->execute([$id]);
    }

    /** All invites for a server (including expired/used-up ones, so the management UI can show their state), newest first. */
    public static function listForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT si.*, u.username AS created_by_username
             FROM server_invites si
             JOIN users u ON u.id = si.created_by
             WHERE si.server_id = ?
             ORDER BY si.created_at DESC'
        );
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }
}
