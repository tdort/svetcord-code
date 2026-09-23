<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::bootSession();
$user = Auth::currentUser();
if ($user === null) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discordish</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
<?php if (defined('SHUTDOWN_ENABLED') && SHUTDOWN_ENABLED): ?>
<div class="shutdown-overlay" id="shutdown-overlay">
  <div class="shutdown-card">
    <div class="shutdown-globe">🌍</div>
    <h1><?php echo htmlspecialchars(SHUTDOWN_TITLE); ?></h1>
    <p class="shutdown-message"><?php echo nl2br(htmlspecialchars(SHUTDOWN_MESSAGE)); ?></p>
    <div class="shutdown-countdown" id="shutdown-countdown">
      <div class="shutdown-countdown-unit"><span id="sd-days">--</span><label>dní</label></div>
      <div class="shutdown-countdown-unit"><span id="sd-hours">--</span><label>hod</label></div>
      <div class="shutdown-countdown-unit"><span id="sd-mins">--</span><label>min</label></div>
      <div class="shutdown-countdown-unit"><span id="sd-secs">--</span><label>sek</label></div>
    </div>
    <p class="shutdown-sub"><?php echo htmlspecialchars(SHUTDOWN_FOOTER_NOTE); ?></p>
    <button type="button" class="btn-secondary" id="shutdown-ack-btn">Rozumím</button>
  </div>
</div>
<div class="shutdown-banner" id="shutdown-banner" hidden>
  <span>🌍 Světcord se brzy natrvalo vypíná — <strong id="shutdown-banner-countdown">--</strong> zbývá.</span>
  <button type="button" id="shutdown-banner-more-btn">Zobrazit více</button>
</div>
<script>
(function() {
  var SHUTDOWN_AT_MS = <?php echo (int) (strtotime(SHUTDOWN_AT) * 1000); ?>;
  var ACK_KEY = 'svetcord_shutdown_ack_date';
  var overlay = document.getElementById('shutdown-overlay');
  var banner = document.getElementById('shutdown-banner');
  var todayStr = new Date().toDateString();

  function formatUnits(ms) {
    if (ms <= 0) return { days: 0, hours: 0, mins: 0, secs: 0, over: true };
    var totalSecs = Math.floor(ms / 1000);
    return {
      days: Math.floor(totalSecs / 86400),
      hours: Math.floor((totalSecs % 86400) / 3600),
      mins: Math.floor((totalSecs % 3600) / 60),
      secs: totalSecs % 60,
      over: false,
    };
  }

  function tick() {
    var remaining = SHUTDOWN_AT_MS - Date.now();
    var u = formatUnits(remaining);

    if (u.over) {
      document.getElementById('shutdown-countdown').innerHTML =
        '<p class="shutdown-message" style="margin:0;">Světcord je nyní vypnutý.</p>';
      document.getElementById('shutdown-banner-countdown').textContent = 'teď';
      return;
    }

    document.getElementById('sd-days').textContent = u.days;
    document.getElementById('sd-hours').textContent = String(u.hours).padStart(2, '0');
    document.getElementById('sd-mins').textContent = String(u.mins).padStart(2, '0');
    document.getElementById('sd-secs').textContent = String(u.secs).padStart(2, '0');

    var bannerText = u.days > 0 ? (u.days + ' d ' + u.hours + ' h') : (u.hours + ' h ' + u.mins + ' min');
    document.getElementById('shutdown-banner-countdown').textContent = bannerText;
  }

  tick();
  setInterval(tick, 1000);

  function showBannerOnly() {
    overlay.hidden = true;
    banner.hidden = false;
  }

  // Show the full overlay once per day; otherwise go straight to the slim banner.
  if (localStorage.getItem(ACK_KEY) === todayStr) {
    showBannerOnly();
  }

  document.getElementById('shutdown-ack-btn').addEventListener('click', function() {
    localStorage.setItem(ACK_KEY, todayStr);
    showBannerOnly();
  });
  document.getElementById('shutdown-banner-more-btn').addEventListener('click', function() {
    overlay.hidden = false;
    banner.hidden = true;
  });
})();
</script>
<?php endif; ?>
<?php if (defined('MAINTENANCE_ENABLED') && MAINTENANCE_ENABLED): ?>
<div class="maintenance-overlay" id="maintenance-overlay">
  <div class="maintenance-card">
    <div class="maintenance-icon">🛠️</div>
    <h1><?php echo htmlspecialchars(MAINTENANCE_TITLE); ?></h1>
    <p class="maintenance-message"><?php echo nl2br(htmlspecialchars(MAINTENANCE_MESSAGE)); ?></p>
    <div class="maintenance-countdown" id="maintenance-countdown">
      <div class="maintenance-countdown-unit"><span id="mt-days">--</span><label>dní</label></div>
      <div class="maintenance-countdown-unit"><span id="mt-hours">--</span><label>hod</label></div>
      <div class="maintenance-countdown-unit"><span id="mt-mins">--</span><label>min</label></div>
      <div class="maintenance-countdown-unit"><span id="mt-secs">--</span><label>sek</label></div>
    </div>
    <p class="maintenance-sub"><?php echo htmlspecialchars(MAINTENANCE_FOOTER_NOTE); ?></p>
    <button type="button" class="btn-secondary" id="maintenance-ack-btn">Rozumím</button>
  </div>
