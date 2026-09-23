<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/UserModel.php';
require_once __DIR__ . '/../includes/models/ServerModel.php';
require_once __DIR__ . '/../includes/models/RoleModel.php';
require_once __DIR__ . '/../includes/models/FriendModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

switch ($action) {
    /**
     * Public profile card for a user. If server_id is provided (and the
     * requester is a member of that server), also includes the target's
     * nickname, joined_at, and roles within that server.
     */
    case 'profile': {
        $targetUserId = (int) ($_GET['user_id'] ?? 0);
        $profile = UserModel::findPublicById($targetUserId);
        if (!$profile) {
            json_error('User not found.', 404);
        }

        $profile['friend'] = FriendModel::getRelationship((int) $user['id'], $targetUserId);

        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        $profile['custom_badges'] = CustomBadgeModel::listForUser($targetUserId);

        if ($targetUserId !== (int) $user['id']) {
            $profile['mutual_friends'] = FriendModel::mutualFriendsCount((int) $user['id'], $targetUserId);
        }

        $serverId = (int) ($_GET['server_id'] ?? 0);
        if ($serverId > 0 && Permissions::isMember($pdo, $serverId, (int) $user['id'])) {
            $stmt = $pdo->prepare(
                'SELECT id AS member_id, nickname, joined_at FROM server_members
                 WHERE server_id = ? AND user_id = ?'
            );
            $stmt->execute([$serverId, $targetUserId]);
            $membership = $stmt->fetch();

            if ($membership) {
                $profile['nickname'] = $membership['nickname'];
                $profile['joined_at'] = $membership['joined_at'];
                $profile['is_owner'] = Permissions::isOwner($pdo, $serverId, $targetUserId);

                $roleStmt = $pdo->prepare(
                    'SELECT r.id, r.name, r.color FROM roles r
                     JOIN member_roles mr ON mr.role_id = r.id
                     WHERE mr.member_id = ? ORDER BY r.position DESC'
                );
                $roleStmt->execute([$membership['member_id']]);
                $profile['roles'] = $roleStmt->fetchAll();

                $canManageRoles = Permissions::has(
                    Permissions::effectiveFor($pdo, $serverId, (int) $user['id']),
                    Permissions::MANAGE_ROLES
                );
                if ($canManageRoles) {
                    $profile['all_roles'] = RoleModel::listForServer($serverId);
                }
            }
        }

        if ($targetUserId !== (int) $user['id']) {
            $stmt = $pdo->prepare(
                'SELECT s.name FROM servers s
                 JOIN server_members mine ON mine.server_id = s.id AND mine.user_id = ?
                 JOIN server_members theirs ON theirs.server_id = s.id AND theirs.user_id = ?
                 ORDER BY s.name ASC'
            );
            $stmt->execute([(int) $user['id'], $targetUserId]);
            $profile['mutual_servers'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        json_ok(['profile' => $profile]);
        break;
    }

    default:
        json_error('Unknown action', 404);
}
