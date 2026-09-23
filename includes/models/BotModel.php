<?php
require_once __DIR__ . '/../Database.php';

class BotModel
{
    /** Creates a new bot: a real `users` row (is_bot=1) plus its owning `bots` row. Returns the token — only shown once. */
    public static function create(int $ownerId, string $name): array
    {
        $pdo = Database::get();
        $username = self::generateUniqueUsername($pdo, $name);
        $email = 'bot_' . bin2hex(random_bytes(6)) . '@bots.local';
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); // unusable — bots never log in with a password
        $token = 'bot_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, is_bot, status, created_at)
                 VALUES (?, ?, ?, 1, 'online', NOW())"
            )->execute([$username, $email, $passwordHash]);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO bots (user_id, owner_id, token_hash, created_at) VALUES (?, ?, ?, NOW())'
            )->execute([$userId, $ownerId, $tokenHash]);
            $botId = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['id' => $botId, 'user_id' => $userId, 'username' => $username, 'token' => $token];
    }

    private static function generateUniqueUsername(PDO $pdo, string $name): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9_]/i', '', $name));
        $base = substr($base !== '' ? $base : 'bot', 0, 24);
        $username = $base;
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $suffix = 0;
        while (true) {
            $stmt->execute([$username]);
            if (!$stmt->fetch()) {
                return $username;
            }
            $suffix++;
            $username = $base . $suffix;
        }
    }

    public static function listForOwner(int $ownerId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT b.id, b.user_id, b.created_at, u.username, u.avatar_url
             FROM bots b JOIN users u ON u.id = b.user_id
             WHERE b.owner_id = ? ORDER BY b.created_at ASC'
        );
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $botId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM bots WHERE id = ?');
        $stmt->execute([$botId]);
        $bot = $stmt->fetch();
        return $bot ?: null;
    }

    /** Issues a fresh token for a bot you own. Returns the raw token (shown once) or null if not found/not yours. */
    public static function regenerateToken(int $botId, int $ownerId): ?string
    {
        $bot = self::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $ownerId) {
            return null;
        }
        $token = 'bot_' . bin2hex(random_bytes(24));
        $pdo = Database::get();
        $pdo->prepare('UPDATE bots SET token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $botId]);
        return $token;
    }

    /** Deletes a bot you own — removes its user account entirely (cascades: memberships, messages, the bots row). */
    public static function delete(int $botId, int $ownerId): bool
    {
        $bot = self::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $ownerId) {
            return false;
        }
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$bot['user_id']]);
        return true;
    }

    /** Resolves a raw bot token (from an Authorization: Bot <token> header) to that bot's user_id. */
    public static function userIdForToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT user_id FROM bots WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    /** Public info for the "Add Bot" landing page — no ownership required to view, just to know what you'd be adding. */
    public static function publicInfo(int $botId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT b.id, b.user_id, u.username, u.avatar_url, owner.username AS owner_username
             FROM bots b
             JOIN users u ON u.id = b.user_id
             JOIN users owner ON owner.id = b.owner_id
             WHERE b.id = ?'
        );
        $stmt->execute([$botId]);
        $bot = $stmt->fetch();
        return $bot ?: null;
    }

    /** Servers the visiting user can add this bot to: they have MANAGE_SERVER there, and the bot isn't already a member. */
    public static function serversAddableBy(int $botId, int $viewerUserId): array
    {
        $bot = self::findById($botId);
        if (!$bot) {
            return [];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT s.* FROM servers s
             JOIN server_members sm ON sm.server_id = s.id
             WHERE sm.user_id = ?
               AND s.id NOT IN (SELECT server_id FROM server_members WHERE user_id = ?)
             ORDER BY s.name ASC'
        );
        $stmt->execute([$viewerUserId, $bot['user_id']]);
        $candidates = $stmt->fetchAll();

        require_once __DIR__ . '/../Permissions.php';
        return array_values(array_filter($candidates, function ($server) use ($pdo, $viewerUserId) {
            $perms = Permissions::effectiveFor($pdo, (int) $server['id'], $viewerUserId);
            return Permissions::has($perms, Permissions::MANAGE_SERVER);
        }));
    }

    /** Adds the bot to a server. Caller must have already checked the viewer has MANAGE_SERVER there. */
    public static function addToServer(int $botId, int $serverId): array
    {
        $bot = self::findById($botId);
        if (!$bot) {
            return ['success' => false, 'error' => 'Bot not found.'];
        }
        require_once __DIR__ . '/ServerModel.php';
        return ServerModel::join($serverId, (int) $bot['user_id']);
    }

    /** Servers this bot is currently in — shown in the Developer Portal so its owner can manage/remove it. */
    public static function serversForBot(int $botId): array
    {
        $bot = self::findById($botId);
        if (!$bot) {
            return [];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT s.* FROM servers s
             JOIN server_members sm ON sm.server_id = s.id
             WHERE sm.user_id = ?
             ORDER BY s.name ASC'
        );
        $stmt->execute([$bot['user_id']]);
        return $stmt->fetchAll();
    }

    /** Removes the bot from a server (kicks it). */
    public static function removeFromServer(int $botId, int $serverId): void
    {
        $bot = self::findById($botId);
        if (!$bot) {
            return;
        }
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM server_members WHERE server_id = ? AND user_id = ?')
            ->execute([$serverId, $bot['user_id']]);
    }
}
