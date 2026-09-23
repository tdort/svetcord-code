<?php
require_once __DIR__ . '/../Database.php';

class CustomEmojiModel
{
    public static function listForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM custom_emojis WHERE server_id = ? ORDER BY name ASC');
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }

    public static function findByServerAndName(int $serverId, string $name): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM custom_emojis WHERE server_id = ? AND name = ?');
        $stmt->execute([$serverId, $name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM custom_emojis WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(int $serverId, string $name, string $imageUrl, int $createdBy): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO custom_emojis (server_id, name, image_url, created_by, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$serverId, $name, $imageUrl, $createdBy]);
        $id = (int) $pdo->lastInsertId();
        return self::findById($id);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM custom_emojis WHERE id = ?')->execute([$id]);
    }

    /**
     * All custom emojis usable by a member — i.e. from every server they
     * belong to — keyed by lowercase name for fast :name: lookup while
     * rendering message content. If two servers both define the same
     * name, the first one found wins (arbitrary but deterministic).
     */
    public static function mapForUser(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT ce.name, ce.image_url
             FROM custom_emojis ce
             JOIN server_members sm ON sm.server_id = ce.server_id AND sm.user_id = ?
             ORDER BY ce.id ASC'
        );
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($map[$row['name']])) {
                $map[$row['name']] = $row['image_url'];
            }
        }
        return $map;
    }
}
