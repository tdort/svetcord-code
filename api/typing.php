<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/ChannelModel.php';
require_once __DIR__ . '/../includes/models/DMModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
$pdo = Database::get();
$action = $_GET['action'] ?? '';

// Rows older than this are treated as "no longer typing" — no cleanup job needed.
const TYPING_TTL_SECONDS = 6;

/** Confirms the user may act (ping/list) on this channel or DM target. */
function typing_require_access(string $kind, int $targetId, array $user, PDO $pdo): void
{
    $userId = (int) $user['id'];
    if ($kind === 'channel') {
        $channel = ChannelModel::findById($targetId);
        if (!$channel) {
            json_error('Channel not found.', 404);
        }
        $serverId = (int) $channel['server_id'];
        if (!Permissions::isMember($pdo, $serverId, $userId)) {
            json_error('You do not have access to this channel.', 403);
        }
        $perms = Permissions::effectiveForChannel($pdo, $targetId, $serverId, $userId);
        if (!Permissions::has($perms, Permissions::VIEW_CHANNELS)) {
            json_error('You do not have access to this channel.', 403);
        }
    } elseif ($kind === 'dm') {
        if (!DMModel::isParticipant($targetId, $userId)) {
            json_error('Conversation not found.', 404);
        }
    } else {
        json_error('Invalid kind.');
    }
}

switch ($action) {
    case 'ping': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $kind = (string) ($body['kind'] ?? '');
        $targetId = (int) ($body['target_id'] ?? 0);
        typing_require_access($kind, $targetId, $user, $pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO typing_indicators (kind, target_id, user_id, updated_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $stmt->execute([$kind, $targetId, (int) $user['id']]);
        json_ok();
        break;
    }

    case 'list': {
        $kind = (string) ($_GET['kind'] ?? '');
        $targetId = (int) ($_GET['target_id'] ?? 0);
        typing_require_access($kind, $targetId, $user, $pdo);

        $stmt = $pdo->prepare(
            'SELECT ti.user_id, u.username
             FROM typing_indicators ti
             JOIN users u ON u.id = ti.user_id
             WHERE ti.kind = ? AND ti.target_id = ? AND ti.user_id != ?
               AND ti.updated_at > (NOW() - INTERVAL ' . TYPING_TTL_SECONDS . ' SECOND)'
        );
        $stmt->execute([$kind, $targetId, (int) $user['id']]);
        json_ok(['typing' => $stmt->fetchAll()]);
        break;
    }

    default:
        json_error('Unknown action', 404);
}
