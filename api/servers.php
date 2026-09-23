<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/ServerModel.php';
require_once __DIR__ . '/../includes/models/ServerBanModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': {
        $servers = ServerModel::listForUser((int) $user['id']);
        json_ok(['servers' => $servers]);
        break;
    }

    case 'create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $name = sanitize_text($body['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            json_error('Server name must be 1-100 characters.');
        }
        $server = ServerModel::create((int) $user['id'], $name);
        json_ok(['server' => $server]);
        break;
    }

    case 'get': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        $server = ServerModel::findById($serverId);
        if (!$server || !Permissions::isMember($pdo, $serverId, (int) $user['id'])) {
            json_error('Server not found.', 404);
        }
        $server['my_permissions'] = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        json_ok(['server' => $server]);
        break;
    }

    case 'join': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $code = sanitize_text($body['invite_code'] ?? '');
        $resolved = ServerModel::resolveInviteCode($code);
        if (!$resolved) {
            json_error('Invalid or expired invite code.', 404);
        }
        $server = $resolved['server'];
        if (ServerBanModel::isBanned((int) $server['id'], (int) $user['id'])) {
            json_error('You are banned from this server.', 403);
        }
        $result = ServerModel::join((int) $server['id'], (int) $user['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        if ($resolved['invite'] !== null) {
            require_once __DIR__ . '/../includes/models/ServerInviteModel.php';
            ServerInviteModel::consume((int) $resolved['invite']['id']);
        }
        json_ok(['server' => ServerModel::findById((int) $server['id'])]);
        break;
    }

    /** Preview a server before joining — used by the /invite/<code> landing page. No membership required. */
    case 'invite_preview': {
        $code = sanitize_text($_GET['code'] ?? '');
        $resolved = ServerModel::resolveInviteCode($code);
        if (!$resolved) {
            json_error('Invalid or expired invite code.', 404);
        }
        $server = $resolved['server'];
        json_ok([
            'server' => [
                'id' => (int) $server['id'],
                'name' => $server['name'],
                'icon_url' => $server['icon_url'],
            ],
            'member_count' => ServerModel::memberCount((int) $server['id']),
            'already_member' => Permissions::isMember($pdo, (int) $server['id'], (int) $user['id']),
        ]);
        break;
    }

    case 'leave': {
        require_method('POST');
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $server = ServerModel::findById($serverId);
        if (!$server) {
            json_error('Server not found.', 404);
        }
        if ((int) $server['owner_id'] === (int) $user['id'] && (int) $server['is_disabled'] !== 1) {
            json_error('Server owners cannot leave; delete the server instead.');
        }
        ServerModel::removeMember($serverId, (int) $user['id']);
        json_ok();
        break;
    }

    case 'delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $server = ServerModel::findById($serverId);
        if (!$server) {
            json_error('Server not found.', 404);
        }
        if ((int) $server['owner_id'] !== (int) $user['id']) {
            json_error('Only the server owner can delete this server.', 403);
        }
        ServerModel::delete($serverId);
        json_ok();
        break;
    }

    case 'members': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        if (!Permissions::isMember($pdo, $serverId, (int) $user['id'])) {
            json_error('You are not a member of this server.', 403);
        }
        json_ok(['members' => ServerModel::listMembers($serverId)]);
        break;
    }

    case 'kick': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $targetUserId = (int) ($body['user_id'] ?? 0);

        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::KICK_MEMBERS)) {
            json_error('You do not have permission to kick members.', 403);
        }
        if (Permissions::isOwner($pdo, $serverId, $targetUserId)) {
            json_error('You cannot kick the server owner.');
        }
        ServerModel::removeMember($serverId, $targetUserId);
        json_ok();
        break;
    }

    case 'rename': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $name = sanitize_text($body['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            json_error('Server name must be 1-100 characters.');
        }
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to rename this server.', 403);
        }
        ServerModel::rename($serverId, $name);
        json_ok();
        break;
    }

    case 'regenerate_invite': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to do that.', 403);
        }
        $code = ServerModel::regenerateInvite($serverId);
        json_ok(['invite_code' => $code]);
        break;
    }

    case 'ban': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $targetUserId = (int) ($body['user_id'] ?? 0);
        $reason = isset($body['reason']) ? sanitize_text($body['reason']) : null;

        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::BAN_MEMBERS)) {
            json_error('You do not have permission to ban members.', 403);
        }
        if (Permissions::isOwner($pdo, $serverId, $targetUserId)) {
            json_error('You cannot ban the server owner.');
        }
        ServerBanModel::ban($serverId, $targetUserId, (int) $user['id'], $reason);
        ServerModel::removeMember($serverId, $targetUserId);
        json_ok();
        break;
    }

    case 'unban': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $targetUserId = (int) ($body['user_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::BAN_MEMBERS)) {
            json_error('You do not have permission to manage bans.', 403);
        }
        ServerBanModel::unban($serverId, $targetUserId);
        json_ok();
        break;
    }

    case 'bans': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::BAN_MEMBERS)) {
            json_error('You do not have permission to view bans.', 403);
        }
        json_ok(['bans' => ServerBanModel::listForServer($serverId)]);
        break;
    }

    /** ---------------- Multi-invite management ---------------- */

    case 'invites_list': {
        require_once __DIR__ . '/../includes/models/ServerInviteModel.php';
        $serverId = (int) ($_GET['server_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to manage invites.', 403);
        }
        json_ok(['invites' => ServerInviteModel::listForServer($serverId)]);
        break;
    }

    case 'invite_create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        require_once __DIR__ . '/../includes/models/ServerInviteModel.php';
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to manage invites.', 403);
        }
        // Both null = a genuinely infinite invite: never expires, unlimited uses.
        $maxUses = !empty($body['max_uses']) ? max(1, (int) $body['max_uses']) : null;
        $expiresInMinutes = !empty($body['expires_in_minutes']) ? max(1, (int) $body['expires_in_minutes']) : null;
        $invite = ServerInviteModel::create($serverId, (int) $user['id'], $maxUses, $expiresInMinutes);
        json_ok(['invite' => $invite]);
        break;
    }

    case 'invite_revoke': {
        require_method('POST');
        Auth::requireNotBanned($user);
        require_once __DIR__ . '/../includes/models/ServerInviteModel.php';
        $body = request_body();
        $inviteId = (int) ($body['invite_id'] ?? 0);
        $invite = ServerInviteModel::findById($inviteId);
        if (!$invite) {
            json_error('Invite not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $invite['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to manage invites.', 403);
        }
        ServerInviteModel::revoke($inviteId);
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
