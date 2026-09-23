<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/ChannelModel.php';
require_once __DIR__ . '/../includes/models/MessageModel.php';
require_once __DIR__ . '/../includes/models/ReadStateModel.php';
require_once __DIR__ . '/../includes/models/DMModel.php';
require_once __DIR__ . '/../includes/models/ServerModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

/** Resolve a channel and confirm the current user can see it (member + per-channel VIEW_CHANNELS). */
function require_channel_access(int $channelId, array $user, PDO $pdo): array
{
    $channel = ChannelModel::findById($channelId);
    if (!$channel) {
        json_error('Channel not found.', 404);
    }
    $serverId = (int) $channel['server_id'];
    $userId = (int) $user['id'];
    if (!Permissions::isMember($pdo, $serverId, $userId)) {
        json_error('You do not have access to this channel.', 403);
    }
    $server = ServerModel::findById($serverId);
    if ($server && (int) $server['is_disabled'] === 1) {
        json_error('This server has been disabled by an admin.', 403);
    }
    $perms = Permissions::effectiveForChannel($pdo, $channelId, $serverId, $userId);
    if (!Permissions::has($perms, Permissions::VIEW_CHANNELS)) {
        json_error('You do not have access to this channel.', 403);
    }
    return $channel;
}

switch ($action) {
    case 'list': {
        $channelId = (int) ($_GET['channel_id'] ?? 0);
        $channel = require_channel_access($channelId, $user, $pdo);

        $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : null;
        $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;

        $messages = MessageModel::listForChannel($channelId, $afterId, $beforeId);
        $messages = MessageModel::attachReactions($messages, (int) $user['id']);
        json_ok(['messages' => $messages, 'channel' => $channel]);
        break;
    }

    case 'send': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);
        $content = sanitize_text($body['content'] ?? '');
        $attachmentUrl = isset($body['attachment_url']) ? sanitize_text((string) $body['attachment_url']) : null;
        $attachmentName = isset($body['attachment_name']) ? sanitize_text((string) $body['attachment_name']) : null;
        $attachmentType = isset($body['attachment_type']) ? sanitize_text((string) $body['attachment_type']) : null;
        if ($attachmentUrl === '') $attachmentUrl = null;
        if ($attachmentUrl !== null && !str_starts_with($attachmentUrl, UPLOAD_URL . '/')) {
            json_error('Invalid attachment.');
        }

        $channel = require_channel_access($channelId, $user, $pdo);
        if ($channel['type'] !== 'text') {
            json_error('You cannot send messages in a voice channel.');
        }

        $perms = Permissions::effectiveForChannel($pdo, $channelId, (int) $channel['server_id'], (int) $user['id']);
        if (!Permissions::has($perms, Permissions::SEND_MESSAGES)) {
            json_error('You do not have permission to send messages here.', 403);
        }

        if ($content === '' && $attachmentUrl === null) {
            json_error('Message cannot be empty.');
        }
        if (mb_strlen($content) > 4000) {
            json_error('Message is too long (max 4000 characters).');
        }

        $message = MessageModel::create($channelId, (int) $user['id'], $content, $attachmentUrl, $attachmentName, $attachmentType);
        ReadStateModel::markRead('channel', $channelId, (int) $user['id'], (int) $message['id']);

        // Server-hosted no-code bot automations — only human messages trigger these,
        // so a bot's own reply can never chain-trigger another bot forever.
        if (empty($user['is_bot'])) {
            require_once __DIR__ . '/../includes/models/BotRuleModel.php';
            BotRuleModel::runTriggersForMessage($channelId, (int) $channel['server_id'], $content, (string) $user['username']);
        }

        json_ok(['message' => $message]);
        break;
    }

    case 'react': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $messageId = (int) ($body['message_id'] ?? 0);
        $emoji = (string) ($body['emoji'] ?? '');

        $allowedEmoji = ['👍', '👎', '❤️', '😂', '😮', '😢', '🎉', '🔥', '👀', '✅'];
        if (!in_array($emoji, $allowedEmoji, true)) {
            json_error('Unsupported reaction.');
        }

        $message = MessageModel::findById($messageId);
        if (!$message) {
            json_error('Message not found.', 404);
        }
        require_channel_access((int) $message['channel_id'], $user, $pdo);

        $reacted = MessageModel::toggleReaction($messageId, (int) $user['id'], $emoji);
        json_ok(['reacted' => $reacted]);
        break;
    }

    case 'delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $messageId = (int) ($body['message_id'] ?? 0);
        $message = MessageModel::findById($messageId);
        if (!$message) {
            json_error('Message not found.', 404);
        }
        $channel = ChannelModel::findById((int) $message['channel_id']);
        $perms = Permissions::effectiveForChannel($pdo, (int) $channel['id'], (int) $channel['server_id'], (int) $user['id']);

        $isOwnMessage = (int) $message['user_id'] === (int) $user['id'];
        if (!$isOwnMessage && !Permissions::has($perms, Permissions::MANAGE_MESSAGES)) {
            json_error('You do not have permission to delete this message.', 403);
        }

        MessageModel::delete($messageId);
        json_ok();
        break;
    }

    case 'edit': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $messageId = (int) ($body['message_id'] ?? 0);
        $content = sanitize_text($body['content'] ?? '');
        $message = MessageModel::findById($messageId);
        if (!$message) {
            json_error('Message not found.', 404);
        }
        if ((int) $message['user_id'] !== (int) $user['id']) {
            json_error('You can only edit your own messages.', 403);
        }
        if ($content === '') {
            json_error('Message cannot be empty.');
        }
        MessageModel::edit($messageId, $content);
        json_ok();
        break;
    }

    case 'mark_read': {
        require_method('POST');
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);
        require_channel_access($channelId, $user, $pdo);
        $latest = MessageModel::latestIdForChannel($channelId);
        ReadStateModel::markRead('channel', $channelId, (int) $user['id'], $latest);
        json_ok();
        break;
    }

    /** One lightweight poll covering unread counts for every channel + DM the user has, for sidebar badges. */
    case 'unread_summary': {
        $userId = (int) $user['id'];
        json_ok([
            'channels' => ReadStateModel::channelUnreadCounts($userId),
            'conversations' => ReadStateModel::dmUnreadCounts($userId),
            'servers' => ReadStateModel::serverHasUnread($userId),
        ]);
        break;
    }

    default:
        json_error('Unknown action', 404);
}
