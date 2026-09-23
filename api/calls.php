<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/CallModel.php';
require_once __DIR__ . '/../includes/models/DMModel.php';
require_once __DIR__ . '/../includes/models/ChannelModel.php';
require_once __DIR__ . '/../includes/CloudflareTurn.php';

Auth::bootSession();
$user = Auth::requireLogin();
$myId = (int) $user['id'];
$pdo = Database::get();
$action = $_GET['action'] ?? '';

/** Load a call by id or 404. */
function load_call(int $callId): array
{
    $call = CallModel::findById($callId);
    if (!$call) {
        json_error('Call not found.', 404);
    }
    return $call;
}

switch ($action) {
    /** STUN/TURN config as JSON, for non-browser clients (the web app gets this inline via window.ICE_CONFIG). */
    case 'ice_servers': {
        $servers = [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
        ];
        $turn = CloudflareTurn::getIceServers();
        if (is_array($turn)) {
            $servers = array_merge($servers, $turn);
        }
        json_ok(['ice_servers' => $servers]);
        break;
    }

    /**
     * Start (if none active) or join the active call for a DM
     * conversation. Used by both the caller and, after they see the
     * incoming-call notice, the callee.
     */
    case 'join_dm': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $conversationId = (int) ($body['conversation_id'] ?? 0);

        if (!DMModel::isParticipant($conversationId, $myId)) {
            json_error('Conversation not found.', 404);
        }

        $result = CallModel::joinDmCall($conversationId, $myId);
        $participants = array_values(array_filter(
            CallModel::activeParticipants((int) $result['call']['id']),
            fn($p) => (int) $p['user_id'] !== $myId
        ));

        json_ok([
            'call_id' => (int) $result['call']['id'],
            'created' => $result['created'],
            'participants' => $participants,
        ]);
        break;
    }

    /** Let the ringing caller know their call was declined. */
    case 'decline_dm': {
        require_method('POST');
        $body = request_body();
        $conversationId = (int) ($body['conversation_id'] ?? 0);

        if (!DMModel::isParticipant($conversationId, $myId)) {
            json_error('Conversation not found.', 404);
        }

        CallModel::declineDmCall($conversationId, $myId);
        json_ok();
        break;
    }

    /** Any DM call currently ringing for me that I haven't joined. */
    case 'incoming_dm': {
        $call = CallModel::incomingDmCallFor($myId);
        json_ok(['call' => $call]);
        break;
    }

    /** Start (if none active) or join the active call for a voice channel. */
    case 'join_channel': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);

        $channel = ChannelModel::findById($channelId);
        if (!$channel || $channel['type'] !== 'voice') {
            json_error('Voice channel not found.', 404);
        }
        if (!Permissions::isMember($pdo, (int) $channel['server_id'], $myId)) {
            json_error('You are not a member of that server.', 403);
        }

        $result = CallModel::joinChannelCall($channelId, $myId);
        $participants = array_values(array_filter(
            CallModel::activeParticipants((int) $result['call']['id']),
            fn($p) => (int) $p['user_id'] !== $myId
        ));

        json_ok([
            'call_id' => (int) $result['call']['id'],
            'created' => $result['created'],
            'participants' => $participants,
        ]);
        break;
    }

    /** Roster of who's currently connected to each voice channel in a server. */
    case 'channel_voice_status': {
        $serverId = (int) ($_GET['server_id'] ?? 0);
        if (!Permissions::isMember($pdo, $serverId, $myId)) {
            json_error('You are not a member of that server.', 403);
        }
        json_ok(['status' => CallModel::voiceStatusForServer($serverId)]);
        break;
    }

    /**
     * Force another member out of the active call for a voice channel
     * (Discord-style "Disconnect"). Requires KICK_MEMBERS (or
     * ADMINISTRATOR/owner) on the server that channel belongs to.
     * Only applies to voice-channel calls — a 1:1 DM call has no
     * server or moderators, so there's nothing to check permissions
     * against.
     */
    case 'disconnect_user': {
        require_method('POST');
        $body = request_body();
        $channelId = (int) ($body['channel_id'] ?? 0);
        $targetUserId = (int) ($body['user_id'] ?? 0);

        if ($targetUserId === $myId) {
            json_error('Use the leave action to disconnect yourself.');
        }

        $channel = ChannelModel::findById($channelId);
        if (!$channel || $channel['type'] !== 'voice') {
            json_error('Voice channel not found.', 404);
        }

        $perms = Permissions::effectiveFor($pdo, (int) $channel['server_id'], $myId);
        if (!Permissions::has($perms, Permissions::KICK_MEMBERS)) {
            json_error('You do not have permission to disconnect members from voice.', 403);
        }

        $call = CallModel::findActiveChannelCall($channelId);
        if (!$call || !CallModel::isActiveParticipant((int) $call['id'], $targetUserId)) {
            json_error('That user is not currently in a call in this channel.', 404);
        }

        CallModel::disconnectUser((int) $call['id'], $targetUserId, $myId);
        json_ok();
        break;
    }

    /** Leave a call I'm currently connected to. */
    case 'leave': {
        require_method('POST');
        $body = request_body();
        $callId = (int) ($body['call_id'] ?? 0);
        load_call($callId);

        if (!CallModel::isActiveParticipant($callId, $myId)) {
            json_error('You are not in that call.', 403);
        }
        CallModel::leave($callId, $myId);
        json_ok();
        break;
    }

    /** Current roster for a call I'm part of (used to discover new peers). */
    case 'participants': {
        $callId = (int) ($_GET['call_id'] ?? 0);
        load_call($callId);
        if (!CallModel::isActiveParticipant($callId, $myId)) {
            json_error('You are not in that call.', 403);
        }
        json_ok(['participants' => CallModel::activeParticipants($callId)]);
        break;
    }

    /**
     * Relay a WebRTC signal (offer/answer/ICE candidate) to another
     * active participant in the same call.
     */
    case 'signal': {
        require_method('POST');
        $body = request_body();
        $callId = (int) ($body['call_id'] ?? 0);
        $toUserId = (int) ($body['to_user_id'] ?? 0);
        $type = (string) ($body['type'] ?? '');
        $payload = $body['payload'] ?? null;

        if (!in_array($type, ['offer', 'answer', 'candidate'], true)) {
            json_error('Invalid signal type.');
        }
        if (!is_array($payload)) {
            json_error('Invalid signal payload.');
        }
        load_call($callId);

        if (!CallModel::isActiveParticipant($callId, $myId)) {
            json_error('You are not in that call.', 403);
        }
        if (!CallModel::isActiveParticipant($callId, $toUserId)) {
            json_error('That user is not in the call.', 404);
        }

        CallModel::sendSignal($callId, $myId, $toUserId, $type, $payload);
        json_ok();
        break;
    }

    /** Signals addressed to me in this call since $after_id. */
    case 'signals': {
        $callId = (int) ($_GET['call_id'] ?? 0);
        $afterId = (int) ($_GET['after_id'] ?? 0);
        load_call($callId);

        if (!CallModel::isActiveParticipant($callId, $myId)) {
            json_error('You are not in that call.', 403);
        }

        $signals = CallModel::fetchSignals($callId, $myId, $afterId);
        foreach ($signals as &$sig) {
            $decoded = json_decode($sig['payload'], true);
            $sig['payload'] = is_array($decoded) ? $decoded : [];
        }
        json_ok(['signals' => $signals]);
        break;
    }

    default:
        json_error('Unknown action', 404);
}
