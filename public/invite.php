<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::bootSession();
$loggedIn = Auth::isLoggedIn();
$code = trim((string) ($_GET['code'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discordish — Join Server</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
  <div class="auth-card" id="invite-card">
    <p class="modal-sub">Loading…</p>
  </div>

<script>
const code = <?php echo json_encode($code) ?>;
const loggedIn = <?php echo json_encode($loggedIn) ?>;
const card = document.getElementById('invite-card');

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
  if (!code) {
    card.innerHTML = '<h1>Invalid invite</h1><p class="modal-sub">This link is missing an invite code.</p>';
    return;
  }
  if (!loggedIn) {
    card.innerHTML = `
      <h1>Log in to join</h1>
      <p class="modal-sub">You need an account to join this server.</p>
      <a href="index.php"><button type="button" class="btn-primary" style="width:100%;">Log In / Sign Up</button></a>
      <p class="auth-sub" style="margin-top:14px;">Come back to this link after logging in.</p>
    `;
    return;
  }

  const json = await apiGet(`servers.php?action=invite_preview&code=${encodeURIComponent(code)}`);
  if (!json.success) {
    card.innerHTML = `<h1>Invite invalid</h1><p class="modal-sub">${escapeHtml(json.error || 'This invite has expired or been revoked.')}</p>`;
    return;
  }
  const { server, member_count, already_member } = json;

  const initials = server.name.split(/\s+/).map(w => w[0]).join('').slice(0, 3).toUpperCase();
  card.innerHTML = `
    ${server.icon_url
      ? `<img src="${server.icon_url}" alt="" style="width:72px; height:72px; border-radius:24px; display:block; margin:0 auto 14px; object-fit:cover;">`
      : `<div style="width:72px; height:72px; border-radius:24px; margin:0 auto 14px; background:var(--bg-lighter); color:var(--text-muted); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:20px;">${escapeHtml(initials)}</div>`}
    <h1 style="text-align:center;">${escapeHtml(server.name)}</h1>
    <p class="modal-sub" style="text-align:center;">${member_count} member${member_count === 1 ? '' : 's'}</p>
    ${already_member ? `
      <a href="https://retroforge.xyz/app.php"><button type="button" class="btn-primary" style="width:100%;">Open Discordish</button></a>
      <p class="auth-sub" style="margin-top:10px;">You're already in this server.</p>
    ` : `
      <div id="join-error" class="auth-error" hidden></div>
      <button type="button" class="btn-primary" id="join-btn" style="width:100%;">Join Server</button>
    `}
  `;

  const joinBtn = document.getElementById('join-btn');
  if (joinBtn) {
    joinBtn.addEventListener('click', async () => {
      joinBtn.disabled = true;
      joinBtn.textContent = 'Joining…';
      const joinJson = await apiPost('servers.php?action=join', { invite_code: code });
      if (!joinJson.success) {
        const err = document.getElementById('join-error');
        err.textContent = joinJson.error;
        err.hidden = false;
        joinBtn.disabled = false;
        joinBtn.textContent = 'Join Server';
        return;
      }
      window.location.href = 'app.php';
    });
  }
}

init();
</script>
</body>
</html>