</div>
<div class="maintenance-banner" id="maintenance-banner" hidden>
  <span>🛠️ Plánovaná údržba za <strong id="maintenance-banner-countdown">--</strong>.</span>
  <button type="button" id="maintenance-banner-more-btn">Zobrazit více</button>
</div>
<script>
(function() {
  var MAINTENANCE_AT_MS = <?php echo (int) (strtotime(MAINTENANCE_AT) * 1000); ?>;
  var ACK_KEY = 'svetcord_maintenance_ack_date';
  var overlay = document.getElementById('maintenance-overlay');
  var banner = document.getElementById('maintenance-banner');
  var todayStr = new Date().toDateString();

  function formatUnits(ms) {
    if (ms <= 0) return { days: 0, hours: 0, mins: 0, secs: 0, over: true };
    var totalSecs = Math.floor(ms / 1000);
    return {
      days: Math.floor(totalSecs / 86400),
      hours: Math.floor((totalSecs % 86400) / 3600),
      mins: Math.floor((totalSecs % 3600) / 60),
      secs: totalSecs % 60,
      over: false,
    };
  }

  function tick() {
    var remaining = MAINTENANCE_AT_MS - Date.now();
    var u = formatUnits(remaining);

    if (u.over) {
      document.getElementById('maintenance-countdown').innerHTML =
        '<p class="maintenance-message" style="margin:0;">Údržba právě probíhá.</p>';
      document.getElementById('maintenance-banner-countdown').textContent = 'probíhá';
      return;
    }

    document.getElementById('mt-days').textContent = u.days;
    document.getElementById('mt-hours').textContent = String(u.hours).padStart(2, '0');
    document.getElementById('mt-mins').textContent = String(u.mins).padStart(2, '0');
    document.getElementById('mt-secs').textContent = String(u.secs).padStart(2, '0');

    var bannerText = u.days > 0 ? (u.days + ' d ' + u.hours + ' h') : (u.hours + ' h ' + u.mins + ' min');
    document.getElementById('maintenance-banner-countdown').textContent = bannerText;
  }

  tick();
  setInterval(tick, 1000);

  function showBannerOnly() {
    overlay.hidden = true;
    banner.hidden = false;
  }

  // Show the full overlay once per day; otherwise go straight to the slim banner.
  if (localStorage.getItem(ACK_KEY) === todayStr) {
    showBannerOnly();
  }

  document.getElementById('maintenance-ack-btn').addEventListener('click', function() {
    localStorage.setItem(ACK_KEY, todayStr);
    showBannerOnly();
  });
  document.getElementById('maintenance-banner-more-btn').addEventListener('click', function() {
    overlay.hidden = false;
    banner.hidden = true;
  });
})();
</script>
<?php endif; ?>
<?php if (!empty($user['is_banned'])): ?>
<div class="suspension-banner" id="suspension-banner">
  <span>Your account is suspended. Some actions are disabled — check your account standing to see why.</span>
  <button type="button" id="suspension-banner-btn">Account Standing</button>
