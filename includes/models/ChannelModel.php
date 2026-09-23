<?php
require_once __DIR__ . '/../Database.php';

class ChannelModel
{
    public static function listForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM channels WHERE server_id = ? ORDER BY category_id IS NULL DESC, category_id ASC, type ASC, position ASC, id ASC'
        );
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM channels WHERE id = ?');
        $stmt->execute([$id]);
        $channel = $stmt->fetch();
        return $channel ?: null;
    }

    public static function create(int $serverId, string $name, string $type, ?string $topic = null, ?int $categoryId = null): array
    {
        $pdo = Database::get();
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM channels WHERE server_id = ? AND type = ?');
        $posStmt->execute([$serverId, $type]);
        $nextPos = (int) $posStmt->fetch()['next_pos'];

        $stmt = $pdo->prepare(
            'INSERT INTO channels (server_id, category_id, name, type, topic, position, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$serverId, $categoryId, $name, $type, $topic, $nextPos]);
        $id = (int) $pdo->lastInsertId();
        return self::findById($id);
    }

    public static function delete(int $channelId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM channels WHERE id = ?');
        $stmt->execute([$channelId]);
    }

    /** Rename/re-topic/move a channel. Pass null for category_id to leave it unchanged; use 0 to explicitly uncategorize. */
    public static function update(int $channelId, ?string $name, ?string $topic, ?int $categoryId, bool $moveToUncategorized): array
    {
        $pdo = Database::get();
        $sets = [];
        $params = [];
        if ($name !== null) {
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($topic !== null) {
            $sets[] = 'topic = ?';
            $params[] = $topic;
        }
        if ($moveToUncategorized) {
            $sets[] = 'category_id = NULL';
        } elseif ($categoryId !== null) {
            $sets[] = 'category_id = ?';
            $params[] = $categoryId;
        }
        if (empty($sets)) {
            return self::findById($channelId);
        }
        $params[] = $channelId;
        $stmt = $pdo->prepare('UPDATE channels SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return self::findById($channelId);
    }

    public static function belongsToServer(int $channelId, int $serverId): bool
    {
        $channel = self::findById($channelId);
        return $channel !== null && (int) $channel['server_id'] === $serverId;
    }
}
