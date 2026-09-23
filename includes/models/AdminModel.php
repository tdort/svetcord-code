<?php
require_once __DIR__ . '/../Database.php';

/**
 * Backing model for the site admin panel: user search/listing,
 * site-wide bans (with a reason + audit trail), and basic stats.
 */
class AdminModel
{
    /** High-level counts shown on the admin panel's overview. */
    public static function getStats(): array
    {
        $pdo = Database::get();
        $stats = [];
        $stats['users'] = (int) $pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
        $stats['banned'] = (int) $pdo->query('SELECT COUNT(*) c FROM users WHERE is_banned = 1')->fetch()['c'];
        try {
            $stats['warned'] = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) c FROM site_warnings WHERE expires_at > NOW()')->fetch()['c'];
        } catch (Throwable $e) {
            $stats['warned'] = 0; // migrations/004_warnings.sql not run yet
        }
        $stats['admins'] = (int) $pdo->query('SELECT COUNT(*) c FROM users WHERE is_admin = 1')->fetch()['c'];
        $stats['servers'] = (int) $pdo->query('SELECT COUNT(*) c FROM servers')->fetch()['c'];
        $stats['messages'] = (int) $pdo->query('SELECT COUNT(*) c FROM messages')->fetch()['c'];
        try {
            $stats['open_reports'] = (int) $pdo->query("SELECT COUNT(*) c FROM user_reports WHERE status = 'open'")->fetch()['c'];
        } catch (Throwable $e) {
            $stats['open_reports'] = 0; // migrations/015_reports.sql not run yet
        }
        return $stats;
    }

    /**
     * List/search users for the admin panel. Search matches username
     * or email. Ordered newest-first, capped at $limit rows.
     */
    public static function listUsers(string $search = '', int $limit = 50): array
    {
        $pdo = Database::get();
        if ($search !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, username, email, avatar_url, is_admin, is_banned, site_badge, created_at, last_seen
                 FROM users
                 WHERE username LIKE ? OR email LIKE ?
                 ORDER BY created_at DESC
                 LIMIT ?'
            );
            $like = '%' . $search . '%';
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, username, email, avatar_url, is_admin, is_banned, site_badge, created_at, last_seen
                 FROM users
                 ORDER BY created_at DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    /**
     * Ban a user site-wide with a required reason. Refuses to ban an
     * admin (demote them first) or the acting admin themself.
     */
    public static function banUser(int $targetId, string $reason, int $actingAdminId): array
    {
        if ($reason === '') {
            return ['success' => false, 'error' => 'A ban reason is required.'];
        }
        if ($targetId === $actingAdminId) {
            return ['success' => false, 'error' => "You can't ban your own account."];
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, is_admin, is_banned FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            return ['success' => false, 'error' => 'User not found.'];
        }
        if ((int) $target['is_admin'] === 1) {
            return ['success' => false, 'error' => 'Remove admin access before banning an admin.'];
        }
        if ((int) $target['is_banned'] === 1) {
            return ['success' => false, 'error' => 'That user is already banned.'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET is_banned = 1 WHERE id = ?')->execute([$targetId]);
            $pdo->prepare(
                'INSERT INTO site_bans (user_id, reason, banned_by, banned_at) VALUES (?, ?, ?, NOW())'
            )->execute([$targetId, $reason, $actingAdminId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to ban user.'];
        }

        return ['success' => true];
    }

    /** Lift a user's current site-wide ban. */
    public static function unban(int $targetId, int $actingAdminId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT is_banned FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            return ['success' => false, 'error' => 'User not found.'];
        }
        if ((int) $target['is_banned'] === 0) {
            return ['success' => false, 'error' => 'That user is not banned.'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET is_banned = 0 WHERE id = ?')->execute([$targetId]);
            $pdo->prepare(
                'UPDATE site_bans SET lifted_at = NOW(), lifted_by = ?
                 WHERE user_id = ? AND lifted_at IS NULL'
            )->execute([$actingAdminId, $targetId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to unban user.'];
        }

        return ['success' => true];
    }

    /**
     * Issue a site-wide warning to a user. Unlike a ban, this never
     * touches users.is_banned — it's purely a logged notice that
     * shows up on the user's own Account Standing screen and expires
     * automatically after $days days.
     */
    public static function warnUser(int $targetId, string $reason, int $actingAdminId, int $days = 90): array
    {
        if ($reason === '') {
            return ['success' => false, 'error' => 'A warning reason is required.'];
        }
        if ($targetId === $actingAdminId) {
            return ['success' => false, 'error' => "You can't warn your own account."];
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO site_warnings (user_id, reason, warned_by, warned_at, expires_at)
                 VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))'
            );
            $stmt->execute([$targetId, $reason, $actingAdminId, $days]);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => "Couldn't save the warning — has migrations/004_warnings.sql been run against this database yet?",
            ];
        }

        return ['success' => true];
    }

    /**
     * End an active warning early (management action from the admin
     * panel). Rather than deleting the row, this just moves its
     * expires_at to now so the audit trail is preserved and it moves
     * from "Active" to "Expired" immediately.
     */
    public static function revokeWarning(int $warningId, int $actingAdminId): array
    {
        $pdo = Database::get();
        try {
            $stmt = $pdo->prepare('SELECT id, expires_at FROM site_warnings WHERE id = ?');
            $stmt->execute([$warningId]);
            $warning = $stmt->fetch();
            if (!$warning) {
                return ['success' => false, 'error' => 'Warning not found.'];
            }
            if (strtotime($warning['expires_at']) <= time()) {
                return ['success' => false, 'error' => 'That warning has already expired.'];
            }
            $pdo->prepare('UPDATE site_warnings SET expires_at = NOW() WHERE id = ?')->execute([$warningId]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => "Couldn't revoke the warning."];
        }

        return ['success' => true];
    }

    /** Full warning history (active and expired) for the admin panel, newest first. */
    public static function listWarnings(int $limit = 100): array
    {
        $pdo = Database::get();
        try {
            $stmt = $pdo->prepare(
                'SELECT sw.id, sw.reason, sw.warned_at, sw.expires_at,
                        u.id AS user_id, u.username,
                        w.username AS warned_by_username
                 FROM site_warnings sw
                 JOIN users u ON u.id = sw.user_id
                 JOIN users w ON w.id = sw.warned_by
                 ORDER BY sw.warned_at DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return []; // migrations/004_warnings.sql not run yet
        }
    }

    /**
     * A single user's own warning history (used by the admin panel's
     * per-user "Manage Warnings" view).
     */
    public static function listWarningsForUser(int $userId, int $limit = 50): array
    {
        $pdo = Database::get();
        try {
            $stmt = $pdo->prepare(
                'SELECT sw.id, sw.reason, sw.warned_at, sw.expires_at,
                        w.username AS warned_by_username
                 FROM site_warnings sw
                 JOIN users w ON w.id = sw.warned_by
                 WHERE sw.user_id = ?
                 ORDER BY sw.warned_at DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return []; // migrations/004_warnings.sql not run yet
        }
    }

    /**
     * Assign a site-wide profile badge (Owner / Co-Owner / Staff / none).
     * This is completely separate from per-server roles — it's a single
     * global flag shown as a small icon next to the user's name
     * everywhere (chat, member lists, profile).
     */
    public static function setBadge(int $targetId, string $badge, int $actingAdminId): array
    {
        $valid = ['none', 'owner', 'co_owner', 'staff', 'trusted'];
        if (!in_array($badge, $valid, true)) {
            return ['success' => false, 'error' => 'Invalid badge.'];
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $pdo->prepare('UPDATE users SET site_badge = ? WHERE id = ?')->execute([$badge, $targetId]);
        return ['success' => true];
    }

    /**
     * List/search servers for the admin panel's Servers tab. Search
     * matches server name or owner username.
     */
    public static function listServers(string $search = '', int $limit = 50): array
    {
        $pdo = Database::get();
        $sql = 'SELECT s.id, s.name, s.icon_url, s.is_disabled, s.disabled_reason, s.disabled_at,
                       s.created_at,
                       o.id AS owner_id, o.username AS owner_username,
                       d.username AS disabled_by_username,
                       (SELECT COUNT(*) FROM server_members sm WHERE sm.server_id = s.id) AS member_count
                FROM servers s
                JOIN users o ON o.id = s.owner_id
                LEFT JOIN users d ON d.id = s.disabled_by';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE s.name LIKE ? OR o.username LIKE ?';
            $like = '%' . $search . '%';
            $params = [$like, $like];
        }
        $sql .= ' ORDER BY s.created_at DESC LIMIT ' . (int) $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Disable a server site-wide (a "server ban"). Members keep their
     * membership row, but every channel/message endpoint refuses to
     * serve the server, and the client shows a takeover screen with
     * the reason and a Leave Server button.
     */
    public static function disableServer(int $serverId, string $reason, int $actingAdminId): array
    {
        if ($reason === '') {
            return ['success' => false, 'error' => 'A reason is required.'];
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, is_disabled FROM servers WHERE id = ?');
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            return ['success' => false, 'error' => 'Server not found.'];
        }
        if ((int) $server['is_disabled'] === 1) {
            return ['success' => false, 'error' => 'That server is already disabled.'];
        }

        $stmt = $pdo->prepare(
            'UPDATE servers SET is_disabled = 1, disabled_reason = ?, disabled_at = NOW(), disabled_by = ?
             WHERE id = ?'
        );
        $stmt->execute([$reason, $actingAdminId, $serverId]);
        return ['success' => true];
    }

    /** Re-enable a previously disabled server. */
    public static function enableServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, is_disabled FROM servers WHERE id = ?');
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            return ['success' => false, 'error' => 'Server not found.'];
        }
        if ((int) $server['is_disabled'] === 0) {
            return ['success' => false, 'error' => 'That server is not disabled.'];
        }

        $stmt = $pdo->prepare(
            'UPDATE servers SET is_disabled = 0, disabled_reason = NULL, disabled_at = NULL, disabled_by = NULL
             WHERE id = ?'
        );
        $stmt->execute([$serverId]);
        return ['success' => true];
    }

    /** Full ban history (active and lifted), newest first. */
    public static function listBans(int $limit = 100): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT sb.id, sb.reason, sb.banned_at, sb.lifted_at,
                    u.id AS user_id, u.username,
                    b.username AS banned_by_username,
                    l.username AS lifted_by_username
             FROM site_bans sb
             JOIN users u ON u.id = sb.user_id
             JOIN users b ON b.id = sb.banned_by
             LEFT JOIN users l ON l.id = sb.lifted_by
             ORDER BY sb.banned_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
