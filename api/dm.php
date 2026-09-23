<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/models/DMModel.php';
require_once __DIR__ . '/../includes/models/UserModel.php';
require_once __DIR__ . '/../includes/models/ReadStateModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'conversations': {
        $conversations = DMModel::listForUser((int) $user['id']);
        json_ok(['conversations' => $conversations]);
        break;
    }

    case 'start': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $otherUserId = (int) ($body['user_id'] ?? 0);
        if ($otherUserId === (int) $user['id']) {
            json_error('You cannot message yourself.');
        }
        $other = UserModel::findPublicById($otherUserId);
        if (!$other) {
            json_error('User not found.', 404);
        }
        $convo = DMModel::findOrCreateConversation((int) $user['id'], $otherUserId);
        json_ok(['conversation' => $convo, 'other_user' => $other]);
        break;
    }

    case 'messages': {
        $conversationId = (int) ($_GET['conversation_id'] ?? 0);
        if (!DMModel::isParticipant($conversationId, (int) $user['id'])) {
            json_error('Conversation not found.', 404);
        }
        $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : null;
        $messages = DMModel::listMessages($conversationId, $afterId);
        json_ok(['messages' => $messages]);
        break;
    }

    case 'send': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $conversationId = (int) ($body['conversation_id'] ?? 0);
        $content = sanitize_text($body['content'] ?? '');
        $attachmentUrl = isset($body['attachment_url']) ? sanitize_text((string) $body['attachment_url']) : null;
        $attachmentName = isset($body['attachment_name']) ? sanitize_text((string) $body['attachment_name']) : null;
        $attachmentType = isset($body['attachment_type']) ? sanitize_text((string) $body['attachment_type']) : null;
        if ($attachmentUrl === '') $attachmentUrl = null;
        if ($attachmentUrl !== null && !str_starts_with($attachmentUrl, UPLOAD_URL . '/')) {
            json_error('Invalid attachment.');
        }

        if (!DMModel::isParticipant($conversationId, (int) $user['id'])) {
            json_error('Conversation not found.', 404);
        }
        if ($content === '' && $attachmentUrl === null) {
            json_error('Message cannot be empty.');
        }
        if (mb_strlen($content) > 4000) {
            json_error('Message is too long (max 4000 characters).');
        }

        $message = DMModel::sendMessage($conversationId, (int) $user['id'], $content, $attachmentUrl, $attachmentName, $attachmentType);
        ReadStateModel::markRead('dm', $conversationId, (int) $user['id'], (int) $message['id']);
        json_ok(['message' => $message]);
        break;
    }

    case 'mark_read': {
        require_method('POST');
        $body = request_body();
        $conversationId = (int) ($body['conversation_id'] ?? 0);
        if (!DMModel::isParticipant($conversationId, (int) $user['id'])) {
            json_error('Conversation not found.', 404);
        }
        $latest = DMModel::latestMessageId($conversationId);
        ReadStateModel::markRead('dm', $conversationId, (int) $user['id'], $latest);
        json_ok();
        break;
    }

    case 'search_users': {
        $query = sanitize_text($_GET['q'] ?? '');
        if (mb_strlen($query) < 2) {
            json_ok(['users' => []]);
        }
        $users = array_values(array_filter(
            UserModel::searchByUsername($query),
            fn($u) => (int) $u['id'] !== (int) $user['id']
        ));
        json_ok(['users' => $users]);
        break;
    }

    default:
        json_error('Unknown action', 404);
}
