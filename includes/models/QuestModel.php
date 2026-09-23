<?php
require_once __DIR__ . '/../Database.php';

/**
 * "Watch an ad" quest: the client shows a simulated ad, then claims a
 * reward. Timing is enforced server-side (via the PHP session, not a
 * client-supplied timestamp) so the reward can't be claimed by simply
 * POSTing twice — start_ad() stamps a session token, claim_ad() checks
 * that at least AD_WATCH_SECONDS have passed since that stamp.
 */
class QuestModel
{
    public const AD_REWARD_POINTS = 25;
    public const AD_WATCH_SECONDS = 15;   // minimum time between start and claim
    public const AD_COOLDOWN_SECONDS = 45; // minimum time between two claims
    public const AD_DAILY_LIMIT = 20;      // max claims per UTC calendar day

    /** Current point balance, cooldown/limit state for the ad quest. */
    public static function status(int $userId): array
    {
        $pdo = Database::get();

        $stmt = $pdo->prepare('SELECT points FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $points = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM quest_claims
             WHERE user_id = ? AND quest_type = 'watch_ad' AND claimed_at >= UTC_DATE()"
        );
        $stmt->execute([$userId]);
        $watchedToday = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT claimed_at FROM quest_claims
             WHERE user_id = ? AND quest_type = 'watch_ad'
             ORDER BY claimed_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $lastClaimedAt = $stmt->fetchColumn();

        $cooldownRemaining = 0;
        if ($lastClaimedAt) {
            $elapsed = time() - strtotime($lastClaimedAt . ' UTC');
            $cooldownRemaining = max(0, self::AD_COOLDOWN_SECONDS - $elapsed);
        }

        return [
            'points' => $points,
            'reward' => self::AD_REWARD_POINTS,
            'watch_seconds' => self::AD_WATCH_SECONDS,
            'watched_today' => $watchedToday,
            'daily_limit' => self::AD_DAILY_LIMIT,
            'cooldown_remaining' => $cooldownRemaining,
            'can_watch' => $cooldownRemaining === 0 && $watchedToday < self::AD_DAILY_LIMIT,
        ];
    }

    /**
     * Step 1: mark "an ad started" in the session. Rejects immediately if
     * the user is still on cooldown or already hit today's cap, so the
     * client doesn't sit through a fake ad for nothing.
     */
    public static function startAd(int $userId): array
    {
        $status = self::status($userId);
        if (!$status['can_watch']) {
            return ['success' => false, 'error' => $status['cooldown_remaining'] > 0
                ? 'You need to wait a bit before watching another ad.'
                : 'You\'ve hit today\'s ad limit — come back tomorrow.'];
        }

        Auth::bootSession();
        $_SESSION['quest_ad_started_at'] = time();
        return ['success' => true, 'watch_seconds' => self::AD_WATCH_SECONDS];
    }

    /** Step 2: verify enough time actually passed, then award points. */
    public static function claimAd(int $userId): array
    {
        Auth::bootSession();
        $startedAt = $_SESSION['quest_ad_started_at'] ?? null;
        if ($startedAt === null) {
            return ['success' => false, 'error' => 'Start the ad first.'];
        }

        $elapsed = time() - (int) $startedAt;
        if ($elapsed < self::AD_WATCH_SECONDS) {
            return ['success' => false, 'error' => 'Ad not finished yet.'];
        }

        // Re-check cooldown/limit server-side in case of a slow client or
        // a second tab racing this one.
        $status = self::status($userId);
        if (!$status['can_watch']) {
            unset($_SESSION['quest_ad_started_at']);
            return ['success' => false, 'error' => 'You\'ve already claimed this — try again later.'];
        }

        unset($_SESSION['quest_ad_started_at']);

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO quest_claims (user_id, quest_type, reward) VALUES (?, 'watch_ad', ?)"
            );
            $stmt->execute([$userId, self::AD_REWARD_POINTS]);

            $stmt = $pdo->prepare('UPDATE users SET points = points + ? WHERE id = ?');
            $stmt->execute([self::AD_REWARD_POINTS, $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Something went wrong — try again.'];
        }

        $stmt = $pdo->prepare('SELECT points FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return [
            'success' => true,
            'reward' => self::AD_REWARD_POINTS,
            'points' => (int) $stmt->fetchColumn(),
        ];
    }
}
