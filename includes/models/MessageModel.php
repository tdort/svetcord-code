<?php
require_once __DIR__ . '/../Database.php';

class MessageModel
{
    /**
     * Fetch messages for a channel, newest last (chat order).
     * $afterId: only return messages with id > $afterId (used for polling).
     * $beforeId: only return messages with id < $beforeId (used for pagination / scroll-up).
     */
    public static function listForChannel(int $channelId, ?int $afterId = null, ?int $beforeId = null, int $limit = MESSAGES_PAGE_SIZE): array
    {
        $pdo = Database::get();

        if ($afterId !== null) {
            $stmt = $pdo->prepare(
                'SELECT m.*, u.username, u.avatar_url, u.site_badge, u.nitro_until, u.is_bot
                 FROM messages m JOIN users u ON u.id = m.user_id
                 WHERE m.channel_id = ? AND m.id > ?
                 ORDER BY m.id ASC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $channelId, PDO::PARAM_INT);
            $stmt->bindValue(2, $afterId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        if ($beforeId !== null) {
            $stmt = $pdo->prepare(
                'SELECT m.*, u.username, u.avatar_url, u.site_badge, u.nitro_until, u.is_bot
                 FROM messages m JOIN users u ON u.id = m.user_id
                 WHERE m.channel_id = ? AND m.id < ?
                 ORDER BY m.id DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $channelId, PDO::PARAM_INT);
            $stmt->bindValue(2, $beforeId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return array_reverse($stmt->fetchAll());
        }

        $stmt = $pdo->prepare(
            'SELECT m.*, u.username, u.avatar_url, u.site_badge, u.nitro_until, u.is_bot
             FROM messages m JOIN users u ON u.id = m.user_id
             WHERE m.channel_id = ?
             ORDER BY m.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $channelId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    public static function create(int $channelId, int $userId, string $content, ?string $attachmentUrl = null, ?string $attachmentName = null, ?string $attachmentType = null): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO messages (channel_id, user_id, content, attachment_url, attachment_name, attachment_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$channelId, $userId, $content, $attachmentUrl, $attachmentName, $attachmentType]);
        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'SELECT m.*, u.username, u.avatar_url, u.site_badge, u.nitro_until, u.is_bot
             FROM messages m JOIN users u ON u.id = m.user_id
             WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$id]);
        $message = $stmt->fetch();
        return $message ?: null;
    }

    public static function latestIdForChannel(int $channelId): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT MAX(id) AS max_id FROM messages WHERE channel_id = ?');
        $stmt->execute([$channelId]);
        return (int) ($stmt->fetch()['max_id'] ?? 0);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('DELETE FROM messages WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function edit(int $id, string $content): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE messages SET content = ?, edited_at = NOW() WHERE id = ?');
        $stmt->execute([$content, $id]);
    }

    /**
     * Attaches a `reactions` array to each message: [{emoji, count, reacted}],
     * grouped/counted in one query rather than one per message. $viewerId
     * decides `reacted` (did the current user react with this emoji).
     */
    public static function attachReactions(array $messages, int $viewerId): array
    {
        if (empty($messages)) {
            return $messages;
        }
        $ids = array_map(fn($m) => (int) $m['id'], $messages);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT message_id, emoji, COUNT(*) AS cnt, MAX(user_id = ?) AS reacted
             FROM message_reactions
             WHERE message_id IN ($placeholders)
             GROUP BY message_id, emoji
             ORDER BY MIN(id) ASC"
        );
        $stmt->execute(array_merge([$viewerId], $ids));

        $byMessage = [];
        foreach ($stmt->fetchAll() as $row) {
            $byMessage[(int) $row['message_id']][] = [
                'emoji' => $row['emoji'],
                'count' => (int) $row['cnt'],
                'reacted' => (bool) $row['reacted'],
            ];
        }

        foreach ($messages as &$m) {
            $m['reactions'] = $byMessage[(int) $m['id']] ?? [];
        }
        return $messages;
    }

    /** Toggles the current user's reaction on a message. Returns the new state. */
    public static function toggleReaction(int $messageId, int $userId, string $emoji): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT 1 FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
        $stmt->execute([$messageId, $userId, $emoji]);
        if ($stmt->fetch()) {
            $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?')
                ->execute([$messageId, $userId, $emoji]);
            return false; // now un-reacted
        }
        $pdo->prepare('INSERT INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$messageId, $userId, $emoji]);
        return true; // now reacted
    }
}
