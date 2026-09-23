<?php
/**
 * Fetches short-lived STUN/TURN credentials from Cloudflare's Realtime
 * TURN service (see config/config.php for CF_TURN_KEY_ID / CF_TURN_API_TOKEN).
 *
 * Cloudflare's TURN key itself is a long-lived server-side secret; it is
 * never sent to the browser. Instead, this calls Cloudflare's API to mint
 * a short-lived username/credential pair (valid for CF_TURN_TTL seconds)
 * and hands *that* to the client. Results are cached to disk so we don't
 * hit Cloudflare's API on every page load — only once the cached
 * credential is close to expiring.
 */
class CloudflareTurn
{
    private static function cacheFile(): string
    {
        return sys_get_temp_dir() . '/discordish_cf_turn_cache.json';
    }

    private static function usageCacheFile(): string
    {
        return sys_get_temp_dir() . '/discordish_cf_turn_usage_cache.json';
    }

    /**
     * Returns an array of ICE server objects (STUN + TURN combined),
     * ready to hand straight to RTCPeerConnection — or null if Cloudflare
     * TURN isn't configured (CF_TURN_KEY_ID/CF_TURN_API_TOKEN empty), this
     * month's usage has hit CF_TURN_MONTHLY_LIMIT_GB, or the request to
     * Cloudflare failed.
     */
    public static function getIceServers(): ?array
    {
        if (!defined('CF_TURN_KEY_ID') || !defined('CF_TURN_API_TOKEN')
            || CF_TURN_KEY_ID === '' || CF_TURN_API_TOKEN === '') {
            return null;
        }

        if (self::monthlyUsageExceeded()) {
            return null;
        }

        $cached = self::readCache();
        if ($cached !== null) {
            return $cached;
        }

        $ttl = defined('CF_TURN_TTL') ? (int) CF_TURN_TTL : 86400;
        $url = 'https://rtc.live.cloudflare.com/v1/turn/keys/'
            . rawurlencode(CF_TURN_KEY_ID) . '/credentials/generate-ice-servers';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['ttl' => $ttl]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . CF_TURN_API_TOKEN,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 201) {
            error_log('[CloudflareTurn] Failed to fetch TURN credentials (HTTP '
                . $status . '): ' . $curlErr);
            return null;
        }

        $data = json_decode($body, true);
        $iceServers = $data['iceServers'] ?? null;
        if (!is_array($iceServers)) {
            error_log('[CloudflareTurn] Unexpected response shape from Cloudflare TURN API: ' . $body);
            return null;
        }

        // Cache for somewhat less than the TTL so we always refresh
        // *before* the minted credential actually expires.
        $expiresAt = time() + max(60, $ttl - 300);
        self::writeCache($iceServers, $expiresAt);

        return $iceServers;
    }

    private static function readCache(): ?array
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires_at'], $data['ice_servers'])) {
            return null;
        }
        if ($data['expires_at'] < time()) {
            return null; // expired — caller will re-fetch
        }
        return $data['ice_servers'];
    }

    private static function writeCache(array $iceServers, int $expiresAt): void
    {
        $payload = json_encode(['expires_at' => $expiresAt, 'ice_servers' => $iceServers]);
        @file_put_contents(self::cacheFile(), $payload, LOCK_EX);
    }

    /**
     * Whether this month's cumulative Cloudflare TURN usage (ingress +
     * egress bytes, from the 1st of the month through today, UTC) has
     * reached CF_TURN_MONTHLY_LIMIT_GB.
     *
     * The underlying byte figure comes from Cloudflare's GraphQL Analytics
     * API and is cached to disk for CF_TURN_USAGE_CHECK_TTL seconds — it's
     * a fairly heavy call and usage doesn't move fast enough to need
     * checking on every page load.
     *
     * Fails "open" (returns false, i.e. TURN stays enabled) if CF_ACCOUNT_ID
     * isn't set or the analytics query fails, so a misconfiguration or a
     * transient Cloudflare API/network hiccup can't silently break calling
     * for everyone. Check your error log for '[CloudflareTurn]' entries if
     * you want to confirm the usage check itself is actually working.
     */
    private static function monthlyUsageExceeded(): bool
    {
        $limitGb = defined('CF_TURN_MONTHLY_LIMIT_GB') ? (float) CF_TURN_MONTHLY_LIMIT_GB : 985;
        if ($limitGb <= 0) {
            return false; // limit disabled
        }
        if (!defined('CF_ACCOUNT_ID') || CF_ACCOUNT_ID === '') {
            return false;
        }

        $ttl = defined('CF_TURN_USAGE_CHECK_TTL') ? (int) CF_TURN_USAGE_CHECK_TTL : 600;
        $cacheFile = self::usageCacheFile();

        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data['checked_at'], $data['bytes_used'])
                && $data['checked_at'] > time() - $ttl) {
                return ($data['bytes_used'] / 1073741824) >= $limitGb;
            }
        }

        $bytesUsed = self::fetchMonthlyUsageBytes();
        if ($bytesUsed === null) {
            return false; // fetch failed — fail open, see docblock above
        }

        @file_put_contents($cacheFile, json_encode([
            'checked_at' => time(),
            'bytes_used' => $bytesUsed,
        ]), LOCK_EX);

        return ($bytesUsed / 1073741824) >= $limitGb;
    }

    /**
     * Queries Cloudflare's GraphQL Analytics API for total TURN
     * ingress+egress bytes from the 1st of the current month (UTC) through
     * today. Returns null on any failure.
     */
    private static function fetchMonthlyUsageBytes(): ?int
    {
        $token = (defined('CF_ANALYTICS_API_TOKEN') && CF_ANALYTICS_API_TOKEN !== '')
            ? CF_ANALYTICS_API_TOKEN
            : (defined('CF_TURN_API_TOKEN') ? CF_TURN_API_TOKEN : '');
        if ($token === '') {
            return null;
        }

        $query = <<<'GRAPHQL'
query TurnMonthlyUsage($accountTag: string, $dateFrom: Date, $dateTo: Date) {
  viewer {
    accounts(filter: { accountTag: $accountTag }) {
      callsTurnUsageAdaptiveGroups(
        limit: 10000
        filter: { date_geq: $dateFrom, date_leq: $dateTo }
      ) {
        sum {
          egressBytes
          ingressBytes
        }
      }
    }
  }
}
GRAPHQL;

        $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'query' => $query,
                'variables' => [
                    'accountTag' => CF_ACCOUNT_ID,
                    'dateFrom' => gmdate('Y-m-01'),
                    'dateTo' => gmdate('Y-m-d'),
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            error_log('[CloudflareTurn] Failed to fetch TURN usage (HTTP '
                . $status . '): ' . $curlErr);
            return null;
        }

        $data = json_decode($body, true);
        if (!empty($data['errors'])) {
            error_log('[CloudflareTurn] GraphQL errors fetching TURN usage: ' . json_encode($data['errors']));
            return null;
        }

        $groups = $data['data']['viewer']['accounts'][0]['callsTurnUsageAdaptiveGroups'] ?? null;
        if (!is_array($groups)) {
            error_log('[CloudflareTurn] Unexpected response shape from TURN analytics API: ' . $body);
            return null;
        }

        $total = 0;
        foreach ($groups as $row) {
            $total += (int) ($row['sum']['egressBytes'] ?? 0);
            $total += (int) ($row['sum']['ingressBytes'] ?? 0);
        }
        return $total;
    }
}
