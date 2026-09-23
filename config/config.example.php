<?php
/**
 * Central configuration.
 * Copy this file's values to match your local environment.
 */

// --- Database -----------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'discord_clone');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// --- App ------------------------------------------------------------------
define('APP_NAME', 'Světcord');
// Base URL WITHOUT trailing slash, e.g. http://localhost:8000
define('BASE_URL', '');

// --- Uploads ---------------------------------------------------------------
define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('UPLOAD_URL', '/uploads');
define('MAX_UPLOAD_BYTES', 4 * 1024 * 1024); // 4MB
define('ALLOWED_IMAGE_TYPES', ['image/png', 'image/jpeg', 'image/gif', 'image/webp']);

// --- Session ----------------------------------------------------------------
define('SESSION_NAME', 'discordish_session');

// --- Outgoing mail (password reset emails) -----------------------------------
// PHP's built-in mail() needs a configured MTA and basically never works on
// local dev stacks (XAMPP/WAMP/MAMP/Laragon) out of the box. For local dev,
// set MAIL_TRANSPORT to 'smtp' and point it at a free testing inbox like
// https://mailtrap.io (sign up, create an inbox, copy its SMTP creds below —
// emails land in a web inbox instead of real mailboxes, perfect for testing)
// or at Gmail with an App Password (https://myaccount.google.com/apppasswords,
// host smtp.gmail.com, port 587, encryption tls). Leave MAIL_TRANSPORT as
// 'mail' on real hosting that already has a working mail server.
define('MAIL_TRANSPORT', 'smtp'); // 'mail' (PHP mail()) or 'smtp'
define('MAIL_FROM_ADDRESS', 'no-reply@retroforge.xyz');
define('MAIL_FROM_NAME', APP_NAME);
define('PASSWORD_RESET_TOKEN_TTL_MINUTES', 60);

// Only used when MAIL_TRANSPORT is 'smtp'.
define('SMTP_HOST', 'smtp.resend.com');       // e.g. 'sandbox.smtp.mailtrap.io' or 'smtp.gmail.com'
define('SMTP_PORT', 587);      // 587 = STARTTLS (most common), 465 = SSL
define('SMTP_USERNAME', 'resend');
define('SMTP_PASSWORD', 'your-smtp-password-here');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' (STARTTLS), 'ssl', or 'none'

// --- Misc --------------------------------------------------------------------
define('MESSAGES_PAGE_SIZE', 50);

// --- WebRTC calling (STUN/TURN) ----------------------------------------------
// STUN alone is enough when both callers have a plain public IP, but it fails
// for most real users — anyone behind symmetric NAT, a corporate network, or
// mobile carrier NAT. Without a working TURN relay, those calls get stuck
// forever in the ICE "checking" state with no audio, because the browsers
// simply can never find a route to each other.
//
// Two ways to configure this — CF_TURN_* takes priority if both are set:
//
// 1) Cloudflare Realtime TURN (recommended — 1,000 GB/month free).
//    Create a TURN key at https://dash.cloudflare.com/?to=/:account/calls
//    then fill in the Key ID + API Token below. includes/CloudflareTurn.php
//    uses these to mint short-lived credentials on the fly (cached to disk
//    until they're close to expiring), rather than a fixed username/password.
define('CF_TURN_KEY_ID', '');
define('CF_TURN_API_TOKEN', '');
define('CF_TURN_TTL', 86400); // seconds a minted credential stays valid (24h)

// --- Cloudflare TURN monthly usage cap --------------------------------------
// The free tier is 1,000 GB/month (ingress+egress combined, across SFU+TURN).
// To make sure we never accidentally get billed, includes/CloudflareTurn.php
// checks cumulative usage-to-date for the current calendar month via
// Cloudflare's GraphQL Analytics API, and once it reaches CF_TURN_MONTHLY_LIMIT_GB
// it stops minting/handing out Cloudflare TURN credentials until the month
// resets — calls fall back to TURN_URLS/STUN below in the meantime.
//
// Find your Account ID on the right-hand sidebar of any page in the
// Cloudflare dashboard (https://dash.cloudflare.com/).
define('CF_ACCOUNT_ID', ''); // required for the usage check to run at all
// API token used for the usage check. Needs the "Account Analytics" Read
// permission (Cloudflare dashboard > My Profile > API Tokens). Leave empty
// to reuse CF_TURN_API_TOKEN above, but note that token typically only has
// the "Calls" Edit permission and will NOT work for analytics queries unless
// you've also granted it Account Analytics Read.
define('CF_ANALYTICS_API_TOKEN', '');
define('CF_TURN_MONTHLY_LIMIT_GB', 985); // 15 GB safety buffer below the 1,000 GB free tier
define('CF_TURN_USAGE_CHECK_TTL', 600); // seconds between usage re-checks (10 min)

// 2) A static TURN provider/self-hosted coturn (fallback if CF_TURN_KEY_ID
//    is left empty). Get one at:
//    - https://www.metered.ca/tools/openrelay/  (free tier; use their Turn
//      Credentials API to get a urls/username/credential set)
//    - https://www.twilio.com/docs/stun-turn     (free trial credits)
//    - or self-host with coturn: https://github.com/coturn/coturn
//
// Leave both TURN_URLS and CF_TURN_KEY_ID empty to fall back to STUN-only —
// calls will still work between people on the same network or with
// permissive NATs, but many real-world pairs will fail to connect.
define('TURN_URLS', []); // e.g. ['turn:your.turn.server:3478', 'turn:your.turn.server:443?transport=tcp']
define('TURN_USERNAME', '');
define('TURN_CREDENTIAL', '');

