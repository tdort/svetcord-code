<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/CustomEmojiModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        if (!Permissions::isMember($pdo, $serverId, (int) $user['id'])) {
            json_error('You do not have access to this server.', 403);
        }
        json_ok(['emojis' => CustomEmojiModel::listForServer($serverId)]);
        break;
    }

    /** Every custom emoji the current user can use, across all their servers — for message rendering + the picker. */
    case 'usable': {
        json_ok(['emojis' => CustomEmojiModel::mapForUser((int) $user['id'])]);
        break;
    }

    case 'delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $emojiId = (int) ($body['emoji_id'] ?? 0);
        $emoji = CustomEmojiModel::findById($emojiId);
        if (!$emoji) {
            json_error('Emoji not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, (int) $emoji['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to manage this server\'s emojis.', 403);
        }
        CustomEmojiModel::delete($emojiId);
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
