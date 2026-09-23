<?php
require_once __DIR__ . '/../Database.php';

class CategoryModel
{
    public static function listForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT * FROM channel_categories WHERE server_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM channel_categories WHERE id = ?');
        $stmt->execute([$id]);
        $category = $stmt->fetch();
        return $category ?: null;
    }

    public static function create(int $serverId, string $name): array
    {
        $pdo = Database::get();
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM channel_categories WHERE server_id = ?');
        $posStmt->execute([$serverId]);
        $nextPos = (int) $posStmt->fetch()['next_pos'];

        $stmt = $pdo->prepare(
            'INSERT INTO channel_categories (server_id, name, position, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$serverId, $name, $nextPos]);
        return self::findById((int) $pdo->lastInsertId());
    }

    public static function rename(int $categoryId, string $name): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE channel_categories SET name = ? WHERE id = ?');
        $stmt->execute([$name, $categoryId]);
    }

    /** Swaps this category's position with its immediate neighbor in the given direction. */
    public static function move(int $categoryId, string $direction): void
    {
        $pdo = Database::get();
        $category = self::findById($categoryId);
        if (!$category) {
            return;
        }
        $cmp = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $stmt = $pdo->prepare(
            "SELECT * FROM channel_categories
             WHERE server_id = ? AND position {$cmp} ?
             ORDER BY position {$order} LIMIT 1"
        );
        $stmt->execute([$category['server_id'], $category['position']]);
        $neighbor = $stmt->fetch();
        if (!$neighbor) {
            return; // already at the edge
        }
        $pdo->prepare('UPDATE channel_categories SET position = ? WHERE id = ?')
            ->execute([$neighbor['position'], $categoryId]);
        $pdo->prepare('UPDATE channel_categories SET position = ? WHERE id = ?')
            ->execute([$category['position'], $neighbor['id']]);
    }

    /** Deleting a category never deletes its channels — they fall back to "uncategorized" (ON DELETE SET NULL). */
    public static function delete(int $categoryId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM channel_categories WHERE id = ?');
        $stmt->execute([$categoryId]);
    }

    public static function belongsToServer(int $categoryId, int $serverId): bool
    {
        $category = self::findById($categoryId);
        return $category !== null && (int) $category['server_id'] === $serverId;
    }
}
