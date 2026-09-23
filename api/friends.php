<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/FriendModel.php';

Auth::bootSession();
$user = Auth::requireLogin();
$myId = (int) $user['id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    /** Everything the Friends view needs in one call: friends, requests, groups. */
    case 'list': {
        json_ok([
            'friends' => FriendModel::listFriends($myId),
            'incoming' => FriendModel::listIncoming($myId),
            'outgoing' => FriendModel::listOutgoing($myId),
            'groups' => FriendModel::listGroups($myId),
        ]);
        break;
    }

    case 'send_request': {
        require_method('POST');
        $body = request_body();
        $username = sanitize_text($body['username'] ?? '');
        if ($username === '') {
            json_error('Enter a username.');
        }
        if (!empty($user['is_banned'])) {
            json_error('Friending is disabled while your account is suspended.');
        }
        $result = FriendModel::sendRequest($myId, $username);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok(['accepted' => $result['accepted']]);
        break;
    }

    case 'accept_request': {
        require_method('POST');
        $body = request_body();
        $requestId = (int) ($body['request_id'] ?? 0);
        $result = FriendModel::acceptRequest($requestId, $myId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    /** Used both to decline an incoming request and to cancel one we sent. */
    case 'remove_request': {
        require_method('POST');
        $body = request_body();
        $requestId = (int) ($body['request_id'] ?? 0);
        $result = FriendModel::removeRequest($requestId, $myId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'remove_friend': {
        require_method('POST');
        $body = request_body();
        $otherUserId = (int) ($body['user_id'] ?? 0);
        $result = FriendModel::removeFriend($myId, $otherUserId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'create_group': {
        require_method('POST');
        $body = request_body();
        $name = sanitize_text($body['name'] ?? '');
        $result = FriendModel::createGroup($myId, $name);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok(['group_id' => $result['group_id']]);
        break;
    }

    case 'rename_group': {
        require_method('POST');
        $body = request_body();
        $groupId = (int) ($body['group_id'] ?? 0);
        $name = sanitize_text($body['name'] ?? '');
        $result = FriendModel::renameGroup($groupId, $myId, $name);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'delete_group': {
        require_method('POST');
        $body = request_body();
        $groupId = (int) ($body['group_id'] ?? 0);
        $result = FriendModel::deleteGroup($groupId, $myId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'assign_group': {
        require_method('POST');
        $body = request_body();
        $friendUserId = (int) ($body['user_id'] ?? 0);
        $groupId = isset($body['group_id']) && $body['group_id'] !== null && $body['group_id'] !== ''
            ? (int) $body['group_id']
            : null;
        $result = FriendModel::assignToGroup($myId, $friendUserId, $groupId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
