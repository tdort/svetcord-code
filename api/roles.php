<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/RoleModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

/** Validate that the requesting user can manage roles for the given server. */
function require_manage_roles(int $serverId, array $user, PDO $pdo): void
{
    Auth::requireNotBanned($user);
    $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
    if (!Permissions::has($perms, Permissions::MANAGE_ROLES)) {
        json_error('You do not have permission to manage roles.', 403);
    }
}

switch ($action) {
    case 'list': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        if (!Permissions::isMember($pdo, $serverId, (int) $user['id'])) {
            json_error('You are not a member of this server.', 403);
        }
        json_ok(['roles' => RoleModel::listForServer($serverId), 'flags' => Permissions::ALL_FLAGS]);
        break;
    }

    case 'create': {
        require_method('POST');
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        require_manage_roles($serverId, $user, $pdo);

        $name = sanitize_text($body['name'] ?? '');
        $color = sanitize_text($body['color'] ?? '#99AAB5');
        $permissions = (int) ($body['permissions'] ?? 0);

        if ($name === '' || mb_strlen($name) > 50) {
            json_error('Role name must be 1-50 characters.');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#99AAB5';
        }

        $role = RoleModel::create($serverId, $name, $color, $permissions);
        json_ok(['role' => $role]);
        break;
    }

    case 'update': {
        require_method('POST');
        $body = request_body();
        $roleId = (int) ($body['role_id'] ?? 0);
        $role = RoleModel::findById($roleId);
        if (!$role) {
            json_error('Role not found.', 404);
        }
        require_manage_roles((int) $role['server_id'], $user, $pdo);

        $name = sanitize_text($body['name'] ?? $role['name']);
        $color = sanitize_text($body['color'] ?? $role['color']);
        $permissions = (int) ($body['permissions'] ?? $role['permissions']);

        RoleModel::update($roleId, $name, $color, $permissions);
        json_ok(['role' => RoleModel::findById($roleId)]);
        break;
    }

    case 'delete': {
        require_method('POST');
        $body = request_body();
        $roleId = (int) ($body['role_id'] ?? 0);
        $role = RoleModel::findById($roleId);
        if (!$role) {
            json_error('Role not found.', 404);
        }
        require_manage_roles((int) $role['server_id'], $user, $pdo);
        if ((int) $role['is_default'] === 1) {
            json_error('The @everyone role cannot be deleted.');
        }
        RoleModel::delete($roleId);
        json_ok();
        break;
    }

    case 'assign': {
        require_method('POST');
        $body = request_body();
        $roleId = (int) ($body['role_id'] ?? 0);
        $targetUserId = (int) ($body['user_id'] ?? 0);
        $role = RoleModel::findById($roleId);
        if (!$role) {
            json_error('Role not found.', 404);
        }
        require_manage_roles((int) $role['server_id'], $user, $pdo);

        $memberId = RoleModel::memberIdFor((int) $role['server_id'], $targetUserId);
        if ($memberId === null) {
            json_error('That user is not a member of this server.', 404);
        }
        RoleModel::assignToMember($memberId, $roleId);
        json_ok();
        break;
    }

    case 'unassign': {
        require_method('POST');
        $body = request_body();
        $roleId = (int) ($body['role_id'] ?? 0);
        $targetUserId = (int) ($body['user_id'] ?? 0);
        $role = RoleModel::findById($roleId);
        if (!$role) {
            json_error('Role not found.', 404);
        }
        require_manage_roles((int) $role['server_id'], $user, $pdo);
        if ((int) $role['is_default'] === 1) {
            json_error('The @everyone role cannot be removed from a member.');
        }

        $memberId = RoleModel::memberIdFor((int) $role['server_id'], $targetUserId);
        if ($memberId === null) {
            json_error('That user is not a member of this server.', 404);
        }
        RoleModel::removeFromMember($memberId, $roleId);
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
