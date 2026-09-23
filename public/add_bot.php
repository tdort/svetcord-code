<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::bootSession();
$loggedIn = Auth::isLoggedIn();
$botId = (int) ($_GET['bot_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discordish — Add Bot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
  <div class="auth-card" id="add-bot-card">
    <p class="modal-sub">Loading…</p>
  </div>

<script>
const botId = <?php echo json_encode($botId) ?>;
const loggedIn = <?php echo json_encode($loggedIn) ?>;
const card = document.getElementById('add-bot-card');

async function apiGet(path) {
  const res = await fetch(`../api/${path}`, { credentials: 'same-origin' });
  return res.json();
}
async function apiPost(path, body) {
  const res = await fetch(`../api/${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  return res.json();
}
function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

async function init() {
  if (!botId) {
    card.innerHTML = '<h1>Invalid link</h1><p class="modal-sub">This add-bot link is missing a bot id.</p>';
    return;
  }
  if (!loggedIn) {
    card.innerHTML = `
      <h1>Log in to continue</h1>
      <p class="modal-sub">You need to be logged in to add a bot to one of your servers.</p>
      <a href="index.php"><button type="button" class="btn-primary" style="width:100%;">Log In</button></a>
      <p class="auth-sub" style="margin-top:14px;">Come back to this link after logging in.</p>
    `;
    return;
  }

  const infoJson = await apiGet(`bots.php?action=public_info&bot_id=${botId}`);
  if (!infoJson.success) {
    card.innerHTML = `<h1>Bot not found</h1><p class="modal-sub">${escapeHtml(infoJson.error || 'This bot link is invalid.')}</p>`;
    return;
  }
  const bot = infoJson.bot;

  const serversJson = await apiGet(`bots.php?action=addable_servers&bot_id=${botId}`);
  const servers = serversJson.success ? serversJson.servers : [];

  const serverOptions = servers.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');

  card.innerHTML = `
    <h1>🤖 Add ${escapeHtml(bot.username)}</h1>
    <p class="modal-sub">Created by ${escapeHtml(bot.owner_username)}. It'll join as a regular member with your server's default role — adjust its permissions afterward from Server Settings → Roles, or set channel-specific overrides.</p>
    ${servers.length === 0 ? `
      <p class="modal-sub">No servers to add it to — you need <strong>Manage Server</strong> permission on a server it isn't already in.</p>
      <a href="app.php"><button type="button" class="btn-secondary" style="width:100%;">Back to Discordish</button></a>
    ` : `
      <label>Add to server</label>
      <select id="server-select">${serverOptions}</select>
      <div id="add-bot-error" class="auth-error" hidden></div>
      <div id="add-bot-success" class="auth-error" style="background:rgba(45,212,167,.12);border-color:var(--green);color:var(--green);" hidden></div>
      <button type="button" class="btn-primary" id="add-bot-btn" style="width:100%; margin-top:10px;">Add Bot</button>
    `}
  `;

  const btn = document.getElementById('add-bot-btn');
  if (btn) {
    btn.addEventListener('click', async () => {
      const serverId = parseInt(document.getElementById('server-select').value, 10);
      btn.disabled = true;
      const json = await apiPost('bots.php?action=add_to_server', { bot_id: botId, server_id: serverId });
      if (!json.success) {
        const err = document.getElementById('add-bot-error');
        err.textContent = json.error;
        err.hidden = false;
        btn.disabled = false;
        return;
      }
      document.getElementById('add-bot-success').textContent = `Added! ${bot.username} is now in that server.`;
      document.getElementById('add-bot-success').hidden = false;
      btn.remove();
      document.getElementById('server-select').disabled = true;
    });
  }
}

init();
</script>
</body>
</html>
