<?php
require_once __DIR__ . '/../Database.php';

class DMModel
{
    /** Get (or lazily create) the conversation between two users. */
    public static function findOrCreateConversation(int $userA, int $userB): array
    {
        $one = min($userA, $userB);
        $two = max($userA, $userB);

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM dm_conversations WHERE user_one_id = ? AND user_two_id = ?');
        $stmt->execute([$one, $two]);
        $convo = $stmt->fetch();
        if ($convo) {
            return $convo;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dm_conversations (user_one_id, user_two_id, created_at) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$one, $two]);
        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM dm_conversations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** List all conversations for a user, with the other participant's info and last message. */
    public static function listForUser(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    CASE WHEN c.user_one_id = ? THEN c.user_two_id ELSE c.user_one_id END AS other_user_id
             FROM dm_conversations c
             WHERE c.user_one_id = ? OR c.user_two_id = ?
             ORDER BY c.id DESC'
        );
        $stmt->execute([$userId, $userId, $userId]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$convo) {
            $userStmt = $pdo->prepare('SELECT id, username, avatar_url, status, site_badge FROM users WHERE id = ?');
            $userStmt->execute([$convo['other_user_id']]);
            $convo['other_user'] = $userStmt->fetch();

            $lastStmt = $pdo->prepare(
                'SELECT content, created_at FROM dm_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1'
            );
            $lastStmt->execute([$convo['id']]);
            $convo['last_message'] = $lastStmt->fetch() ?: null;
        }

        return $conversations;
    }

    public static function isParticipant(int $conversationId, int $userId): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM dm_conversations WHERE id = ? AND (user_one_id = ? OR user_two_id = ?)'
        );
        $stmt->execute([$conversationId, $userId, $userId]);
        return (bool) $stmt->fetch();
    }

    public static function listMessages(int $conversationId, ?int $afterId = null, int $limit = MESSAGES_PAGE_SIZE): array
    {
        $pdo = Database::get();
        if ($afterId !== null) {
            $stmt = $pdo->prepare(
                'SELECT dm.*, u.username, u.avatar_url, u.site_badge
                 FROM dm_messages dm JOIN users u ON u.id = dm.sender_id
                 WHERE dm.conversation_id = ? AND dm.id > ?
                 ORDER BY dm.id ASC LIMIT ?'
            );
            $stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
            $stmt->bindValue(2, $afterId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare(
            'SELECT dm.*, u.username, u.avatar_url, u.site_badge
             FROM dm_messages dm JOIN users u ON u.id = dm.sender_id
             WHERE dm.conversation_id = ?
             ORDER BY dm.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    public static function sendMessage(int $conversationId, int $senderId, string $content, ?string $attachmentUrl = null, ?string $attachmentName = null, ?string $attachmentType = null): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO dm_messages (conversation_id, sender_id, content, attachment_url, attachment_name, attachment_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$conversationId, $senderId, $content, $attachmentUrl, $attachmentName, $attachmentType]);
        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'SELECT dm.*, u.username, u.avatar_url, u.site_badge
             FROM dm_messages dm JOIN users u ON u.id = dm.sender_id
             WHERE dm.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function latestMessageId(int $conversationId): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT MAX(id) AS max_id FROM dm_messages WHERE conversation_id = ?');
        $stmt->execute([$conversationId]);
        return (int) ($stmt->fetch()['max_id'] ?? 0);
    }
}
