<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/ChannelModel.php';
require_once __DIR__ . '/../includes/models/CategoryModel.php';
require_once __DIR__ . '/../includes/models/ChannelPermissionModel.php';
require_once __DIR__ . '/../includes/models/ServerModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        $myId = (int) $user['id'];
        if (!Permissions::isMember($pdo, $serverId, $myId)) {
            json_error('You are not a member of this server.', 403);
        }
        $server = ServerModel::findById($serverId);
        if ($server && (int) $server['is_disabled'] === 1) {
            json_error('This server has been disabled by an admin.', 403);
        }
        $channels = [];
        foreach (ChannelModel::listForServer($serverId) as $ch) {
            $effective = Permissions::effectiveForChannel($pdo, (int) $ch['id'], $serverId, $myId);
            if (!Permissions::has($effective, Permissions::VIEW_CHANNELS)) {
                continue;
            }
            $ch['can_send'] = Permissions::has($effective, Permissions::SEND_MESSAGES);
            $channels[] = $ch;
        }
        json_ok([
            'channels' => $channels,
            'categories' => CategoryModel::listForServer($serverId),
        ]);
        break;
    }

    case 'create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $name = sanitize_text($body['name'] ?? '');
        $type = ($body['type'] ?? 'text') === 'voice' ? 'voice' : 'text';
        $topic = isset($body['topic']) ? sanitize_text($body['topic']) : null;
        $categoryId = !empty($body['category_id']) ? (int) $body['category_id'] : null;

        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        if ($name === '' || mb_strlen($name) > 100) {
            json_error('Channel name must be 1-100 characters.');
        }
        if ($categoryId !== null && !CategoryModel::belongsToServer($categoryId, $serverId)) {
            json_error('Category not found.', 404);
        }

        // channel names: lowercase, dashes instead of spaces (discord-style) for text channels
        if ($type === 'text') {
            $name = strtolower(preg_replace('/\s+/', '-', $name));
        }

        $channel = ChannelModel::create($serverId, $name, $type, $topic, $categoryId);
        json_ok(['channel' => $channel]);
        break;
    }

    case 'update': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);
        $channel = ChannelModel::findById($channelId);
        if (!$channel) {
            json_error('Channel not found.', 404);
        }
        $serverId = (int) $channel['server_id'];
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $name = sanitize_text($body['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                json_error('Channel name must be 1-100 characters.');
            }
            if ($channel['type'] === 'text') {
                $name = strtolower(preg_replace('/\s+/', '-', $name));
            }
        }

        $topic = array_key_exists('topic', $body) ? sanitize_text($body['topic']) : null;

        // category_id: absent = leave unchanged, null/0 = move to uncategorized, otherwise move into that category.
        $moveToUncategorized = false;
        $categoryId = null;
        if (array_key_exists('category_id', $body)) {
            if (empty($body['category_id'])) {
                $moveToUncategorized = true;
            } else {
                $categoryId = (int) $body['category_id'];
                if (!CategoryModel::belongsToServer($categoryId, $serverId)) {
                    json_error('Category not found.', 404);
                }
            }
        }

        $updated = ChannelModel::update($channelId, $name, $topic, $categoryId, $moveToUncategorized);
        json_ok(['channel' => $updated]);
        break;
    }

    case 'delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);
        $channel = ChannelModel::findById($channelId);
        if (!$channel) {
            json_error('Channel not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $channel['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        ChannelModel::delete($channelId);
        json_ok();
        break;
    }

    /** ---------------- Categories ---------------- */

    case 'category_create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $name = sanitize_text($body['name'] ?? '');

        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        if ($name === '' || mb_strlen($name) > 100) {
            json_error('Category name must be 1-100 characters.');
        }

        $category = CategoryModel::create($serverId, $name);
        json_ok(['category' => $category]);
        break;
    }

    case 'category_rename': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $categoryId = (int) ($body['category_id'] ?? 0);
        $name = sanitize_text($body['name'] ?? '');
        $category = CategoryModel::findById($categoryId);
        if (!$category) {
            json_error('Category not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $category['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        if ($name === '' || mb_strlen($name) > 100) {
            json_error('Category name must be 1-100 characters.');
        }
        CategoryModel::rename($categoryId, $name);
        json_ok();
        break;
    }

    case 'category_delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $categoryId = (int) ($body['category_id'] ?? 0);
        $category = CategoryModel::findById($categoryId);
        if (!$category) {
            json_error('Category not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $category['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        // Channels in this category are NOT deleted — they fall back to uncategorized.
        CategoryModel::delete($categoryId);
        json_ok();
        break;
    }

    case 'category_move': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $categoryId = (int) ($body['category_id'] ?? 0);
        $direction = ($body['direction'] ?? '') === 'up' ? 'up' : 'down';
        $category = CategoryModel::findById($categoryId);
        if (!$category) {
            json_error('Category not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $category['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        CategoryModel::move($categoryId, $direction);
        json_ok();
        break;
    }

    /**
     * "Deleting" the built-in Text/Voice Channels header: same idea as
     * deleting a real category — the label disappears, channels underneath
     * are untouched, they just render without a group label.
     */
    case 'set_default_group_hidden': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $type = ($body['type'] ?? '') === 'voice' ? 'voice' : 'text';
        $hidden = !empty($body['hidden']) ? 1 : 0;

        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        $column = $type === 'voice' ? 'hide_voice_header' : 'hide_text_header';
        $pdo->prepare("UPDATE servers SET {$column} = ? WHERE id = ?")->execute([$hidden, $serverId]);
        json_ok();
        break;
    }

    /** ---------------- Channel permission overrides ---------------- */

    case 'permissions': {
        $channelId = (int) ($_GET['channel_id'] ?? 0);
        $channel = ChannelModel::findById($channelId);
        if (!$channel) {
            json_error('Channel not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $channel['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }
        json_ok([
            'overrides' => ChannelPermissionModel::listForChannel($channelId),
            'flags' => Permissions::CHANNEL_OVERRIDABLE_FLAGS,
        ]);
        break;
    }

    case 'set_permission': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);
        $roleId = (int) ($body['role_id'] ?? 0);
        $allow = (int) ($body['allow'] ?? 0);
        $deny = (int) ($body['deny'] ?? 0);

        $channel = ChannelModel::findById($channelId);
        if (!$channel) {
            json_error('Channel not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $channel['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_CHANNELS)) {
            json_error('You do not have permission to manage channels.', 403);
        }

        // Only allow touching the flags that are meant to be overridable —
        // stops a stray/forged allow/deny bit from smuggling in ADMINISTRATOR etc.
        $overridable = array_sum(Permissions::CHANNEL_OVERRIDABLE_FLAGS);
        $allow &= $overridable;
        $deny &= $overridable;
        // A flag can't be both allowed and denied at once — deny wins if both are set.
        $allow &= ~$deny;

        ChannelPermissionModel::setOverride($channelId, $roleId, $allow, $deny);
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
