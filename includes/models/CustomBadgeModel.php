<?php
require_once __DIR__ . '/../Database.php';

class CustomBadgeModel
{
    public static function listAll(): array
    {
        $pdo = Database::get();
        return $pdo->query('SELECT * FROM custom_badges ORDER BY created_at ASC')->fetchAll();
    }

    public static function findById(int $badgeId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM custom_badges WHERE id = ?');
        $stmt->execute([$badgeId]);
        $badge = $stmt->fetch();
        return $badge ?: null;
    }

    public static function create(string $name, string $iconUrl): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO custom_badges (name, icon_url, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$name, $iconUrl]);
        return self::findById((int) $pdo->lastInsertId());
    }

    public static function delete(int $badgeId): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM custom_badges WHERE id = ?')->execute([$badgeId]);
    }

    public static function assign(int $userId, int $badgeId): void
    {
        $pdo = Database::get();
        $pdo->prepare('INSERT IGNORE INTO user_custom_badges (user_id, badge_id, assigned_at) VALUES (?, ?, NOW())')
            ->execute([$userId, $badgeId]);
    }

    public static function unassign(int $userId, int $badgeId): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM user_custom_badges WHERE user_id = ? AND badge_id = ?')
            ->execute([$userId, $badgeId]);
    }

    /** Badges a user currently holds, in assignment order. */
    public static function listForUser(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT cb.id, cb.name, cb.icon_url
             FROM user_custom_badges ucb
             JOIN custom_badges cb ON cb.id = ucb.badge_id
             WHERE ucb.user_id = ?
             ORDER BY ucb.assigned_at ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Badge ids a user holds — cheap lookup for the admin assign-badges UI (checkbox state). */
    public static function badgeIdsForUser(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT badge_id FROM user_custom_badges WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
