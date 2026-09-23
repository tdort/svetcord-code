<?php
require_once __DIR__ . '/../Database.php';

class ReportModel
{
    public static function create(int $reporterId, int $reportedUserId, string $reason, ?int $messageId = null): array
    {
        if ($reporterId === $reportedUserId) {
            return ['success' => false, 'error' => "You can't report yourself."];
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$reportedUserId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $snapshot = null;
        if ($messageId !== null) {
            $stmt = $pdo->prepare('SELECT content FROM messages WHERE id = ?');
            $stmt->execute([$messageId]);
            $row = $stmt->fetch();
            $snapshot = $row ? $row['content'] : null; // message may already be gone — that's fine, reason still stands alone
        }

        $stmt = $pdo->prepare(
            'INSERT INTO user_reports (reporter_id, reported_user_id, message_id, message_snapshot, reason, status, created_at)
             VALUES (?, ?, ?, ?, ?, "open", NOW())'
        );
        $stmt->execute([$reporterId, $reportedUserId, $messageId, $snapshot, $reason]);
        return ['success' => true];
    }

    /** All reports for the admin panel, newest-first. $status null = all. */
    public static function listAll(?string $status = null): array
    {
        $pdo = Database::get();
        $sql = 'SELECT r.*, reporter.username AS reporter_username,
                       reported.username AS reported_username, reported.avatar_url AS reported_avatar_url,
                       resolver.username AS resolved_by_username
                FROM user_reports r
                JOIN users reporter ON reporter.id = r.reporter_id
                JOIN users reported ON reported.id = r.reported_user_id
                LEFT JOIN users resolver ON resolver.id = r.resolved_by';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.created_at DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countOpen(): int
    {
        $pdo = Database::get();
        return (int) $pdo->query("SELECT COUNT(*) c FROM user_reports WHERE status = 'open'")->fetch()['c'];
    }

    public static function resolve(int $reportId, int $adminId, string $status): array
    {
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            return ['success' => false, 'error' => 'Invalid status.'];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'UPDATE user_reports SET status = ?, resolved_at = NOW(), resolved_by = ? WHERE id = ?'
        );
        $stmt->execute([$status, $adminId, $reportId]);
        return ['success' => true];
    }
}
