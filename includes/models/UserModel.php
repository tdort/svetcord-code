<?php
require_once __DIR__ . '/../Database.php';

class UserModel
{
    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, username, email, avatar_url, status, bio, pronouns, accent_color, banner_url, site_badge, points, nitro_until, created_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /** Public profile fields — safe to show to any other user. */
    public static function findPublicById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, username, avatar_url, status, bio, pronouns, accent_color, banner_url, site_badge, nitro_until, is_bot, created_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, username, avatar_url, status, site_badge FROM users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function searchByUsername(string $query, int $limit = 10): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, username, avatar_url, status, site_badge FROM users WHERE username LIKE ? LIMIT ?'
        );
        $stmt->bindValue(1, '%' . $query . '%');
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function updateAvatar(int $userId, string $url): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ?');
        $stmt->execute([$url, $userId]);
    }

    /** Nitro perk: set (or clear, with $url = null) a custom profile banner image. */
    public static function updateBanner(int $userId, ?string $url): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE users SET banner_url = ? WHERE id = ?');
        $stmt->execute([$url, $userId]);
    }

    /** Update the "About Me" personalization fields for a user. */
    public static function updateProfile(int $userId, string $bio, string $pronouns, string $accentColor): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'UPDATE users SET bio = ?, pronouns = ?, accent_color = ? WHERE id = ?'
        );
        $stmt->execute([
            $bio === '' ? null : $bio,
            $pronouns === '' ? null : $pronouns,
            $accentColor,
            $userId,
        ]);
    }
}
