<?php
require_once __DIR__ . '/../Database.php';

/**
 * Backing model for audio calling. A "call" is a lightweight row
 * tying together either a DM conversation or a voice channel with
 * whoever is currently connected. Actual audio never touches the
 * server — peers connect directly over WebRTC, and these tables
 * only carry the signaling handshake (offer/answer/ICE candidates),
 * relayed by polling, plus the current participant roster.
 */
class CallModel
{
    /** Active (not-yet-ended) call for a DM conversation, if any. */
    public static function findActiveDmCall(int $conversationId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM calls WHERE type = 'dm' AND dm_conversation_id = ? AND ended_at IS NULL"
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetch() ?: null;
    }

    /** Active (not-yet-ended) call for a voice channel, if any. */
    public static function findActiveChannelCall(int $channelId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT * FROM calls WHERE type = 'channel' AND channel_id = ? AND ended_at IS NULL"
        );
        $stmt->execute([$channelId]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $callId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM calls WHERE id = ?');
        $stmt->execute([$callId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Find-or-create the active call for a DM conversation, then add
     * $userId as a connected participant. Used by both the caller
     * (creates it) and the callee (joins the existing one).
     */
    public static function joinDmCall(int $conversationId, int $userId): array
    {
        $pdo = Database::get();
        $call = self::findActiveDmCall($conversationId);
        $created = false;

        if (!$call) {
            $stmt = $pdo->prepare(
                "INSERT INTO calls (type, dm_conversation_id, started_by, created_at)
                 VALUES ('dm', ?, ?, NOW())"
            );
            $stmt->execute([$conversationId, $userId]);
            $call = self::findById((int) $pdo->lastInsertId());
            $created = true;
        }

        self::addParticipant((int) $call['id'], $userId);

        return ['call' => $call, 'created' => $created];
    }

    /** Same idea as joinDmCall(), for a server voice channel. */
    public static function joinChannelCall(int $channelId, int $userId): array
    {
        $pdo = Database::get();
        $call = self::findActiveChannelCall($channelId);
        $created = false;

        if (!$call) {
            $stmt = $pdo->prepare(
                "INSERT INTO calls (type, channel_id, started_by, created_at)
                 VALUES ('channel', ?, ?, NOW())"
            );
            $stmt->execute([$channelId, $userId]);
            $call = self::findById((int) $pdo->lastInsertId());
            $created = true;
        }

        self::addParticipant((int) $call['id'], $userId);

        return ['call' => $call, 'created' => $created];
    }

    /** Add (or reactivate) a participant row for a user in a call. */
    private static function addParticipant(int $callId, int $userId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id FROM call_participants WHERE call_id = ? AND user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$callId, $userId]);
        if ($stmt->fetch()) {
            return; // already an active participant
        }
        $pdo->prepare(
            'INSERT INTO call_participants (call_id, user_id, joined_at) VALUES (?, ?, NOW())'
        )->execute([$callId, $userId]);
    }

    public static function isActiveParticipant(int $callId, int $userId): bool
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM call_participants WHERE call_id = ? AND user_id = ? AND left_at IS NULL'
        );
        $stmt->execute([$callId, $userId]);
        return (bool) $stmt->fetch();
    }

    /** Currently-connected participants for a call (excluding no one). */
    public static function activeParticipants(int $callId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT u.id AS user_id, u.username, u.avatar_url
             FROM call_participants cp
             JOIN users u ON u.id = cp.user_id
             WHERE cp.call_id = ? AND cp.left_at IS NULL
             ORDER BY cp.joined_at ASC'
        );
        $stmt->execute([$callId]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a user as having left a call. Notifies every other active
     * participant with a 'leave' signal so their client can tear down
     * that peer connection even if this poll cycle is missed. If no
     * one is left connected, the call is marked ended.
     */
    public static function leave(int $callId, int $userId): void
    {
        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE call_participants SET left_at = NOW()
             WHERE call_id = ? AND user_id = ? AND left_at IS NULL'
        )->execute([$callId, $userId]);

        $remaining = self::activeParticipants($callId);
        foreach ($remaining as $peer) {
            self::sendSignal($callId, $userId, (int) $peer['user_id'], 'leave', ['reason' => 'left']);
        }

        if (count($remaining) === 0) {
            $pdo->prepare('UPDATE calls SET ended_at = NOW() WHERE id = ? AND ended_at IS NULL')
                ->execute([$callId]);
        }
    }

    /**
     * Force a participant out of a voice-channel call. Used for
     * moderator "disconnect" actions — the caller (api/calls.php)
     * is responsible for checking KICK_MEMBERS/ADMINISTATOR first.
     *
     * This does the same participant/roster bookkeeping as a normal
     * leave() (marks them left, notifies remaining peers, ends the
     * call if empty), plus one extra signal sent straight to the
     * disconnected user themselves — unlike a voluntary leave, they
     * didn't initiate this, so their own client needs to be told to
     * hang up and given a reason.
     */
    public static function disconnectUser(int $callId, int $targetUserId, int $byUserId): void
    {
        self::sendSignal($callId, $byUserId, $targetUserId, 'leave', ['reason' => 'disconnected']);
        self::leave($callId, $targetUserId);
    }

    /**
     * Send a decline signal for a DM call the user was invited to but
     * never joined (so the caller's UI can stop ringing immediately).
     */
    public static function declineDmCall(int $conversationId, int $userId): bool
    {
        $call = self::findActiveDmCall($conversationId);
        if (!$call || self::isActiveParticipant((int) $call['id'], $userId)) {
            return false;
        }
        self::sendSignal((int) $call['id'], $userId, (int) $call['started_by'], 'leave', ['declined' => true]);
        return true;
    }

    /**
     * Find an active DM call that's "ringing" for $userId: a call in
     * a DM conversation they belong to, that they haven't joined yet.
     */
    public static function incomingDmCallFor(int $userId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT c.*, dm.user_one_id, dm.user_two_id
             FROM calls c
             JOIN dm_conversations dm ON dm.id = c.dm_conversation_id
             WHERE c.type = 'dm' AND c.ended_at IS NULL
               AND (dm.user_one_id = ? OR dm.user_two_id = ?)
               AND NOT EXISTS (
                   SELECT 1 FROM call_participants cp
                   WHERE cp.call_id = c.id AND cp.user_id = ? AND cp.left_at IS NULL
               )
               AND EXISTS (
                   SELECT 1 FROM call_participants cp2
                   WHERE cp2.call_id = c.id AND cp2.left_at IS NULL
               )
             ORDER BY c.id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $userId, $userId]);
        $call = $stmt->fetch();
        if (!$call) {
            return null;
        }

        $callerId = (int) $call['user_one_id'] === $userId ? (int) $call['user_two_id'] : (int) $call['user_one_id'];
        $userStmt = $pdo->prepare('SELECT id, username, avatar_url FROM users WHERE id = ?');
        $userStmt->execute([$callerId]);
        $call['caller'] = $userStmt->fetch();

        return $call;
    }

    /** Active voice-call rosters for every voice channel in a server. */
    public static function voiceStatusForServer(int $serverId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "SELECT id FROM channels WHERE server_id = ? AND type = 'voice'"
        );
        $stmt->execute([$serverId]);
        $channelIds = array_column($stmt->fetchAll(), 'id');

        $status = [];
        foreach ($channelIds as $channelId) {
            $call = self::findActiveChannelCall((int) $channelId);
            $status[$channelId] = $call ? self::activeParticipants((int) $call['id']) : [];
        }
        return $status;
    }

    public static function sendSignal(int $callId, int $fromUserId, int $toUserId, string $type, array $payload): void
    {
        $pdo = Database::get();
        $pdo->prepare(
            'INSERT INTO call_signals (call_id, from_user_id, to_user_id, type, payload, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$callId, $fromUserId, $toUserId, $type, json_encode($payload)]);
    }

    /** Signals addressed to $userId in this call, after $afterId. */
    public static function fetchSignals(int $callId, int $userId, int $afterId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, from_user_id, type, payload FROM call_signals
             WHERE call_id = ? AND to_user_id = ? AND id > ?
             ORDER BY id ASC'
        );
        $stmt->execute([$callId, $userId, $afterId]);
        return $stmt->fetchAll();
    }
}