</div>
<?php endif; ?>
<div id="app" class="app-shell">

  <!-- Server rail -->
  <nav class="server-rail" id="server-rail">
    <button class="server-icon home-icon active" id="dm-home-btn" title="Direct Messages">DM</button>
    <div class="rail-divider"></div>
    <div id="server-list"></div>
    <button class="server-icon add-server-btn" id="add-server-btn" title="Add a server">+</button>
    <button class="server-icon join-server-btn" id="join-server-btn" title="Join a server">↗</button>
  </nav>

  <!-- Tapping this (mobile only) closes the channel/DM drawer -->
  <div class="mobile-backdrop" id="mobile-backdrop"></div>

  <!-- Secondary sidebar: channel list OR DM list -->
  <aside class="sidebar" id="app-sidebar">
    <div class="sidebar-header" id="sidebar-header">Direct Messages</div>
    <div class="sidebar-scroll" id="sidebar-content"></div>
    <div class="user-panel">
      <img id="my-avatar" class="avatar avatar-sm" src="" alt="">
      <div class="user-panel-info">
        <div class="user-panel-name" id="my-username"></div>
        <div class="user-panel-status">Online</div>
      </div>
      <?php if (!empty($user['is_admin'])): ?>
      <button id="admin-panel-btn" title="Admin Panel">🛡</button>
      <?php endif; ?>
      <button id="quests-btn" title="Nitro &amp; Quests">⚡</button>
      <button id="settings-btn" title="User Settings">⚙</button>
      <input type="file" id="avatar-input" accept="image/*" hidden>
      <input type="file" id="banner-input" accept="image/*" hidden>
      <button id="logout-btn" title="Log out">⏻</button>
    </div>
  </aside>

  <!-- Main content -->
  <main class="main-panel">
    <header class="channel-header" id="channel-header">
      <button class="mobile-menu-btn" id="mobile-menu-btn" title="Menu" aria-label="Open channel list">☰</button>
      <span id="channel-header-title">Select a channel</span>
      <div class="header-actions" id="header-actions"></div>
    </header>

    <div class="call-bar" id="call-bar" hidden title="Click to return to your call">
      <span class="call-bar-status" id="call-bar-status"></span>
      <span class="call-bar-warning" id="call-bar-warning" hidden title="No working TURN server is configured (see config/config.php), so this connection can't get past your network's NAT/firewall.">⚠️ Connection trouble</span>
      <div class="call-bar-avatars" id="call-bar-avatars"></div>
      <span class="call-bar-chevron">›</span>
    </div>

    <div class="messages-scroll" id="messages-scroll">
      <div class="empty-state" id="empty-state">
        <h2>No conversation selected</h2>
        <p>Pick a server &amp; channel, or a DM, from the left.</p>
      </div>
    </div>

    <div class="typing-indicator" id="typing-indicator" hidden></div>
    <form class="composer" id="composer" hidden>
      <input type="file" id="attachment-input" hidden>
      <div class="composer-attachment-preview" id="composer-attachment-preview" hidden></div>
      <div class="composer-input-row">
        <button type="button" id="attachment-btn" class="composer-icon-btn" title="Attach a file">📎</button>
        <input type="text" id="composer-input" placeholder="Message" maxlength="4000" autocomplete="off">
        <button type="submit">Send</button>
      </div>
    </form>
  </main>

  <!-- Member list (server view only) -->
  <aside class="member-list" id="member-list" hidden>
    <div class="member-list-header">Members</div>
    <div id="member-list-content"></div>
  </aside>
</div>

<!-- Incoming call toast -->
<div class="call-toast" id="call-toast" hidden>
  <img class="avatar avatar-sm" id="call-toast-avatar" src="" alt="">
  <div class="call-toast-info">
    <div class="call-toast-title" id="call-toast-title"></div>
    <div class="call-toast-sub">Incoming call</div>
  </div>
  <div class="call-toast-actions">
    <button type="button" class="btn-secondary" id="call-toast-decline">Decline</button>
    <button type="button" class="btn-primary" id="call-toast-accept">Accept</button>
  </div>
</div>

<!-- Hidden audio sinks for remote call participants -->
<div id="call-audio-sinks" hidden></div>

<!-- Modals -->
<div class="modal-overlay" id="modal-overlay" hidden>
  <div class="modal" id="modal-content"></div>
</div>


<script>
window.CURRENT_USER = <?php echo json_encode($user); ?>;
window.ICE_CONFIG = {
  // Cloudflare's mixed STUN+TURN server list (with short-lived credentials),
  // or null if CF_TURN_KEY_ID isn't configured — app.js falls back to
  // turnUrls/turnUsername/turnCredential below in that case.
  iceServers: <?php
    require_once __DIR__ . '/../includes/CloudflareTurn.php';
    echo json_encode(CloudflareTurn::getIceServers());
  ?>,
  turnUrls: <?php echo json_encode(TURN_URLS); ?>,
  turnUsername: <?php echo json_encode(TURN_USERNAME); ?>,
  turnCredential: <?php echo json_encode(TURN_CREDENTIAL); ?>,
};
</script>
<script src="assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
</body>
</html>