// --- Site shutdown notice ---------------------------------------------------
// Turn this on to show every logged-in user a full-screen farewell notice
// with a live countdown to SHUTDOWN_AT. They can dismiss it down to a slim
// top banner (reappears once per day until the date passes), and reopen the
// full notice from that banner at any time. Turn SHUTDOWN_ENABLED back to
// false at any time to remove it completely — this doesn't actually take
// the site offline by itself, it's just the announcement.
define('SHUTDOWN_ENABLED', false);
define('SHUTDOWN_AT', '2026-09-23 18:00:00'); // server-local time (see date_default_timezone_set below)
define('SHUTDOWN_TITLE', 'Světcord končí.');
define('SHUTDOWN_MESSAGE',
    "Po dlouhé cestě přichází čas se rozloučit. Světcord dnes definitivně končí.\n\n" .
    "Chceme moc poděkovat úplně každému z vás — za zprávy psané pozdě v noci, za hovory, " .
    "které trvaly hodiny, za smích, hádky, memy i chvíle, kdy jste se tu jen tak zastavili, " .
    "abyste nebyli sami. Tenhle server nebyl jen o kódu a serverech — byl o vás a o tom, co jste " .
    "si tu spolu vybudovali.\n\nDěkujeme, že jste byli součástí tohoto světa. I když se tahle " .
    "kapitola uzavírá, vzpomínky zůstávají."
);
define('SHUTDOWN_FOOTER_NOTE', 'Doporučujeme si stáhnout vše důležité do té doby.');

// --- Maintenance notice ------------------------------------------------------
// Same idea as the shutdown notice above, but for a temporary maintenance
// window instead of a permanent goodbye — different styling (amber, wrench
// icon) so the two are never visually confused. Independent on/off switch,
// so you can run this one without the shutdown notice (or vice versa).
define('MAINTENANCE_ENABLED', false);
define('MAINTENANCE_AT', '2026-08-05 9:00:00'); // when the maintenance window starts (server-local time)
define('MAINTENANCE_TITLE', 'Plánovaná údržba Světcordu');
define('MAINTENANCE_MESSAGE',
    "Světcord bude na krátkou dobu odstaven kvůli plánované údržbě. " .
    "Během tohoto okna nebude server dostupný — omlouváme se za nepříjemnosti " .
    "a děkujeme za trpělivost.\n\nJakmile bude údržba dokončena, vše poběží jako obvykle."
);
define('MAINTENANCE_FOOTER_NOTE', 'Doporučujeme si před začátkem údržby dokončit rozepsané zprávy a hovory.');

// --- Site-wide lockout (real maintenance mode) ------------------------------
// Much stronger than the notices above: when enabled, this blocks EVERY
// page and every API request for everyone except the allow-listed
// IPs/hosts below — the rest of the site stays fully usable for you while
// showing everyone else a simple "down for maintenance" page.
//
// This works because every single entry point (public/index.php,
// public/app.php, and every file in api/) requires includes/Auth.php as
// its very first line, which requires Database.php, which requires this
// file — so the check below runs before anything else on every request.
define('LOCKOUT_ENABLED', false);

// A port number isn't part of an IP address, so if "192.168.1.10:8080" is
// the address your own browser is on, that's checked against
// LOCKOUT_ALLOWED_HOSTS (matches the Host header, i.e. what's typed in the
// address bar) below. If it's meant as "my computer's IP is 192.168.1.10"
// (the :8080 just being whatever port your browser happened to use), that's
// checked against LOCKOUT_ALLOWED_IPS instead (port ignored, since client
// ports are random each connection). Both are enabled by default so it
// works either way — trim whichever you don't need.
define('LOCKOUT_ALLOWED_IPS', ['127.0.0.1']);
define('LOCKOUT_ALLOWED_HOSTS', ['127.0.0.1:4565']);

define('LOCKOUT_TITLE', 'Světcord skončil.');
define('LOCKOUT_MESSAGE', 'Děkuji za účast v tomto projektu a děkuji za vzpomínky. 
- Dort');

function lockout_client_ip(): string
{
    // Behind a reverse proxy, the real client IP is the first hop in
    // X-Forwarded-For rather than REMOTE_ADDR (which would be the proxy).
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function lockout_enforce(): void
{
    if (!defined('LOCKOUT_ENABLED') || !LOCKOUT_ENABLED) {
        return;
    }

    $clientIp = lockout_client_ip();
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $allowed = in_array($clientIp, LOCKOUT_ALLOWED_IPS, true) || in_array($host, LOCKOUT_ALLOWED_HOSTS, true);
    if ($allowed) {
        return;
    }

    http_response_code(503);
    header('Retry-After: 3600');

    $isApiRequest = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
    if ($isApiRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => LOCKOUT_MESSAGE]);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $title = htmlspecialchars(LOCKOUT_TITLE);
    $message = htmlspecialchars(LOCKOUT_MESSAGE);
    echo <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #0b0e17;
    color: #e6e9f0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 24px;
    box-sizing: border-box;
    text-align: center;
  }
  .card { max-width: 420px; }
  .icon { font-size: 52px; margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 12px; }
  p { font-size: 14px; line-height: 1.6; color: #9aa2b3; margin: 0; }
</style>
</head>
<body>
  <div class="card">
    <div class="icon">🚧</div>
    <h1>{$title}</h1>
    <p>{$message}</p>
  </div>
</body>
</html>
HTML;
    exit;
}

lockout_enforce();

error_reporting(E_ALL);
ini_set('display_errors', '1'); // set to '1' during local development if needed
date_default_timezone_set('CET');