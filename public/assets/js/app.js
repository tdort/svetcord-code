'use strict';

/* ============================================================
   API helpers
   ============================================================ */
const API = '../api';

async function apiGet(path) {
  const res = await fetch(`${API}/${path}`, { credentials: 'same-origin' });
  return res.json();
}

async function apiPost(path, body) {
  const res = await fetch(`${API}/${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  return res.json();
}

async function apiUpload(path, formData) {
  const res = await fetch(`${API}/${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    body: formData,
  });
  return res.json();
}

/* ============================================================
   Global state
   ============================================================ */
const state = {
  me: window.CURRENT_USER,
  view: 'dm',            // 'dm' | 'server' | 'friends'
  servers: [],
  channels: [],
  categories: [],
  conversations: [],
  activeServerId: null,
  activeChannelId: null,
  activeChannel: null,
  activeConversationId: null,
  activeConversationUser: null,
  lastMessageId: 0,
  pollTimer: null,
  myPermissions: 0,
  PERM_FLAGS: {},
  voiceStatus: {},
  voiceRosterTimer: null,
  friendsTab: 'online',   // 'online' | 'all' | 'pending' | 'add'
  friendsData: null,      // { friends, incoming, outgoing, groups } once loaded
  currentMembers: [],     // last-loaded member list for the active server, used for @mention autocomplete
  pendingAttachment: null, // { url, name, type } once uploaded, attached to the next sent message
  customEmojiMap: {},      // name -> image_url, across all servers the user is in
  unreadChannels: {},      // channel_id -> count
  unreadConversations: {}, // conversation_id -> count
  unreadServers: {},       // server_id -> true
  typingTimer: null,
  typingPingedAt: 0,
};

const PERMISSIONS = {
  VIEW_CHANNELS: 1 << 0,
  SEND_MESSAGES: 1 << 1,
  MANAGE_MESSAGES: 1 << 2,
  MANAGE_CHANNELS: 1 << 3,
  MANAGE_ROLES: 1 << 4,
  KICK_MEMBERS: 1 << 5,
  BAN_MEMBERS: 1 << 6,
  MANAGE_SERVER: 1 << 7,
  ADMINISTRATOR: 1 << 8,
};

const PERMISSION_LABELS = {
  VIEW_CHANNELS: ['View Channels', 'Members can see this server\'s channels.'],
  SEND_MESSAGES: ['Send Messages', 'Members can send messages in text channels.'],
  MANAGE_MESSAGES: ['Manage Messages', 'Delete messages sent by other members.'],
  MANAGE_CHANNELS: ['Manage Channels', 'Create, edit, and delete channels.'],
  MANAGE_ROLES: ['Manage Roles', 'Create roles and assign them to members.'],
  KICK_MEMBERS: ['Kick Members', 'Remove members from the server.'],
  BAN_MEMBERS: ['Ban Members', 'Permanently ban members (reserved for future use).'],
  MANAGE_SERVER: ['Manage Server', 'Rename the server and change its icon.'],
  ADMINISTRATOR: ['Administrator', 'Grants every permission, always.'],
};

function hasPerm(flag) {
  return (state.myPermissions & PERMISSIONS.ADMINISTRATOR) !== 0 ||
         (state.myPermissions & flag) === flag;
}

/* ============================================================
   DOM refs
   ============================================================ */
const el = {
  serverList: document.getElementById('server-list'),
  dmHomeBtn: document.getElementById('dm-home-btn'),
  addServerBtn: document.getElementById('add-server-btn'),
  joinServerBtn: document.getElementById('join-server-btn'),
  sidebarHeader: document.getElementById('sidebar-header'),
  sidebarContent: document.getElementById('sidebar-content'),
  myAvatar: document.getElementById('my-avatar'),
  myUsername: document.getElementById('my-username'),
  settingsBtn: document.getElementById('settings-btn'),
  questsBtn: document.getElementById('quests-btn'),
  adminPanelBtn: document.getElementById('admin-panel-btn'),
  avatarInput: document.getElementById('avatar-input'),
  bannerInput: document.getElementById('banner-input'),
  logoutBtn: document.getElementById('logout-btn'),
  channelHeader: document.getElementById('channel-header-title'),
  headerActions: document.getElementById('header-actions'),
  messagesScroll: document.getElementById('messages-scroll'),
  emptyState: document.getElementById('empty-state'),
  composer: document.getElementById('composer'),
  composerInput: document.getElementById('composer-input'),
  attachmentInput: document.getElementById('attachment-input'),
  attachmentBtn: document.getElementById('attachment-btn'),
  attachmentPreview: document.getElementById('composer-attachment-preview'),
  typingIndicator: document.getElementById('typing-indicator'),
  memberList: document.getElementById('member-list'),
  memberListContent: document.getElementById('member-list-content'),
  modalOverlay: document.getElementById('modal-overlay'),
  modalContent: document.getElementById('modal-content'),
  callBar: document.getElementById('call-bar'),
  callBarStatus: document.getElementById('call-bar-status'),
  callBarAvatars: document.getElementById('call-bar-avatars'),
  callBarWarning: document.getElementById('call-bar-warning'),
  callToast: document.getElementById('call-toast'),
  callToastAvatar: document.getElementById('call-toast-avatar'),
  callToastTitle: document.getElementById('call-toast-title'),
  callToastAccept: document.getElementById('call-toast-accept'),
  callToastDecline: document.getElementById('call-toast-decline'),
  callAudioSinks: document.getElementById('call-audio-sinks'),
  suspensionBanner: document.getElementById('suspension-banner'),
  suspensionBannerBtn: document.getElementById('suspension-banner-btn'),
  mobileMenuBtn: document.getElementById('mobile-menu-btn'),
  mobileBackdrop: document.getElementById('mobile-backdrop'),
  appSidebar: document.getElementById('app-sidebar'),
};

/* ============================================================
   Init
   ============================================================ */
init();

async function init() {
  el.myUsername.textContent = state.me.username;
  el.myAvatar.src = avatarUrl(state.me.avatar_url, state.me.username);

  bindStaticEvents();
  if (state.me.is_banned) applySuspendedRestrictions();
  await loadServers();
  await loadConversations();
  loadCustomEmojiMap();
  showDMHome();
  if (window.matchMedia('(max-width: 768px)').matches) openMobileSidebar();
  startIncomingCallWatcher();
  startUnreadPolling();
}

/**
 * Visually and functionally restricts the UI for a suspended account.
 * The server still enforces this independently (see Auth::requireNotBanned
 * in the API) — this just keeps the UI from offering actions that will
 * be rejected anyway, and steers the user toward their standing.
 */
function applySuspendedRestrictions() {
  [el.addServerBtn, el.joinServerBtn].forEach(btn => {
    btn.disabled = true;
    btn.title = 'Disabled while your account is suspended';
    btn.classList.add('disabled');
  });
  if (el.suspensionBannerBtn) {
    el.suspensionBannerBtn.addEventListener('click', openAccountStandingModal);
  }
}

/** Disables the message composer if suspended, or (in a server channel) if the current user can't send messages there. */
function updateComposerState() {
  const submitBtn = el.composer.querySelector('button[type="submit"]');
  if (state.me.is_banned) {
    el.composerInput.disabled = true;
    el.composerInput.placeholder = 'You can’t send messages while suspended';
    submitBtn.disabled = true;
    return;
  }
  if (state.view === 'server' && state.activeChannel && state.activeChannel.can_send === false) {
    el.composerInput.disabled = true;
    el.composerInput.placeholder = `You don't have permission to send messages in #${state.activeChannel.name}`;
    submitBtn.disabled = true;
    return;
  }
  el.composerInput.disabled = false;
  el.composerInput.placeholder = 'Message';
  submitBtn.disabled = false;
}

/**
 * Mobile layout: the channel/DM list becomes a slide-in drawer instead
 * of a permanent column (see the max-width:768px CSS). These helpers
 * are harmless no-ops on desktop widths, since the CSS only applies
 * the transform inside that media query.
 */
function openMobileSidebar() {
  el.appSidebar.classList.add('mobile-open');
  el.mobileBackdrop.classList.add('visible');
}
function closeMobileSidebar() {
  el.appSidebar.classList.remove('mobile-open');
  el.mobileBackdrop.classList.remove('visible');
}
function toggleMobileSidebar() {
  if (el.appSidebar.classList.contains('mobile-open')) closeMobileSidebar();
  else openMobileSidebar();
}

function bindStaticEvents() {
  el.dmHomeBtn.addEventListener('click', () => { showDMHome(); openMobileSidebar(); });
  el.addServerBtn.addEventListener('click', openCreateServerModal);
  el.joinServerBtn.addEventListener('click', openJoinServerModal);
  el.mobileMenuBtn.addEventListener('click', toggleMobileSidebar);
  el.mobileBackdrop.addEventListener('click', closeMobileSidebar);
  el.logoutBtn.addEventListener('click', async () => {
    await apiPost('auth.php?action=logout');
    window.location.href = 'index.php';
  });
  el.settingsBtn.addEventListener('click', openUserSettingsModal);
  el.questsBtn.addEventListener('click', openQuestsModal);
  if (el.adminPanelBtn) {
    el.adminPanelBtn.addEventListener('click', () => openAdminPanelModal());
  }
  el.callBar.addEventListener('click', returnToActiveCall);
  el.callToastAccept.addEventListener('click', acceptIncomingCall);
  el.callToastDecline.addEventListener('click', declineIncomingCall);
  el.myAvatar.style.cursor = 'pointer';
  el.myAvatar.addEventListener('click', openUserSettingsModal);
  el.avatarInput.addEventListener('change', handleAvatarUpload);
  el.bannerInput.addEventListener('change', handleBannerUpload);
  el.composer.addEventListener('submit', handleSendMessage);
  el.composerInput.addEventListener('input', handleMentionAutocompleteInput);
  el.composerInput.addEventListener('input', pingTyping);
  el.composerInput.addEventListener('keydown', handleMentionAutocompleteKeydown);
  el.attachmentBtn.addEventListener('click', () => el.attachmentInput.click());
  el.attachmentInput.addEventListener('change', handleAttachmentSelected);
  el.modalOverlay.addEventListener('click', (e) => {
    if (e.target === el.modalOverlay) closeModal();
  });
}

/* ============================================================
   Helpers
   ============================================================ */
/** Full shareable /invite/CODE URL for a given invite code. */
/* ============================================================
   Context menus (right-click on desktop, long-press on mobile)
   ============================================================ */
function openContextMenu(x, y, items) {
  document.querySelectorAll('.context-menu').forEach(m => m.remove());
  const menu = document.createElement('div');
  menu.className = 'context-menu';
  items.forEach((item) => {
    if (item.separator) {
      const sep = document.createElement('div');
      sep.className = 'context-menu-sep';
      menu.appendChild(sep);
      return;
    }
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'context-menu-item' + (item.danger ? ' danger' : '');
    btn.textContent = item.label;
    btn.addEventListener('click', () => {
      menu.remove();
      item.onClick();
    });
    menu.appendChild(btn);
  });
  document.body.appendChild(menu);

  const menuWidth = 200;
  let left = x;
  const maxLeft = window.scrollX + document.documentElement.clientWidth - menuWidth - 8;
  left = Math.max(8, Math.min(left, maxLeft));
  const maxTop = window.scrollY + document.documentElement.clientHeight - 8;
  let top = Math.min(y, maxTop - 40);
  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;

  setTimeout(() => {
    document.addEventListener('click', function onDocClick(e) {
      if (!menu.contains(e.target)) {
        menu.remove();
        document.removeEventListener('click', onDocClick);
      }
    });
    document.addEventListener('contextmenu', function onDocCtx() {
      menu.remove();
      document.removeEventListener('contextmenu', onDocCtx);
    }, { once: true });
  }, 0);
}

/**
 * Wires both right-click (desktop) and long-press (mobile, ~500ms) to open
 * a context menu on `targetEl`. `buildItems` is called fresh each time so
 * the menu always reflects current state/permissions.
 */
function bindContextMenu(targetEl, buildItems) {
  targetEl.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    openContextMenu(e.pageX, e.pageY, buildItems());
  });

  let pressTimer = null;
  let longPressed = false;
  targetEl.addEventListener('touchstart', (e) => {
    longPressed = false;
    const touch = e.touches[0];
    pressTimer = setTimeout(() => {
      longPressed = true;
      if (navigator.vibrate) navigator.vibrate(15);
      openContextMenu(touch.pageX, touch.pageY, buildItems());
    }, 500);
  }, { passive: true });
  targetEl.addEventListener('touchmove', () => clearTimeout(pressTimer));
  targetEl.addEventListener('touchend', (e) => {
    clearTimeout(pressTimer);
    if (longPressed) e.preventDefault(); // swallow the click a long-press would otherwise also trigger
  });
  targetEl.addEventListener('touchcancel', () => clearTimeout(pressTimer));
}

function buildServerContextMenuItems(server) {
  const isOwner = server.owner_id === state.me.id;
  const items = server.is_disabled ? [] : [
    { label: '⚙ Server Settings', onClick: async () => {
        if (state.activeServerId !== server.id) await openServer(server.id);
        openServerSettingsModal(server);
      } },
    { label: '🔗 Copy Invite Link', onClick: () => {
        navigator.clipboard.writeText(inviteLink(server.invite_code));
      } },
  ];
  if (!isOwner || server.is_disabled) {
    items.push({ separator: true });
    items.push({
      label: '🚪 Leave Server',
      danger: true,
      onClick: async () => {
        if (!confirm(`Leave "${server.name}"? You'll need a new invite to rejoin.`)) return;
        const json = await apiPost('servers.php?action=leave', { server_id: server.id });
        if (json.success) {
          await loadServers();
          if (state.activeServerId === server.id) showDMHome();
        } else {
          alert(json.error);
        }
      },
    });
  }
  return items;
}

function inviteLink(code) {
  return `${location.origin}${location.pathname.split('/').slice(0, -1).join('/')}/invite/${code}`;
}

function avatarUrl(url, username) {
  if (url) return url;
  // Generated placeholder avatar (initial letter on brand color) via inline SVG data URI.
  const initial = (username || '?').charAt(0).toUpperCase();
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64">
    <rect width="64" height="64" fill="#5865f2"/>
    <text x="32" y="42" font-size="28" text-anchor="middle" fill="white" font-family="sans-serif">${initial}</text>
  </svg>`;
  return `data:image/svg+xml;base64,${btoa(svg)}`;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

const BADGE_META = {
  owner: { file: 'owner.png', label: 'Owner' },
  co_owner: { file: 'co_owner.png', label: 'Co-Owner' },
  staff: { file: 'staff.png', label: 'Staff' },
  trusted: { emoji: '✅', label: 'Trusted User' },
};

/** Small badge icon (Owner/Co-Owner/Staff/Trusted) for next to a username, or '' if none/unrecognized. */
function badgeIconHtml(siteBadge) {
  const meta = BADGE_META[siteBadge];
  if (!meta) return '';
  if (meta.file) {
    return `<img class="site-badge-icon" src="assets/img/badges/${meta.file}" alt="${meta.label}" title="${meta.label}">`;
  }
  return `<span class="site-badge-icon site-badge-emoji" title="${meta.label}">${meta.emoji}</span>`;
}

function isNitroActive(nitroUntil) {
  if (!nitroUntil) return false;
  return new Date(nitroUntil.replace(' ', 'T') + 'Z').getTime() > Date.now();
}

/** Small ⚡ badge for next to a username if their Nitro is currently active, or '' otherwise. */
/** ' nitro-name' if active, else '' — appended to a username element's class list. */
function nitroActiveClass(nitroUntil) {
  if (!nitroUntil) return '';
  return isNitroActive(nitroUntil) ? ' nitro-name' : '';
}

function nitroBadgeHtml(nitroUntil) {
  if (!nitroUntil) return '';
  const until = new Date(nitroUntil.replace(' ', 'T') + 'Z');
  if (until.getTime() <= Date.now()) return '';
  return `<span class="nitro-badge" title="Nitro until ${until.toLocaleDateString()}">⚡</span>`;
}

/** Small "BOT" tag for next to a bot account's username, or '' otherwise. */
function botTagHtml(isBot) {
  if (!isBot) return '';
  return `<span class="bot-tag" title="Automated bot account">BOT</span>`;
}

function formatTime(isoString) {
  const d = new Date(isoString.replace(' ', 'T') + 'Z');
  const now = new Date();
  const sameDay = d.toDateString() === now.toDateString();
  const timeStr = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  if (sameDay) return `Today at ${timeStr}`;
  return `${d.toLocaleDateString()} ${timeStr}`;
}

function stopPolling() {
  if (state.pollTimer) {
    clearInterval(state.pollTimer);
    state.pollTimer = null;
  }
  stopTypingPolling();
}

/* ============================================================
   Typing indicators
   ============================================================ */
function currentTypingTarget() {
  if (state.view === 'server' && state.activeChannel && state.activeChannel.type === 'text') {
    return { kind: 'channel', targetId: state.activeChannelId };
  }
  if (state.view === 'dm' && state.activeConversationId) {
    return { kind: 'dm', targetId: state.activeConversationId };
  }
  return null;
}

/** Called on every composer keystroke; throttled so it only actually pings the server every ~2.5s. */
function pingTyping() {
  const target = currentTypingTarget();
  if (!target || !el.composerInput.value.trim()) return;
  const now = Date.now();
  if (now - state.typingPingedAt < 2500) return;
  state.typingPingedAt = now;
  apiPost('typing.php?action=ping', { kind: target.kind, target_id: target.targetId });
}

function startTypingPolling(kind, targetId) {
  stopTypingPolling();
  const poll = async () => {
    const json = await apiGet(`typing.php?action=list&kind=${kind}&target_id=${targetId}`);
    if (json.success) renderTypingIndicator(json.typing);
  };
  poll();
  state.typingTimer = setInterval(poll, 2500);
}

function stopTypingPolling() {
  if (state.typingTimer) {
    clearInterval(state.typingTimer);
    state.typingTimer = null;
  }
  el.typingIndicator.hidden = true;
  el.typingIndicator.textContent = '';
}

function renderTypingIndicator(typingUsers) {
  if (!typingUsers || typingUsers.length === 0) {
    el.typingIndicator.hidden = true;
    el.typingIndicator.textContent = '';
    return;
  }
  const names = typingUsers.map(u => u.username);
  let text;
  if (names.length === 1) text = `${names[0]} is typing...`;
  else if (names.length === 2) text = `${names[0]} and ${names[1]} are typing...`;
  else text = `${names.slice(0, 2).join(', ')}, and others are typing...`;
  el.typingIndicator.textContent = text;
  el.typingIndicator.hidden = false;
}

/* ============================================================
   Unread badges
   ============================================================ */
function startUnreadPolling() {
  refreshUnreadSummary();
  setInterval(refreshUnreadSummary, 10000);
}

async function refreshUnreadSummary() {
  const json = await apiGet('messages.php?action=unread_summary');
  if (!json.success) return;
  state.unreadChannels = json.channels || {};
  state.unreadConversations = json.conversations || {};
  state.unreadServers = json.servers || {};
  applyUnreadBadgesToDom();
}

/** Pure DOM patch from already-fetched state.unread* — safe to call after any sidebar/rail rebuild with no network round trip. */
function applyUnreadBadgesToDom() {
  document.querySelectorAll('.channel-item[data-channel-id]').forEach((item) => {
    const count = state.unreadChannels[item.dataset.channelId] || 0;
    const badge = item.querySelector('.unread-badge');
    if (!badge) return;
    if (count > 0 && state.activeChannelId !== parseInt(item.dataset.channelId, 10)) {
      badge.hidden = false;
      badge.textContent = count > 99 ? '99+' : String(count);
    } else {
      badge.hidden = true;
    }
  });

  document.querySelectorAll('.dm-item[data-conversation-id]').forEach((item) => {
    const count = state.unreadConversations[item.dataset.conversationId] || 0;
    const badge = item.querySelector('.unread-badge');
    if (!badge) return;
    if (count > 0 && state.activeConversationId !== parseInt(item.dataset.conversationId, 10)) {
      badge.hidden = false;
      badge.textContent = count > 99 ? '99+' : String(count);
    } else {
      badge.hidden = true;
    }
  });

  document.querySelectorAll('.server-icon[data-server-id]').forEach((btn) => {
    const dot = btn.querySelector('.server-unread-dot');
    if (!dot) return;
    const hasUnread = !!state.unreadServers[btn.dataset.serverId];
    const isOpen = state.view === 'server' && state.activeServerId === parseInt(btn.dataset.serverId, 10);
    dot.hidden = !hasUnread || isOpen;
  });
}

async function markChannelRead(channelId) {
  state.unreadChannels[channelId] = 0;
  applyUnreadBadgesToDom();
  await apiPost('messages.php?action=mark_read', { channel_id: channelId });
}

async function markConversationRead(conversationId) {
  state.unreadConversations[conversationId] = 0;
  applyUnreadBadgesToDom();
  await apiPost('dm.php?action=mark_read', { conversation_id: conversationId });
}

/* ============================================================
   Servers (rail + sidebar)
   ============================================================ */
async function loadServers() {
  const json = await apiGet('servers.php?action=list');
  if (json.success) {
    state.servers = json.servers;
    renderServerRail();
  }
}

function renderServerRail() {
  el.serverList.innerHTML = '';
  state.servers.forEach((server) => {
    const btn = document.createElement('button');
    btn.className = 'server-icon'
      + (state.view === 'server' && state.activeServerId === server.id ? ' active' : '')
      + (server.is_disabled ? ' server-icon-disabled' : '');
    btn.title = server.is_disabled ? `${server.name} (disabled)` : server.name;
    btn.dataset.serverId = server.id;
    if (server.icon_url) {
      const img = document.createElement('img');
      img.src = server.icon_url;
      btn.appendChild(img);
    } else {
      btn.textContent = server.name.split(/\s+/).map(w => w[0]).join('').slice(0, 3).toUpperCase();
    }
    const dot = document.createElement('span');
    dot.className = 'server-unread-dot';
    dot.hidden = true;
    btn.appendChild(dot);
    btn.addEventListener('click', () => openServer(server.id));
    bindContextMenu(btn, () => buildServerContextMenuItems(server));
    el.serverList.appendChild(btn);
  });
  el.dmHomeBtn.classList.toggle('active', state.view === 'dm' || state.view === 'friends');
  applyUnreadBadgesToDom();
}

async function openServer(serverId) {
  stopPolling();
  state.view = 'server';
  state.activeServerId = serverId;
  state.activeChannelId = null;

  const json = await apiGet(`servers.php?action=get&server_id=${serverId}`);
  if (!json.success) {
    alert(json.error);
    return;
  }
  state.myPermissions = json.server.my_permissions;
  const currentServer = json.server;

  renderServerRail();

  if (currentServer.is_disabled) {
    showServerDisabledMain(currentServer);
    openMobileSidebar();
    return;
  }

  el.memberList.hidden = false;
  await loadChannelsForSidebar(serverId, currentServer);
  await loadMembers(serverId);
  startVoiceRosterPolling(serverId);
  openMobileSidebar();
}

/** Takeover screen shown instead of channels/messages when an admin has disabled this server. */
function showServerDisabledMain(server) {
  el.sidebarHeader.innerHTML = '';
  const title = document.createElement('span');
  title.textContent = server.name;
  el.sidebarHeader.appendChild(title);
  el.sidebarContent.innerHTML = '<p class="profile-empty" style="padding:16px;">This server has been disabled.</p>';
  el.memberList.hidden = true;
  el.composer.hidden = true;

  el.messagesScroll.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'empty-state server-disabled-state';
  wrap.innerHTML = `
    <h2>🚫 Server Disabled</h2>
    <p>"${escapeHtml(server.name)}" has been disabled by an admin and can no longer be used.</p>
    ${server.disabled_reason ? `<p class="server-disabled-reason">Reason: ${escapeHtml(server.disabled_reason)}</p>` : ''}
    <button type="button" class="btn-danger" id="disabled-server-leave-btn">🚪 Leave Server</button>
  `;
  el.messagesScroll.appendChild(wrap);

  document.getElementById('disabled-server-leave-btn').addEventListener('click', async () => {
    if (!confirm(`Leave "${server.name}"? This disabled server will be removed from your server list.`)) return;
    const json = await apiPost('servers.php?action=leave', { server_id: server.id });
    if (!json.success) { alert(json.error); return; }
    await loadServers();
    showDMHome();
  });
}

async function loadChannelsForSidebar(serverId, serverInfo) {
  const json = await apiGet(`channels.php?action=list&server_id=${serverId}`);
  if (!json.success) return;
  state.channels = json.channels;
  state.categories = json.categories || [];

  el.sidebarHeader.innerHTML = '';
  const title = document.createElement('span');
  title.textContent = serverInfo.name;
  el.sidebarHeader.appendChild(title);

  const canManageChannels = hasPerm(PERMISSIONS.MANAGE_CHANNELS) && !state.me.is_banned;
  if (canManageChannels) {
    const addCategoryBtn = document.createElement('button');
    addCategoryBtn.textContent = '🗂+';
    addCategoryBtn.title = 'New category';
    addCategoryBtn.style.color = 'var(--text-muted)';
    addCategoryBtn.style.fontSize = '12px';
    addCategoryBtn.addEventListener('click', () => openCreateCategoryModal(serverInfo.id));
    el.sidebarHeader.appendChild(addCategoryBtn);
  }

  if ((hasPerm(PERMISSIONS.MANAGE_SERVER) || hasPerm(PERMISSIONS.MANAGE_ROLES) || hasPerm(PERMISSIONS.MANAGE_CHANNELS)) && !state.me.is_banned) {
    const settingsBtn = document.createElement('button');
    settingsBtn.textContent = '⚙';
    settingsBtn.title = 'Server settings';
    settingsBtn.style.color = 'var(--text-muted)';
    settingsBtn.addEventListener('click', () => openServerSettingsModal(serverInfo));
    el.sidebarHeader.appendChild(settingsBtn);
  }

  el.sidebarContent.innerHTML = '';

  const uncategorized = state.channels.filter(c => !c.category_id);
  const textChannels = uncategorized.filter(c => c.type === 'text');
  const voiceChannels = uncategorized.filter(c => c.type === 'voice');

  el.sidebarContent.appendChild(renderChannelGroup('Text Channels', textChannels, 'text', serverInfo,
    serverInfo.hide_text_header ? { hideLabel: true } : { defaultGroupType: 'text' }));
  el.sidebarContent.appendChild(renderChannelGroup('Voice Channels', voiceChannels, 'voice', serverInfo,
    serverInfo.hide_voice_header ? { hideLabel: true } : { defaultGroupType: 'voice' }));

  state.categories.forEach((cat) => {
    const catChannels = state.channels.filter(c => c.category_id === cat.id);
    el.sidebarContent.appendChild(renderChannelGroup(cat.name, catChannels, 'text', serverInfo, { categoryId: cat.id }));
  });

  // Auto-select first text channel (from anywhere — uncategorized or in a category)
  const firstText = state.channels.find(c => c.type === 'text');
  if (firstText && !state.activeChannelId) {
    selectChannel(firstText);
  } else if (!firstText) {
    showEmptyMain('No text channels', 'Create a text channel to start chatting.');
  }

  applyUnreadBadgesToDom();
}

function openCreateCategoryModal(serverId) {
  openModal(`
    <h2>Create Category</h2>
    <form id="create-category-form">
      <label>Category Name</label>
      <input type="text" name="name" required maxlength="100" autofocus placeholder="e.g. Announcements">
      <div id="create-category-error" class="auth-error" hidden></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="cancel-create-category">Cancel</button>
        <button type="submit" class="btn-primary">Create</button>
      </div>
    </form>
  `);
  document.getElementById('cancel-create-category').addEventListener('click', closeModal);
  document.getElementById('create-category-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = e.target.name.value.trim();
    const json = await apiPost('channels.php?action=category_create', { server_id: serverId, name });
    if (!json.success) {
      const err = document.getElementById('create-category-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    closeModal();
    const serverInfo = state.servers.find(s => s.id === serverId);
    await loadChannelsForSidebar(serverId, serverInfo);
  });
}

function renderChannelGroup(label, channels, defaultType, serverInfo, opts) {
  opts = opts || {};
  const wrap = document.createElement('div');
  const canManage = hasPerm(PERMISSIONS.MANAGE_CHANNELS) && !state.me.is_banned;

  if (!opts.hideLabel) {
    const labelRow = document.createElement('div');
    labelRow.className = 'channel-group-label';
    labelRow.style.display = 'flex';
    labelRow.style.justifyContent = 'space-between';
    labelRow.style.alignItems = 'center';
    labelRow.innerHTML = `<span>${escapeHtml(label)}</span>`;

    if (canManage) {
      const btnGroup = document.createElement('span');

      if (opts.categoryId) {
        const upBtn = document.createElement('button');
        upBtn.textContent = '▲';
        upBtn.title = 'Move category up';
        upBtn.style.color = 'var(--text-muted)';
        upBtn.style.marginRight = '2px';
        upBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const json = await apiPost('channels.php?action=category_move', { category_id: opts.categoryId, direction: 'up' });
          if (!json.success) { alert(json.error); return; }
          await loadChannelsForSidebar(serverInfo.id, serverInfo);
        });
        btnGroup.appendChild(upBtn);

        const downBtn = document.createElement('button');
        downBtn.textContent = '▼';
        downBtn.title = 'Move category down';
        downBtn.style.color = 'var(--text-muted)';
        downBtn.style.marginRight = '4px';
        downBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const json = await apiPost('channels.php?action=category_move', { category_id: opts.categoryId, direction: 'down' });
          if (!json.success) { alert(json.error); return; }
          await loadChannelsForSidebar(serverInfo.id, serverInfo);
        });
        btnGroup.appendChild(downBtn);

        const renameBtn = document.createElement('button');
        renameBtn.textContent = '✏️';
        renameBtn.title = `Rename category "${label}"`;
        renameBtn.style.marginRight = '4px';
        renameBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const newName = prompt('Rename category:', label);
          if (!newName || !newName.trim() || newName.trim() === label) return;
          const json = await apiPost('channels.php?action=category_rename', { category_id: opts.categoryId, name: newName.trim() });
          if (!json.success) { alert(json.error); return; }
          await loadChannelsForSidebar(serverInfo.id, serverInfo);
        });
        btnGroup.appendChild(renameBtn);
      }

      if (opts.categoryId || opts.defaultGroupType) {
        const delGroupBtn = document.createElement('button');
        delGroupBtn.textContent = '🗑';
        delGroupBtn.style.color = 'var(--text-muted)';
        delGroupBtn.style.marginRight = '4px';
        if (opts.categoryId) {
          delGroupBtn.title = `Delete category "${label}" (channels move to uncategorized)`;
          delGroupBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm(`Delete category "${label}"? Its channels will become uncategorized.`)) return;
            const json = await apiPost('channels.php?action=category_delete', { category_id: opts.categoryId });
            if (!json.success) { alert(json.error); return; }
            await loadChannelsForSidebar(serverInfo.id, serverInfo);
          });
        } else {
          delGroupBtn.title = `Delete "${label}" header (channels stay, header disappears — restorable from Server Settings)`;
          delGroupBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm(`Delete the "${label}" header? Its channels stay, just without this label. You can bring it back from Server Settings.`)) return;
            const json = await apiPost('channels.php?action=set_default_group_hidden', {
              server_id: serverInfo.id, type: opts.defaultGroupType, hidden: true,
            });
            if (!json.success) { alert(json.error); return; }
            if (opts.defaultGroupType === 'text') serverInfo.hide_text_header = 1;
            else serverInfo.hide_voice_header = 1;
            await loadChannelsForSidebar(serverInfo.id, serverInfo);
          });
        }
        btnGroup.appendChild(delGroupBtn);
      }

      const addBtn = document.createElement('button');
      addBtn.textContent = '+';
      addBtn.style.color = 'var(--text-muted)';
      addBtn.style.fontWeight = '700';
      addBtn.addEventListener('click', () => openCreateChannelModal(serverInfo.id, defaultType, opts.categoryId || null));
      btnGroup.appendChild(addBtn);

      labelRow.appendChild(btnGroup);
    }
    wrap.appendChild(labelRow);
  }

  channels.forEach((ch) => {
    const item = document.createElement('div');
    item.className = 'channel-item' + (state.activeChannelId === ch.id ? ' active' : '');
    item.dataset.channelId = ch.id;
    item.innerHTML = `<span class="${ch.type === 'text' ? 'hash' : 'voice-icon'}">${ch.type === 'text' ? '#' : '🔊'}</span><span>${escapeHtml(ch.name)}</span><span class="unread-badge" hidden></span>`;
    item.addEventListener('click', () => selectChannel(ch));

    if (canManage) {
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'channel-delete-btn';
      editBtn.textContent = '✏️';
      editBtn.title = `Edit #${ch.name}`;
      editBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openEditChannelModal(serverInfo, ch);
      });
      item.appendChild(editBtn);

      const permBtn = document.createElement('button');
      permBtn.type = 'button';
      permBtn.className = 'channel-delete-btn';
      permBtn.textContent = '🔒';
      permBtn.title = `Edit permissions for #${ch.name}`;
      permBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openChannelPermissionsModal(serverInfo, ch);
      });
      item.appendChild(permBtn);

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'channel-delete-btn';
      delBtn.textContent = '🗑';
      delBtn.title = `Delete #${ch.name}`;
      delBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (!confirm(`Delete #${ch.name}? This can't be undone.`)) return;
        const json = await apiPost('channels.php?action=delete', { channel_id: ch.id });
        if (!json.success) { alert(json.error); return; }
        if (state.activeChannelId === ch.id) {
          state.activeChannelId = null;
          state.activeChannel = null;
        }
        await loadChannelsForSidebar(serverInfo.id, serverInfo);
      });
      item.appendChild(delBtn);
    }

    bindContextMenu(item, () => {
      const items = [{ label: `Copy #${ch.name}`, onClick: () => navigator.clipboard.writeText(ch.name) }];
      if (canManage) {
        items.push({ separator: true });
        items.push({ label: '✏️ Edit Channel', onClick: () => openEditChannelModal(serverInfo, ch) });
        items.push({ label: '🔒 Permissions', onClick: () => openChannelPermissionsModal(serverInfo, ch) });
        items.push({
          label: '🗑 Delete Channel',
          danger: true,
          onClick: async () => {
            if (!confirm(`Delete #${ch.name}? This can't be undone.`)) return;
            const json = await apiPost('channels.php?action=delete', { channel_id: ch.id });
            if (!json.success) { alert(json.error); return; }
            if (state.activeChannelId === ch.id) {
              state.activeChannelId = null;
              state.activeChannel = null;
            }
            await loadChannelsForSidebar(serverInfo.id, serverInfo);
          },
        });
      }
      return items;
    });

    wrap.appendChild(item);

    if (ch.type === 'voice') {
      const roster = document.createElement('div');
      roster.className = 'voice-roster';
      roster.id = `voice-roster-${ch.id}`;
      wrap.appendChild(roster);
    }
  });

  return wrap;
}

/* ---------------- Voice channel rosters (who's connected) ---------------- */
function startVoiceRosterPolling(serverId) {
  if (state.voiceRosterTimer) clearInterval(state.voiceRosterTimer);
  refreshVoiceRoster(serverId);
  state.voiceRosterTimer = setInterval(() => {
    if (state.activeServerId === serverId) refreshVoiceRoster(serverId);
  }, 4000);
}

function stopVoiceRosterPolling() {
  if (state.voiceRosterTimer) {
    clearInterval(state.voiceRosterTimer);
    state.voiceRosterTimer = null;
  }
}

async function refreshVoiceRoster(serverId) {
  const json = await apiGet(`calls.php?action=channel_voice_status&server_id=${serverId}`);
  if (!json.success) return;
  state.voiceStatus = json.status;

  Object.entries(state.voiceStatus).forEach(([channelId, users]) => {
    const rosterEl = document.getElementById(`voice-roster-${channelId}`);
    if (!rosterEl) return;
    rosterEl.innerHTML = users.map(u => `
      <span class="voice-roster-user"><img class="avatar" src="${avatarUrl(u.avatar_url, u.username)}" data-speaking-id="${u.user_id}">${escapeHtml(u.username)}</span>
    `).join('');
  });

  if (state.activeChannel && state.activeChannel.type === 'voice' && state.activeChannel.server_id === serverId) {
    renderVoiceChannelMain(state.activeChannel);
  }
}

function selectChannel(channel) {
  state.activeChannelId = channel.id;
  state.activeChannel = channel;
  document.querySelectorAll('.channel-item').forEach(n => n.classList.remove('active'));
  loadChannelsForSidebar(state.activeServerId, state.servers.find(s => s.id === state.activeServerId) || { id: state.activeServerId, name: '' });
  closeMobileSidebar();

  el.channelHeader.textContent = channel.type === 'text' ? `# ${channel.name}` : `🔊 ${channel.name}`;
  el.headerActions.innerHTML = '';

  if (channel.type === 'voice') {
    el.emptyState.hidden = true;
    el.composer.hidden = true;
    stopPolling();
    renderVoiceChannelMain(channel);
    return;
  }

  el.composer.hidden = false;
  el.emptyState.hidden = true;
  updateComposerState();
  loadMessages(channel.id, true);
  startPollingChannel(channel.id);
  markChannelRead(channel.id);
  startTypingPolling('channel', channel.id);
}

function renderVoiceChannelMain(channel) {
  if (!state.activeChannel || state.activeChannel.id !== channel.id) return;

  const inThisCall = callRuntime.callId && callRuntime.kind === 'channel' && callRuntime.channelId === channel.id;
  const everyone = state.voiceStatus[channel.id] || [];
  const canDisconnectOthers = hasPerm(PERMISSIONS.KICK_MEMBERS);

  el.messagesScroll.innerHTML = '';
  const stage = document.createElement('div');
  stage.className = 'voice-stage';

  if (everyone.length === 0) {
    // Nobody connected — simple centered call-to-action, no tile grid needed.
    const empty = document.createElement('div');
    empty.className = 'empty-state';
    empty.innerHTML = `<h2>🔊 ${escapeHtml(channel.name)}</h2><p>No one is connected yet.</p>`;
    empty.appendChild(makeVoiceJoinBtn(channel));
    stage.appendChild(empty);
    el.messagesScroll.appendChild(stage);
    return;
  }

  const grid = document.createElement('div');
  grid.className = 'voice-tile-grid';
  grid.innerHTML = everyone.map(u => {
    const isMe = u.user_id === state.me.id;
    const showMuted = isMe && callRuntime.muted;
    return `
      <div class="voice-tile${isMe ? ' self' : ''}" style="background:${tileGradientFor(u.user_id)}">
        ${canDisconnectOthers && !isMe ? `
          <button type="button" class="voice-disconnect-btn" data-disconnect-user="${u.user_id}" data-disconnect-username="${escapeHtml(u.username)}" title="Disconnect ${escapeHtml(u.username)} from voice">✕</button>
        ` : ''}
        <img class="avatar" src="${avatarUrl(u.avatar_url, u.username)}" data-speaking-id="${u.user_id}">
        <span class="voice-tile-name">${showMuted ? ICON_MIC_OFF : ''}${escapeHtml(isMe ? 'You' : u.username)}</span>
      </div>
    `;
  }).join('');
  stage.appendChild(grid);

  if (inThisCall) {
    const controls = document.createElement('div');
    controls.className = 'voice-controls-bar';
    controls.innerHTML = `
      <button type="button" id="voice-stage-mute-btn" class="${callRuntime.muted ? 'muted' : ''}" title="${callRuntime.muted ? 'Unmute' : 'Mute'}">${callRuntime.muted ? ICON_MIC_OFF : ICON_MIC}</button>
      <button type="button" class="disabled-feature" disabled title="Video calls aren't supported">${ICON_VIDEO_OFF}</button>
      <button type="button" class="disabled-feature" disabled title="Screen share isn't supported">${ICON_SCREEN_OFF}</button>
      <button type="button" id="voice-stage-leave-btn" class="hangup-btn" title="Leave voice">${ICON_PHONE_OFF}</button>
    `;
    stage.appendChild(controls);
  } else {
    const joinWrap = document.createElement('div');
    joinWrap.className = 'voice-join-wrap';
    joinWrap.appendChild(makeVoiceJoinBtn(channel));
    stage.appendChild(joinWrap);
  }

  el.messagesScroll.appendChild(stage);

  stage.querySelectorAll('[data-disconnect-user]').forEach(dcBtn => {
    dcBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const targetUserId = parseInt(dcBtn.dataset.disconnectUser, 10);
      const targetUsername = dcBtn.dataset.disconnectUsername;
      disconnectVoiceUser(channel.id, targetUserId, targetUsername);
    });
  });

  if (inThisCall) {
    document.getElementById('voice-stage-mute-btn').addEventListener('click', toggleMute);
    document.getElementById('voice-stage-leave-btn').addEventListener('click', () => leaveCall());
  }
}

/** Shared "Join Voice" CTA builder used by both the empty and populated stage states. */
function makeVoiceJoinBtn(channel) {
  const btn = document.createElement('button');
  btn.className = 'voice-join-cta join';
  btn.innerHTML = `${ICON_PHONE} Join Voice`;
  if (state.me.is_banned) {
    btn.disabled = true;
    btn.title = 'Disabled while your account is suspended';
  } else {
    btn.addEventListener('click', () => joinChannelCall(channel.id, channel.name));
  }
  return btn;
}

// Deterministic tile background per user, cycling through a small palette
// so a channel's grid stays visually distinct without any per-user config.
const TILE_GRADIENTS = [
  'linear-gradient(160deg, #3d1f78, #7c3aed)',
  'linear-gradient(160deg, #0c2f52, #1a4a86)',
  'linear-gradient(160deg, #7a1f4d, #d63384)',
  'linear-gradient(160deg, #0f4a3a, #1fa37a)',
  'linear-gradient(160deg, #5a3a00, #d98324)',
  'linear-gradient(160deg, #2a1f5c, #4b2fae)',
];
function tileGradientFor(userId) {
  return TILE_GRADIENTS[Math.abs(userId) % TILE_GRADIENTS.length];
}

/** Moderator action: force a member out of this channel's active call. */
async function disconnectVoiceUser(channelId, targetUserId, targetUsername) {
  if (!confirm(`Disconnect ${targetUsername} from this voice channel?`)) return;
  const json = await apiPost('calls.php?action=disconnect_user', {
    channel_id: channelId,
    user_id: targetUserId,
  });
  if (!json.success) {
    alert(json.error || 'Failed to disconnect that user.');
    return;
  }
  if (state.activeServerId) refreshVoiceRoster(state.activeServerId);
}

function showEmptyMain(title, sub) {
  el.messagesScroll.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'empty-state';
  wrap.innerHTML = `<h2>${escapeHtml(title)}</h2><p>${escapeHtml(sub)}</p>`;
  el.messagesScroll.appendChild(wrap);
  el.composer.hidden = true;
}

/* ============================================================
   Channel messages (polling)
   ============================================================ */
async function loadMessages(channelId, replace) {
  const json = await apiGet(`messages.php?action=list&channel_id=${channelId}`);
  if (!json.success) return;
  if (replace) {
    el.messagesScroll.innerHTML = '';
    state.lastMessageId = 0;
  }
  json.messages.forEach(m => appendMessage(m, 'channel'));
  scrollToBottom();
}

function startPollingChannel(channelId) {
  stopPolling();
  state.pollTimer = setInterval(async () => {
    if (state.activeChannelId !== channelId) return;
    const wasNearBottom = isScrolledNearBottom();
    const json = await apiGet(`messages.php?action=list&channel_id=${channelId}&after_id=${state.lastMessageId}`);
    if (json.success && json.messages.length) {
      json.messages.forEach(m => appendMessage(m, 'channel'));
      if (wasNearBottom) scrollToBottom();
      markChannelRead(channelId);
    }
  }, 3000);
}

function appendMessage(m, kind) {
  const isMe = (m.user_id || m.sender_id) === state.me.id;
  const row = document.createElement('div');
  row.className = 'message-row';
  row.dataset.id = m.id;

  const avatar = document.createElement('img');
  avatar.className = 'avatar';
  avatar.src = avatarUrl(m.avatar_url, m.username);

  const body = document.createElement('div');
  body.className = 'message-body';

  const meta = document.createElement('div');
  meta.className = 'message-meta';
  meta.innerHTML = `<span class="message-author${nitroActiveClass(m.nitro_until)}" data-user-id="${m.user_id || m.sender_id}">${escapeHtml(m.username)}</span>${badgeIconHtml(m.site_badge)}${nitroBadgeHtml(m.nitro_until)}${botTagHtml(m.is_bot)}<span class="message-time">${formatTime(m.created_at)}</span>`;

  const content = document.createElement('div');
  content.className = 'message-content';
  content.innerHTML = m.content ? renderMessageContentHtml(m.content) : '';
  if (m.edited_at) {
    const tag = document.createElement('span');
    tag.className = 'edited-tag';
    tag.textContent = ' (edited)';
    content.appendChild(tag);
  }
  if (kind === 'channel' && messageMentionsMe(m.content)) {
    row.classList.add('mentions-me');
  }

  body.appendChild(meta);
  body.appendChild(content);

  if (m.attachment_url) {
    body.appendChild(renderAttachmentHtml(m.attachment_url, m.attachment_name, m.attachment_type));
  }

  if (kind === 'channel') {
    const reactionsRow = document.createElement('div');
    reactionsRow.className = 'reactions-row';
    body.appendChild(reactionsRow);
    renderReactions(reactionsRow, m.id, m.reactions || []);
  }

  row.appendChild(avatar);
  row.appendChild(body);

  const authorUserId = m.user_id || m.sender_id;
  const profileServerId = kind === 'channel' ? state.activeServerId : null;
  avatar.style.cursor = 'pointer';
  avatar.addEventListener('click', (e) => openUserProfilePopover(e.currentTarget, authorUserId, profileServerId));
  const authorSpan = meta.querySelector('.message-author');
  authorSpan.style.cursor = 'pointer';
  authorSpan.addEventListener('click', (e) => openUserProfilePopover(e.currentTarget, authorUserId, profileServerId));

  if (kind === 'channel') {
    const canEdit = isMe && !state.me.is_banned;
    const canDelete = (isMe || hasPerm(PERMISSIONS.MANAGE_MESSAGES)) && !state.me.is_banned;
    const canReport = !isMe && !state.me.is_banned;
    const canReact = !state.me.is_banned;
    if (canEdit || canDelete || canReport || canReact) {
      const actions = document.createElement('div');
      actions.className = 'message-actions';
      if (canReact) {
        const reactBtn = document.createElement('button');
        reactBtn.textContent = '😀';
        reactBtn.title = 'Add Reaction';
        reactBtn.addEventListener('click', (e) => openEmojiPicker(e.currentTarget, m.id));
        actions.appendChild(reactBtn);
      }
      if (canEdit) {
        const editBtn = document.createElement('button');
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => startEditingMessage(m.id, content, m.content));
        actions.appendChild(editBtn);
      }
      if (canReport) {
        const reportBtn = document.createElement('button');
        reportBtn.textContent = 'Report';
        reportBtn.addEventListener('click', () => openReportModal(authorUserId, m.username, m.id));
        actions.appendChild(reportBtn);
      }
      if (canDelete) {
        const delBtn = document.createElement('button');
        delBtn.textContent = 'Delete';
        delBtn.addEventListener('click', () => deleteMessage(m.id, row));
        actions.appendChild(delBtn);
      }
      row.appendChild(actions);
    }

    bindContextMenu(row, () => {
      const items = [{ label: '📋 Copy Text', onClick: () => navigator.clipboard.writeText(m.content) }];
      if (canReact) {
        REACTION_EMOJI.slice(0, 4).forEach((emoji) => {
          items.push({ label: `${emoji} React`, onClick: () => toggleReaction(m.id, emoji, row.querySelector('.reactions-row')) });
        });
      }
      if (canEdit || canReport || canDelete) items.push({ separator: true });
      if (canEdit) items.push({ label: '✏️ Edit', onClick: () => startEditingMessage(m.id, content, m.content) });
      if (canReport) items.push({ label: '🚩 Report', onClick: () => openReportModal(authorUserId, m.username, m.id) });
      if (canDelete) items.push({ label: '🗑 Delete', danger: true, onClick: () => deleteMessage(m.id, row) });
      return items;
    });

    state.lastMessageId = Math.max(state.lastMessageId, m.id);
  }

  el.messagesScroll.appendChild(row);
}

/** Escapes message text, then wraps @word / @everyone / @here tokens in a styled span and :emoji_name: tokens as inline custom-emoji images. Safe: escaping happens first, mention/emoji markup is built from already-escaped text. */
function renderMessageContentHtml(text) {
  const escaped = escapeHtml(text);
  const withMentions = escaped.replace(/(^|\s)@(everyone|here|[A-Za-z0-9_]{2,32})/g, (full, lead, name) => {
    const isSpecial = name === 'everyone' || name === 'here';
    return `${lead}<span class="mention${isSpecial ? ' mention-special' : ''}">@${name}</span>`;
  });
  return withMentions.replace(/:([a-z0-9_]{2,32}):/gi, (full, name) => {
    const url = state.customEmojiMap[name.toLowerCase()];
    return url ? `<img class="inline-emoji" src="${url}" alt=":${name}:" title=":${name}:">` : full;
  });
}

/** Renders an image preview or a download chip for a message's attachment, depending on type. */
function renderAttachmentHtml(url, name, type) {
  const wrap = document.createElement('div');
  wrap.className = 'message-attachment';
  if (type === 'image') {
    wrap.innerHTML = `<a href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${escapeHtml(name || 'attachment')}" loading="lazy"></a>`;
  } else {
    wrap.innerHTML = `
      <a class="attachment-file-chip" href="${url}" target="_blank" rel="noopener" download>
        <span class="attachment-file-icon">📄</span>
        <span class="attachment-name">${escapeHtml(name || 'file')}</span>
        <span class="attachment-download-icon">⬇</span>
      </a>
    `;
  }
  return wrap;
}

function messageMentionsMe(text) {
  if (!state.me || !state.me.username) return false;
  const re = new RegExp(`(^|\\s)@(everyone|here|${state.me.username.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})(?=\\s|$)`, 'i');
  return re.test(text);
}

function renderReactions(container, messageId, reactions) {
  container.innerHTML = '';
  if (!reactions.length) return;
  reactions.forEach((r) => {
    const pill = document.createElement('button');
    pill.type = 'button';
    pill.className = 'reaction-pill' + (r.reacted ? ' reacted' : '');
    pill.textContent = `${r.emoji} ${r.count}`;
    pill.addEventListener('click', () => toggleReaction(messageId, r.emoji, container));
    container.appendChild(pill);
  });
}

const REACTION_EMOJI = ['👍', '👎', '❤️', '😂', '😮', '😢', '🎉', '🔥', '👀', '✅'];

function openEmojiPicker(anchorBtn, messageId) {
  document.querySelectorAll('.emoji-picker-popup').forEach(p => p.remove());
  const popup = document.createElement('div');
  popup.className = 'emoji-picker-popup';
  REACTION_EMOJI.forEach((emoji) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = emoji;
    btn.addEventListener('click', () => {
      toggleReaction(messageId, emoji, anchorBtn.closest('.message-row').querySelector('.reactions-row'));
      popup.remove();
    });
    popup.appendChild(btn);
  });
  document.body.appendChild(popup);
  const rect = anchorBtn.getBoundingClientRect();
  popup.style.top = `${rect.bottom + window.scrollY + 4}px`;
  popup.style.left = `${rect.left + window.scrollX}px`;
  setTimeout(() => {
    document.addEventListener('click', function onDocClick(e) {
      if (!popup.contains(e.target) && e.target !== anchorBtn) {
        popup.remove();
        document.removeEventListener('click', onDocClick);
      }
    });
  }, 0);
}

async function toggleReaction(messageId, emoji, reactionsRowEl) {
  const json = await apiPost('messages.php?action=react', { message_id: messageId, emoji });
  if (!json.success) { alert(json.error); return; }

  // Reflect the toggle locally without a full re-fetch.
  const existing = Array.from(reactionsRowEl.querySelectorAll('.reaction-pill'))
    .find(p => p.textContent.startsWith(emoji));
  if (json.reacted) {
    if (existing) {
      const count = parseInt(existing.textContent.split(' ')[1], 10) + 1;
      existing.textContent = `${emoji} ${count}`;
      existing.classList.add('reacted');
    } else {
      const pill = document.createElement('button');
      pill.type = 'button';
      pill.className = 'reaction-pill reacted';
      pill.textContent = `${emoji} 1`;
      pill.addEventListener('click', () => toggleReaction(messageId, emoji, reactionsRowEl));
      reactionsRowEl.appendChild(pill);
    }
  } else if (existing) {
    const count = parseInt(existing.textContent.split(' ')[1], 10) - 1;
    if (count <= 0) {
      existing.remove();
    } else {
      existing.textContent = `${emoji} ${count}`;
      existing.classList.remove('reacted');
    }
  }
}

function startEditingMessage(messageId, contentEl, currentText) {
  const textarea = document.createElement('textarea');
  textarea.className = 'edit-message-textarea';
  textarea.value = currentText;
  contentEl.replaceWith(textarea);
  textarea.focus();
  textarea.setSelectionRange(textarea.value.length, textarea.value.length);

  let done = false;
  const finish = async (save) => {
    if (done) return;
    done = true;
    if (save) {
      const newText = textarea.value.trim();
      if (newText && newText !== currentText) {
        const json = await apiPost('messages.php?action=edit', { message_id: messageId, content: newText });
        if (!json.success) {
          alert(json.error);
          textarea.replaceWith(contentEl);
          return;
        }
        contentEl.innerHTML = renderMessageContentHtml(newText);
        const tag = document.createElement('span');
        tag.className = 'edited-tag';
        tag.textContent = ' (edited)';
        contentEl.appendChild(tag);
      }
    }
    textarea.replaceWith(contentEl);
  };

  textarea.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); finish(true); }
    else if (e.key === 'Escape') { finish(false); }
  });
  textarea.addEventListener('blur', () => finish(true));
}

async function deleteMessage(messageId, row) {
  if (!confirm('Delete this message?')) return;
  const json = await apiPost('messages.php?action=delete', { message_id: messageId });
  if (json.success) row.remove();
}

function openReportModal(reportedUserId, username, messageId) {
  openModal(`
    <h2>🚩 Report ${escapeHtml(username)}</h2>
    <p class="modal-sub">${messageId ? 'This report will include the message you reported.' : 'This will be sent to the site admins for review.'}</p>
    <label>What's going on?</label>
    <textarea id="report-reason" maxlength="500" rows="4" placeholder="Describe the issue..." autofocus></textarea>
    <div id="report-error" class="auth-error" hidden></div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="cancel-report-btn">Cancel</button>
      <button type="button" class="btn-primary" id="submit-report-btn">Submit Report</button>
    </div>
  `);
  document.getElementById('cancel-report-btn').addEventListener('click', closeModal);
  document.getElementById('submit-report-btn').addEventListener('click', async () => {
    const reason = document.getElementById('report-reason').value.trim();
    if (!reason) return;
    const json = await apiPost('reports.php?action=create', {
      reported_user_id: reportedUserId, reason, message_id: messageId || null,
    });
    if (!json.success) {
      const err = document.getElementById('report-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    closeModal();
  });
}

function scrollToBottom() {
  el.messagesScroll.scrollTop = el.messagesScroll.scrollHeight;
}

/** True if the user is at (or very near) the bottom of the message list already. */
function isScrolledNearBottom(threshold = 120) {
  const s = el.messagesScroll;
  return s.scrollHeight - s.scrollTop - s.clientHeight < threshold;
}

/* ============================================================
   @mention autocomplete
   ============================================================ */
let mentionAutocompleteState = { open: false, matches: [], triggerStart: -1 };

function handleMentionAutocompleteInput() {
  const input = el.composerInput;
  const cursor = input.selectionStart;
  const beforeCursor = input.value.slice(0, cursor);
  const match = beforeCursor.match(/(^|\s)@([A-Za-z0-9_]{0,32})$/);
  if (!match) {
    closeMentionAutocomplete();
    return;
  }
  const query = match[2].toLowerCase();
  const triggerStart = cursor - match[2].length - 1; // index of the '@'
  const pool = state.view === 'server' ? (state.currentMembers || []).map(m => m.username) : [];
  const names = [...['everyone', 'here'], ...pool].filter(n => n.toLowerCase().startsWith(query)).slice(0, 6);
  if (names.length === 0) {
    closeMentionAutocomplete();
    return;
  }
  mentionAutocompleteState = { open: true, matches: names, triggerStart };
  renderMentionAutocomplete(names);
}

function renderMentionAutocomplete(matches) {
  let popup = document.getElementById('mention-autocomplete-popup');
  if (!popup) {
    popup = document.createElement('div');
    popup.id = 'mention-autocomplete-popup';
    popup.className = 'mention-autocomplete-popup';
    el.composer.appendChild(popup);
  }
  popup.innerHTML = '';
  matches.forEach((name, i) => {
    const item = document.createElement('div');
    item.className = 'mention-autocomplete-item' + (i === 0 ? ' active' : '');
    item.textContent = `@${name}`;
    item.addEventListener('mousedown', (e) => {
      e.preventDefault(); // don't let the input lose focus before we insert
      selectMentionAutocomplete(name);
    });
    popup.appendChild(item);
  });
  popup.hidden = false;
}

function closeMentionAutocomplete() {
  mentionAutocompleteState = { open: false, matches: [], triggerStart: -1 };
  const popup = document.getElementById('mention-autocomplete-popup');
  if (popup) popup.hidden = true;
}

function selectMentionAutocomplete(name) {
  const input = el.composerInput;
  const cursor = input.selectionStart;
  const before = input.value.slice(0, mentionAutocompleteState.triggerStart);
  const after = input.value.slice(cursor);
  input.value = `${before}@${name} ${after}`;
  const newCursor = `${before}@${name} `.length;
  input.setSelectionRange(newCursor, newCursor);
  input.focus();
  closeMentionAutocomplete();
}

function handleMentionAutocompleteKeydown(e) {
  if (!mentionAutocompleteState.open) return;
  if (e.key === 'Escape') {
    closeMentionAutocomplete();
  } else if (e.key === 'Enter' || e.key === 'Tab') {
    e.preventDefault();
    selectMentionAutocomplete(mentionAutocompleteState.matches[0]);
  }
}

async function handleSendMessage(e) {
  e.preventDefault();
  closeMentionAutocomplete();
  const text = el.composerInput.value.trim();
  const attachment = state.pendingAttachment;
  if (!text && !attachment) return;
  el.composerInput.value = '';
  clearPendingAttachment();

  const attachmentFields = attachment
    ? { attachment_url: attachment.url, attachment_name: attachment.name, attachment_type: attachment.type }
    : {};

  if (state.view === 'server' && state.activeChannel && state.activeChannel.type === 'text') {
    const json = await apiPost('messages.php?action=send', { channel_id: state.activeChannelId, content: text, ...attachmentFields });
    if (json.success) {
      appendMessage(json.message, 'channel');
      scrollToBottom();
    } else {
      alert(json.error);
    }
  } else if (state.view === 'dm' && state.activeConversationId) {
    const json = await apiPost('dm.php?action=send', { conversation_id: state.activeConversationId, content: text, ...attachmentFields });
    if (json.success) {
      appendMessage(json.message, 'dm');
      scrollToBottom();
    } else {
      alert(json.error);
    }
  }
}

/* ============================================================
   Members list
   ============================================================ */
async function loadMembers(serverId) {
  const json = await apiGet(`servers.php?action=members&server_id=${serverId}`);
  if (!json.success) return;
  state.currentMembers = json.members;

  el.memberListContent.innerHTML = '';
  json.members.forEach((m) => {
    const row = document.createElement('div');
    row.className = 'member-row';
    const topRole = m.roles && m.roles.length ? m.roles[0] : null;
    const color = topRole && topRole.color !== '#99AAB5' ? topRole.color : 'var(--text-normal)';

    row.innerHTML = `
      <img class="avatar avatar-sm" src="${avatarUrl(m.avatar_url, m.username)}">
      <div>
        <div class="name" style="color:${color}">${escapeHtml(m.nickname || m.username)}${badgeIconHtml(m.site_badge)}${nitroBadgeHtml(m.nitro_until)}${botTagHtml(m.is_bot)}</div>
      </div>
    `;

    if (hasPerm(PERMISSIONS.KICK_MEMBERS) && m.user_id !== state.me.id && !state.me.is_banned) {
      const kickBtn = document.createElement('button');
      kickBtn.textContent = '✕';
      kickBtn.style.marginLeft = 'auto';
      kickBtn.style.color = 'var(--text-muted)';
      kickBtn.title = 'Kick member';
      kickBtn.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        if (!confirm(`Kick ${m.username} from the server?`)) return;
        const res = await apiPost('servers.php?action=kick', { server_id: serverId, user_id: m.user_id });
        if (res.success) loadMembers(serverId);
        else alert(res.error);
      });
      row.appendChild(kickBtn);
    }

    if (hasPerm(PERMISSIONS.BAN_MEMBERS) && m.user_id !== state.me.id && !state.me.is_banned) {
      const banBtn = document.createElement('button');
      banBtn.textContent = '⛔';
      banBtn.style.color = 'var(--text-muted)';
      banBtn.title = 'Ban member';
      banBtn.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        const reason = prompt(`Ban ${m.username} from the server? Optionally give a reason:`, '');
        if (reason === null) return;
        const res = await apiPost('servers.php?action=ban', { server_id: serverId, user_id: m.user_id, reason });
        if (res.success) loadMembers(serverId);
        else alert(res.error);
      });
      row.appendChild(banBtn);
    }

    row.style.cursor = 'pointer';
    row.addEventListener('click', (e) => openUserProfilePopover(e.currentTarget, m.user_id, serverId));

    el.memberListContent.appendChild(row);
  });
}

/* ============================================================
   Direct Messages
   ============================================================ */
async function loadConversations() {
  const json = await apiGet('dm.php?action=conversations');
  if (json.success) {
    state.conversations = json.conversations;
  }
}

/** Common chrome reset when entering DM-related views (home, a conversation, or friends). */
function enterDMChrome(view) {
  stopPolling();
  stopVoiceRosterPolling();
  state.view = view || 'dm';
  state.activeServerId = null;
  state.activeChannelId = null;
  state.activeChannel = null;
  el.memberList.hidden = true;
  renderServerRail();
  renderDMSidebarShell();
}

function showDMHome() {
  enterDMChrome();

  if (state.activeConversationId && state.activeConversationUser) {
    // A conversation was already open — show it instead of leaving stale content
    // (e.g. a server channel's messages) on screen.
    loadConversationContent(state.activeConversationId, state.activeConversationUser);
    return;
  }

  el.messagesScroll.innerHTML = '';
  showEmptyMain('Your Direct Messages', 'Select a conversation or start a new one.');
  el.channelHeader.textContent = 'Direct Messages';
  el.headerActions.innerHTML = '';
}

/** The DM sidebar (Friends link + conversation list) — shared between the DM view and the Friends view. */
function renderDMSidebarShell() {
  el.sidebarHeader.innerHTML = '<span>Direct Messages</span>';
  const findBtn = document.createElement('button');
  findBtn.textContent = '+';
  findBtn.style.color = 'var(--text-muted)';
  findBtn.style.fontWeight = '700';
  findBtn.addEventListener('click', openFindUserModal);
  el.sidebarHeader.appendChild(findBtn);

  el.sidebarContent.innerHTML = '';

  const friendsItem = document.createElement('div');
  friendsItem.className = 'dm-item friends-nav-item' + (state.view === 'friends' ? ' active' : '');
  friendsItem.innerHTML = `<span class="friends-nav-icon">👥</span><span>Friends</span>`;
  friendsItem.addEventListener('click', () => showFriendsView());
  el.sidebarContent.appendChild(friendsItem);

  const label = document.createElement('div');
  label.className = 'channel-group-label';
  label.textContent = 'Direct Messages';
  el.sidebarContent.appendChild(label);

  if (state.conversations.length === 0) {
    const hint = document.createElement('div');
    hint.style.padding = '8px';
    hint.style.color = 'var(--text-muted)';
    hint.style.fontSize = '13px';
    hint.textContent = 'No conversations yet — click + to find someone.';
    el.sidebarContent.appendChild(hint);
  }

  state.conversations.forEach((c) => {
    const item = document.createElement('div');
    item.className = 'dm-item' + (state.activeConversationId === c.id ? ' active' : '');
    item.dataset.conversationId = c.id;
    const other = c.other_user;
    item.innerHTML = `<img class="avatar" src="${avatarUrl(other.avatar_url, other.username)}"><span>${escapeHtml(other.username)}</span><span class="unread-badge" hidden></span>`;
    item.addEventListener('click', () => openConversation(c.id, other));
    el.sidebarContent.appendChild(item);
  });

  applyUnreadBadgesToDom();
}

/* ============================================================
   Friends
   ============================================================ */
async function loadFriendsData() {
  const json = await apiGet('friends.php?action=list');
  if (json.success) {
    state.friendsData = {
      friends: json.friends,
      incoming: json.incoming,
      outgoing: json.outgoing,
      groups: json.groups,
    };
  }
}

async function showFriendsView() {
  enterDMChrome('friends');
  state.activeConversationId = null;
  state.activeConversationUser = null;
  closeMobileSidebar();

  el.channelHeader.textContent = 'Friends';
  el.headerActions.innerHTML = '';
  el.composer.hidden = true;
  el.emptyState.hidden = true;

  await loadFriendsData();
  renderFriendsMain();
}

function friendsTabCounts() {
  const d = state.friendsData || { friends: [], incoming: [], outgoing: [] };
  return { pending: d.incoming.length + d.outgoing.length };
}

function renderFriendsMain() {
  const d = state.friendsData || { friends: [], incoming: [], outgoing: [], groups: [] };
  const counts = friendsTabCounts();

  el.messagesScroll.innerHTML = `
    <div class="friends-view">
      <div class="friends-tabs">
        <button class="friends-tab ${state.friendsTab === 'online' ? 'active' : ''}" data-tab="online">Online</button>
        <button class="friends-tab ${state.friendsTab === 'all' ? 'active' : ''}" data-tab="all">All Friends</button>
        <button class="friends-tab ${state.friendsTab === 'pending' ? 'active' : ''}" data-tab="pending">Pending${counts.pending ? ` (${counts.pending})` : ''}</button>
        <button class="friends-tab friends-tab-add ${state.friendsTab === 'add' ? 'active' : ''}" data-tab="add">Add Friend</button>
      </div>
      <div id="friends-tab-body" class="friends-tab-body"></div>
    </div>
  `;

  document.querySelectorAll('.friends-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      state.friendsTab = btn.dataset.tab;
      renderFriendsMain();
    });
  });

  const body = document.getElementById('friends-tab-body');
  if (state.friendsTab === 'online') {
    renderFriendsListTab(body, d.friends.filter(f => f.status && f.status !== 'offline'), 'No one is online right now.', d.groups);
  } else if (state.friendsTab === 'all') {
    renderFriendsListTab(body, d.friends, "You haven't added any friends yet.", d.groups);
  } else if (state.friendsTab === 'pending') {
    renderPendingTab(body, d.incoming, d.outgoing);
  } else if (state.friendsTab === 'add') {
    renderAddFriendTab(body);
  }
}

function friendRowHtml(f, showGroupSelect, groups) {
  const groupOptions = showGroupSelect ? `
    <select class="friend-group-select" data-user-id="${f.id}" title="Move to group">
      <option value="">No Group</option>
      ${groups.map(g => `<option value="${g.id}" ${f.group_id === g.id ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}
    </select>
  ` : '';
  return `
    <div class="friend-row" data-user-id="${f.id}">
      <img class="avatar avatar-sm friend-row-avatar" src="${avatarUrl(f.avatar_url, f.username)}">
      <div class="friend-row-info">
        <div class="name">${escapeHtml(f.username)}${badgeIconHtml(f.site_badge)}</div>
        <div class="user-panel-status">${escapeHtml(f.status || 'offline')}</div>
      </div>
      ${groupOptions}
      <button type="button" class="btn-secondary friend-message-btn" data-user-id="${f.id}">Message</button>
      <button type="button" class="btn-secondary friend-remove-btn" data-user-id="${f.id}" data-username="${escapeHtml(f.username)}">Remove</button>
    </div>
  `;
}

function renderFriendsListTab(body, friends, emptyMessage, groups) {
  if (friends.length === 0) {
    body.innerHTML = `<p class="profile-empty">${escapeHtml(emptyMessage)}</p>`;
    return;
  }

  let html = '';
  if (state.friendsTab === 'all' && groups.length > 0) {
    // Group friends by their assigned group, ungrouped last.
    const byGroup = new Map(groups.map(g => [g.id, []]));
    const ungrouped = [];
    friends.forEach(f => {
      if (f.group_id !== null && byGroup.has(f.group_id)) byGroup.get(f.group_id).push(f);
      else ungrouped.push(f);
    });
    groups.forEach(g => {
      const members = byGroup.get(g.id);
      html += `
        <div class="friend-group-section">
          <div class="friend-group-header">
            <span class="friend-group-toggle">▾ ${escapeHtml(g.name)} — ${members.length}</span>
            <button type="button" class="btn-secondary friend-group-rename-btn" data-group-id="${g.id}" data-group-name="${escapeHtml(g.name)}">Rename</button>
            <button type="button" class="btn-secondary friend-group-delete-btn" data-group-id="${g.id}">Delete</button>
          </div>
          ${members.map(f => friendRowHtml(f, true, groups)).join('') || '<p class="profile-empty">No one in this group yet.</p>'}
        </div>
      `;
    });
    html += `
      <div class="friend-group-section">
        <div class="friend-group-header"><span class="friend-group-toggle">▾ No Group — ${ungrouped.length}</span></div>
        ${ungrouped.map(f => friendRowHtml(f, true, groups)).join('') || '<p class="profile-empty">Nobody here.</p>'}
      </div>
    `;
  } else {
    html = friends.map(f => friendRowHtml(f, state.friendsTab === 'all', groups)).join('');
  }

  if (state.friendsTab === 'all') {
    html += `<button type="button" class="btn-secondary" id="friends-add-group-btn" style="margin-top:12px;">+ Create Friend Group</button>`;
  }

  body.innerHTML = html;
  bindFriendRowActions(body);
}

function renderPendingTab(body, incoming, outgoing) {
  const incomingHtml = incoming.length ? incoming.map(r => `
    <div class="friend-row" data-request-id="${r.id}">
      <img class="avatar avatar-sm" src="${avatarUrl(r.avatar_url, r.username)}">
      <div class="friend-row-info">
        <div class="name">${escapeHtml(r.username)}${badgeIconHtml(r.site_badge)}</div>
        <div class="user-panel-status">Incoming Friend Request</div>
      </div>
      <button type="button" class="btn-primary friend-accept-btn" data-request-id="${r.id}">Accept</button>
      <button type="button" class="btn-secondary friend-decline-btn" data-request-id="${r.id}">Decline</button>
    </div>
  `).join('') : '<p class="profile-empty">No incoming requests.</p>';

  const outgoingHtml = outgoing.length ? outgoing.map(r => `
    <div class="friend-row" data-request-id="${r.id}">
      <img class="avatar avatar-sm" src="${avatarUrl(r.avatar_url, r.username)}">
      <div class="friend-row-info">
        <div class="name">${escapeHtml(r.username)}${badgeIconHtml(r.site_badge)}</div>
        <div class="user-panel-status">Outgoing Friend Request</div>
      </div>
      <button type="button" class="btn-secondary friend-cancel-btn" data-request-id="${r.id}">Cancel</button>
    </div>
  `).join('') : '<p class="profile-empty">No outgoing requests.</p>';

  body.innerHTML = `
    <div class="friend-group-section">
      <div class="friend-group-header"><span class="friend-group-toggle">▾ Incoming — ${incoming.length}</span></div>
      ${incomingHtml}
    </div>
    <div class="friend-group-section">
      <div class="friend-group-header"><span class="friend-group-toggle">▾ Outgoing — ${outgoing.length}</span></div>
      ${outgoingHtml}
    </div>
  `;

  document.querySelectorAll('.friend-accept-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const json = await apiPost('friends.php?action=accept_request', { request_id: parseInt(btn.dataset.requestId, 10) });
      if (!json.success) { alert(json.error); return; }
      await loadFriendsData();
      renderFriendsMain();
    });
  });
  document.querySelectorAll('.friend-decline-btn, .friend-cancel-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const json = await apiPost('friends.php?action=remove_request', { request_id: parseInt(btn.dataset.requestId, 10) });
      if (!json.success) { alert(json.error); return; }
      await loadFriendsData();
      renderFriendsMain();
    });
  });
}

function renderAddFriendTab(body) {
  body.innerHTML = `
    <p class="modal-sub">You can add a friend by their exact username.</p>
    <div class="add-friend-row">
      <input type="text" id="add-friend-input" placeholder="Enter a username" maxlength="32">
      <button type="button" class="btn-primary" id="add-friend-btn">Send Friend Request</button>
    </div>
    <div id="add-friend-feedback" class="auth-error" hidden></div>
  `;

  const input = document.getElementById('add-friend-input');
  const feedback = document.getElementById('add-friend-feedback');
  input.focus();

  const submit = async () => {
    const username = input.value.trim();
    if (!username) return;
    const json = await apiPost('friends.php?action=send_request', { username });
    feedback.hidden = false;
    if (!json.success) {
      feedback.textContent = json.error;
      feedback.classList.add('auth-error');
      feedback.style.color = 'var(--red)';
    } else {
      feedback.style.color = 'var(--green, #3ba55d)';
      feedback.textContent = json.accepted
        ? `You and ${username} are now friends!`
        : `Friend request sent to ${username}.`;
      input.value = '';
      await loadFriendsData();
    }
  };

  document.getElementById('add-friend-btn').addEventListener('click', submit);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
}

function bindFriendRowActions(scope) {
  scope.querySelectorAll('.friend-row-info .name, .friend-row-avatar').forEach(el2 => {
    el2.style.cursor = 'pointer';
    el2.addEventListener('click', (e) => {
      const row = el2.closest('.friend-row');
      openUserProfilePopover(e.currentTarget, parseInt(row.dataset.userId, 10), null);
    });
  });
  scope.querySelectorAll('.friend-message-btn').forEach(btn => {
    btn.addEventListener('click', () => startDM(parseInt(btn.dataset.userId, 10)));
  });
  scope.querySelectorAll('.friend-remove-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`Remove ${btn.dataset.username} from your friends?`)) return;
      const json = await apiPost('friends.php?action=remove_friend', { user_id: parseInt(btn.dataset.userId, 10) });
      if (!json.success) { alert(json.error); return; }
      await loadFriendsData();
      renderFriendsMain();
    });
  });
  scope.querySelectorAll('.friend-group-select').forEach(sel => {
    sel.addEventListener('change', async () => {
      const json = await apiPost('friends.php?action=assign_group', {
        user_id: parseInt(sel.dataset.userId, 10),
        group_id: sel.value || null,
      });
      if (!json.success) { alert(json.error); }
      await loadFriendsData();
      renderFriendsMain();
    });
  });
  scope.querySelectorAll('.friend-group-rename-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const name = prompt('Rename this friend group:', btn.dataset.groupName);
      if (name === null || name.trim() === '') return;
      const json = await apiPost('friends.php?action=rename_group', { group_id: parseInt(btn.dataset.groupId, 10), name: name.trim() });
      if (!json.success) { alert(json.error); return; }
      await loadFriendsData();
      renderFriendsMain();
    });
  });
  scope.querySelectorAll('.friend-group-delete-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this friend group? Friends in it will just become ungrouped.')) return;
      const json = await apiPost('friends.php?action=delete_group', { group_id: parseInt(btn.dataset.groupId, 10) });
      if (!json.success) { alert(json.error); return; }
      await loadFriendsData();
      renderFriendsMain();
    });
  });
  const addGroupBtn = document.getElementById('friends-add-group-btn');
  if (addGroupBtn) {
    addGroupBtn.addEventListener('click', async () => {
      const name = prompt('New friend group name:');
      if (name === null || name.trim() === '') return;
      const json = await apiPost('friends.php?action=create_group', { name: name.trim() });
      if (!json.success) { alert(json.error); return; }
      await loadFriendsData();
      renderFriendsMain();
    });
  }
}

async function startDM(userId) {
  const json = await apiPost('dm.php?action=start', { user_id: userId });
  if (!json.success) {
    alert(json.error);
    return;
  }
  await loadConversations();
  showDMHome();
  openConversation(json.conversation.id, json.other_user);
}

async function openConversation(conversationId, otherUser) {
  state.activeConversationId = conversationId;
  state.activeConversationUser = otherUser;
  state.lastMessageId = 0;

  enterDMChrome('dm');
  closeMobileSidebar();

  await loadConversationContent(conversationId, otherUser);
}

/** Loads header + messages + polling for a conversation. Assumes DM chrome (sidebar/rail) is already set up. */
async function loadConversationContent(conversationId, otherUser) {
  stopPolling();

  el.channelHeader.textContent = `@ ${otherUser.username}`;
  el.headerActions.innerHTML = '';
  const inThisCall = callRuntime.callId && callRuntime.kind === 'dm' && callRuntime.conversationId === conversationId;
  if (inThisCall) {
    // Mute + leave live here now — the persistent top call bar is just a
    // status/return strip and no longer carries its own controls.
    const controls = document.createElement('div');
    controls.className = 'dm-call-controls';
    controls.innerHTML = `
      <button type="button" id="dm-call-mute-btn" class="dm-call-icon-btn${callRuntime.muted ? ' muted' : ''}" title="${callRuntime.muted ? 'Unmute' : 'Mute'}">${callRuntime.muted ? ICON_MIC_OFF : ICON_MIC}</button>
      <button type="button" id="dm-call-leave-btn" class="dm-call-icon-btn hangup" title="Leave call">${ICON_PHONE_OFF}</button>
    `;
    el.headerActions.appendChild(controls);
    document.getElementById('dm-call-mute-btn').addEventListener('click', toggleMute);
    document.getElementById('dm-call-leave-btn').addEventListener('click', () => leaveCall());
  } else {
    const callBtn = document.createElement('button');
    callBtn.className = 'dm-call-btn';
    callBtn.innerHTML = `${ICON_PHONE} Call`;
    callBtn.title = `Call ${otherUser.username}`;
    if (state.me.is_banned) {
      callBtn.disabled = true;
      callBtn.title = 'Disabled while your account is suspended';
    } else {
      callBtn.addEventListener('click', () => joinDmCall(conversationId, otherUser));
    }
    el.headerActions.appendChild(callBtn);
  }
  el.composer.hidden = false;
  el.emptyState.hidden = true;
  updateComposerState();

  const json = await apiGet(`dm.php?action=messages&conversation_id=${conversationId}`);
  el.messagesScroll.innerHTML = '';
  if (json.success) {
    json.messages.forEach(m => appendMessage(m, 'dm'));
    if (json.messages.length) {
      state.lastMessageId = Math.max(...json.messages.map(m => m.id));
    }
  }
  scrollToBottom();
  markConversationRead(conversationId);
  startTypingPolling('dm', conversationId);

  state.pollTimer = setInterval(async () => {
    if (state.activeConversationId !== conversationId) return;
    const wasNearBottom = isScrolledNearBottom();
    const res = await apiGet(`dm.php?action=messages&conversation_id=${conversationId}&after_id=${state.lastMessageId}`);
    if (res.success && res.messages.length) {
      res.messages.forEach(m => {
        appendMessage(m, 'dm');
        state.lastMessageId = Math.max(state.lastMessageId, m.id);
      });
      if (wasNearBottom) scrollToBottom();
      markConversationRead(conversationId);
    }
  }, 3000);
}

/* ============================================================
   Avatar upload
   ============================================================ */
async function handleAvatarUpload(e) {
  const file = e.target.files[0];
  if (!file) return;
  const formData = new FormData();
  formData.append('avatar', file);
  const json = await apiUpload('upload.php?action=avatar', formData);
  if (json.success) {
    el.myAvatar.src = json.avatar_url;
    state.me.avatar_url = json.avatar_url;
    const preview = document.getElementById('settings-avatar-preview');
    if (preview) preview.src = json.avatar_url;
  } else {
    alert(json.error);
  }
  e.target.value = '';
}

/* ============================================================
   Profile banner upload (Nitro perk)
   ============================================================ */
async function handleBannerUpload(e) {
  const file = e.target.files[0];
  if (!file) return;
  const formData = new FormData();
  formData.append('banner', file);
  const json = await apiUpload('upload.php?action=banner', formData);
  if (json.success) {
    state.me.banner_url = json.banner_url;
    refreshSettingsBannerPreview();
  } else {
    alert(json.error);
  }
  e.target.value = '';
}

async function handleBannerRemove() {
  const json = await apiPost('upload.php?action=banner_remove', {});
  if (json.success) {
    state.me.banner_url = null;
    refreshSettingsBannerPreview();
  } else {
    alert(json.error);
  }
}

/** Repaints just the banner preview strip inside the (already-open) Settings modal, if present. */
function refreshSettingsBannerPreview() {
  const preview = document.getElementById('settings-banner-preview');
  if (!preview) return;
  if (state.me.banner_url) {
    preview.style.backgroundImage = `url(${state.me.banner_url})`;
    preview.style.backgroundColor = '';
  } else {
    preview.style.backgroundImage = '';
    preview.style.backgroundColor = state.me.accent_color;
  }
  const removeBtn = document.getElementById('settings-remove-banner-btn');
  if (removeBtn) removeBtn.hidden = !state.me.banner_url;
}

/* ============================================================
   Message attachments (composer)
   ============================================================ */
async function handleAttachmentSelected(e) {
  const file = e.target.files[0];
  if (!file) return;
  const formData = new FormData();
  formData.append('file', file);
  const json = await apiUpload('upload.php?action=attachment', formData);
  e.target.value = '';
  if (!json.success) {
    alert(json.error);
    return;
  }
  state.pendingAttachment = { url: json.url, name: json.name, type: json.type };
  renderAttachmentPreview();
}

function renderAttachmentPreview() {
  const att = state.pendingAttachment;
  if (!att) {
    el.attachmentPreview.hidden = true;
    el.attachmentPreview.innerHTML = '';
    return;
  }
  el.attachmentPreview.hidden = false;
  el.attachmentPreview.innerHTML = `
    ${att.type === 'image'
      ? `<img src="${att.url}" alt="">`
      : `<span class="attachment-file-icon">📄</span>`}
    <span class="attachment-name">${escapeHtml(att.name)}</span>
    <button type="button" class="attachment-remove-btn" title="Remove attachment">✕</button>
  `;
  el.attachmentPreview.querySelector('.attachment-remove-btn').addEventListener('click', clearPendingAttachment);
}

function clearPendingAttachment() {
  state.pendingAttachment = null;
  renderAttachmentPreview();
}

/* ============================================================
   Custom server emojis
   ============================================================ */
async function loadCustomEmojiMap() {
  const json = await apiGet('emojis.php?action=usable');
  if (json.success) state.customEmojiMap = json.emojis;
}

/* ============================================================
   Modals
   ============================================================ */
function openModal(html, opts) {
  el.modalContent.innerHTML = html;
  el.modalContent.classList.toggle('modal-wide', !!(opts && opts.wide));
  el.modalOverlay.hidden = false;
}
function closeModal() {
  el.modalOverlay.hidden = true;
  el.modalContent.innerHTML = '';
  el.modalContent.classList.remove('modal-wide');
}

/* ============================================================
   Nitro & Quests
   ============================================================ */
async function openQuestsModal() {
  const [qRes, nRes] = await Promise.all([
    apiGet('quests.php?action=status'),
    apiGet('premium.php?action=status'),
  ]);
  if (!qRes.success || !nRes.success) return;
  renderQuestsModal(qRes.quest, nRes.nitro);
}

function renderQuestsModal(quest, nitro) {
  const cooldownText = quest.cooldown_remaining > 0 ? formatCooldown(quest.cooldown_remaining) : null;
  const canWatch = quest.can_watch;
  const limitReached = quest.watched_today >= quest.daily_limit;
  const nitroActiveText = nitro.active
    ? `Active until ${new Date(nitro.nitro_until.replace(' ', 'T') + 'Z').toLocaleDateString()}`
    : 'Not active';
  const canBuyNitro = nitro.points >= nitro.cost;

  openModal(`
    <h2>⚡ Nitro &amp; Quests</h2>
    <p class="modal-sub">Earn points by watching ads, then spend them on Nitro perks.</p>
    <div class="quests-points-balance">💰 <strong id="quests-points-value">${nitro.points}</strong> points</div>

    <div class="quests-section">
      <h3>Daily Quest</h3>
      <div class="quest-card">
        <div class="quest-card-info">
          <div class="quest-card-title">📺 Watch an Ad</div>
          <div class="quest-card-sub">+${quest.reward} points • ${quest.watched_today}/${quest.daily_limit} today</div>
        </div>
        <button type="button" class="btn-primary" id="watch-ad-btn" ${canWatch ? '' : 'disabled'}>
          ${canWatch ? 'Watch' : (limitReached ? 'Limit reached' : 'On cooldown')}
        </button>
      </div>
      <div id="quest-cooldown-note" class="modal-sub"${cooldownText ? '' : ' hidden'}>Next ad available in <span id="quest-cooldown-timer">${cooldownText || ''}</span></div>
    </div>

    <div class="quests-section">
      <h3>Nitro</h3>
      <ul class="nitro-perks">
        <li>⚡ Nitro badge next to your name everywhere</li>
        <li>🖼 Bigger avatar uploads</li>
        <li>🎨 Highlighted, glowing username in chat</li>
        <li>🌄 Custom profile banner image</li>
      </ul>
      <div class="quest-card">
        <div class="quest-card-info">
          <div class="quest-card-title">${nitro.active ? '⚡ Nitro active' : 'Get Nitro'}</div>
          <div class="quest-card-sub">${nitro.active ? nitroActiveText + ' — buying again adds ' + nitro.duration_days + ' more days' : nitro.duration_days + ' days for ' + nitro.cost + ' points'}</div>
        </div>
        <button type="button" class="btn-primary" id="buy-nitro-btn" ${canBuyNitro ? '' : 'disabled'}>${nitro.active ? 'Extend' : 'Get Nitro'}</button>
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-quests-modal-btn">Close</button>
    </div>
  `);

  document.getElementById('close-quests-modal-btn').addEventListener('click', closeModal);

  if (cooldownText) startQuestCooldownTicker(quest.cooldown_remaining);

  const watchBtn = document.getElementById('watch-ad-btn');
  if (watchBtn && canWatch) {
    watchBtn.addEventListener('click', () => runAdWatch(quest.watch_seconds));
  }

  const buyBtn = document.getElementById('buy-nitro-btn');
  if (buyBtn && canBuyNitro) {
    buyBtn.addEventListener('click', async () => {
      buyBtn.disabled = true;
      buyBtn.textContent = 'Purchasing...';
      const json = await apiPost('premium.php?action=purchase');
      if (!json.success) {
        alert(json.error);
        buyBtn.disabled = false;
        return;
      }
      const questJson = await apiGet('quests.php?action=status');
      renderQuestsModal(questJson.quest, json.nitro);
    });
  }
}

/** Simulates watching an ad for `watchSeconds`, then claims the reward. */
async function runAdWatch(watchSeconds) {
  const btn = document.getElementById('watch-ad-btn');
  const startJson = await apiPost('quests.php?action=start_ad');
  if (!startJson.success) {
    alert(startJson.error);
    return;
  }
  btn.disabled = true;
  let remaining = startJson.watch_seconds || watchSeconds;
  btn.textContent = `Playing ad… ${remaining}s`;
  const timer = setInterval(async () => {
    remaining -= 1;
    if (remaining > 0) {
      if (document.getElementById('watch-ad-btn') === btn) btn.textContent = `Playing ad… ${remaining}s`;
      return;
    }
    clearInterval(timer);
    if (document.getElementById('watch-ad-btn') === btn) btn.textContent = 'Claiming...';
    const claimJson = await apiPost('quests.php?action=claim_ad');
    if (!claimJson.success) {
      alert(claimJson.error);
    }
    const [questJson, nitroJson] = await Promise.all([
      apiGet('quests.php?action=status'),
      apiGet('premium.php?action=status'),
    ]);
    if (document.getElementById('watch-ad-btn')) {
      renderQuestsModal(questJson.quest, nitroJson.nitro);
    }
  }, 1000);
}

function startQuestCooldownTicker(seconds) {
  let remaining = seconds;
  const interval = setInterval(() => {
    remaining -= 1;
    const timerEl = document.getElementById('quest-cooldown-timer');
    if (!timerEl) { clearInterval(interval); return; } // modal closed/re-rendered elsewhere
    if (remaining <= 0) {
      clearInterval(interval);
      openQuestsModal(); // refresh full state, re-enables the button
      return;
    }
    timerEl.textContent = formatCooldown(remaining);
  }, 1000);
}

function formatCooldown(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function openCreateServerModal() {
  openModal(`
    <h2>Create a Server</h2>
    <p class="modal-sub">Your server is where you and your friends hang out.</p>
    <form id="create-server-form">
      <label>Server Name</label>
      <input type="text" name="name" required maxlength="100" autofocus>
      <div id="create-server-error" class="auth-error" hidden></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="cancel-create-server">Cancel</button>
        <button type="submit" class="btn-primary">Create</button>
      </div>
    </form>
  `);
  document.getElementById('cancel-create-server').addEventListener('click', closeModal);
  document.getElementById('create-server-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = e.target.name.value.trim();
    const json = await apiPost('servers.php?action=create', { name });
    if (!json.success) {
      const err = document.getElementById('create-server-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    closeModal();
    await loadServers();
    openServer(json.server.id);
  });
}

function openJoinServerModal() {
  openModal(`
    <h2>Join a Server</h2>
    <p class="modal-sub">Enter an invite code below to join an existing server.</p>
    <form id="join-server-form">
      <label>Invite Code</label>
      <input type="text" name="invite_code" required autofocus>
      <div id="join-server-error" class="auth-error" hidden></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="cancel-join-server">Cancel</button>
        <button type="submit" class="btn-primary">Join Server</button>
      </div>
    </form>
  `);
  document.getElementById('cancel-join-server').addEventListener('click', closeModal);
  document.getElementById('join-server-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const raw = e.target.invite_code.value.trim();
    // Accept either a bare code or a full /invite/<code> link.
    const code = raw.includes('/') ? raw.replace(/\/+$/, '').split('/').pop() : raw;
    const json = await apiPost('servers.php?action=join', { invite_code: code });
    if (!json.success) {
      const err = document.getElementById('join-server-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    closeModal();
    await loadServers();
    openServer(json.server.id);
  });
}

function openCreateChannelModal(serverId, defaultType, categoryId) {
  openModal(`
    <h2>Create Channel</h2>
    <form id="create-channel-form">
      <label>Channel Type</label>
      <select name="type">
        <option value="text" ${defaultType === 'text' ? 'selected' : ''}>Text Channel</option>
        <option value="voice" ${defaultType === 'voice' ? 'selected' : ''}>Voice Channel</option>
      </select>
      <label>Channel Name</label>
      <input type="text" name="name" required maxlength="100" autofocus>
      ${state.categories.length > 0 ? `
        <label>Category</label>
        <select name="category_id">
          <option value="">No category</option>
          ${state.categories.map(c => `<option value="${c.id}" ${categoryId === c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}
        </select>
      ` : ''}
      <div id="create-channel-error" class="auth-error" hidden></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="cancel-create-channel">Cancel</button>
        <button type="submit" class="btn-primary">Create Channel</button>
      </div>
    </form>
  `);
  document.getElementById('cancel-create-channel').addEventListener('click', closeModal);
  document.getElementById('create-channel-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = e.target.name.value.trim();
    const type = e.target.type.value;
    const categoryField = e.target.category_id;
    const chosenCategoryId = categoryField && categoryField.value ? parseInt(categoryField.value, 10) : null;
    const json = await apiPost('channels.php?action=create', { server_id: serverId, name, type, category_id: chosenCategoryId });
    if (!json.success) {
      const err = document.getElementById('create-channel-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    closeModal();
    const serverInfo = state.servers.find(s => s.id === serverId);
    await loadChannelsForSidebar(serverId, serverInfo);
    if (json.channel.type === 'text') selectChannel(json.channel);
  });
}

function openEditChannelModal(serverInfo, channel) {
  openModal(`
    <h2>Edit #${escapeHtml(channel.name)}</h2>
    <form id="edit-channel-form">
      <label>Channel Name</label>
      <input type="text" name="name" required maxlength="100" value="${escapeHtml(channel.name)}" autofocus>
      ${channel.type === 'text' ? `
        <label>Topic</label>
        <input type="text" name="topic" maxlength="250" value="${escapeHtml(channel.topic || '')}" placeholder="What's this channel about?">
      ` : ''}
      <label>Category</label>
      <select name="category_id">
        <option value="" ${!channel.category_id ? 'selected' : ''}>No category</option>
        ${state.categories.map(c => `<option value="${c.id}" ${channel.category_id === c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}
      </select>
      <div id="edit-channel-error" class="auth-error" hidden></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="cancel-edit-channel">Cancel</button>
        <button type="submit" class="btn-primary">Save Changes</button>
      </div>
    </form>
  `);
  document.getElementById('cancel-edit-channel').addEventListener('click', closeModal);
  document.getElementById('edit-channel-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = e.target.name.value.trim();
    const topicField = e.target.topic;
    const categoryField = e.target.category_id;
    const body = { channel_id: channel.id, name, category_id: categoryField.value ? parseInt(categoryField.value, 10) : null };
    if (topicField) body.topic = topicField.value.trim();
    const json = await apiPost('channels.php?action=update', body);
    if (!json.success) {
      const err = document.getElementById('edit-channel-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    closeModal();
    await loadChannelsForSidebar(serverInfo.id, serverInfo);
  });
}

function openFindUserModal() {
  openModal(`
    <h2>Find a User</h2>
    <p class="modal-sub">Search by username to start a direct message.</p>
    <input type="text" id="find-user-input" placeholder="Type a username..." autofocus>
    <div id="find-user-results" style="margin-top:12px;"></div>
  `);
  const input = document.getElementById('find-user-input');
  const results = document.getElementById('find-user-results');
  let debounceTimer;
  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async () => {
      const q = input.value.trim();
      if (q.length < 2) { results.innerHTML = ''; return; }
      const json = await apiGet(`dm.php?action=search_users&q=${encodeURIComponent(q)}`);
      results.innerHTML = '';
      if (json.success) {
        json.users.forEach(u => {
          const row = document.createElement('div');
          row.className = 'dm-item';
          row.innerHTML = `<img class="avatar" src="${avatarUrl(u.avatar_url, u.username)}"><span>${escapeHtml(u.username)}</span>`;
          row.addEventListener('click', () => {
            closeModal();
            startDM(u.id);
          });
          results.appendChild(row);
        });
      }
    }, 300);
  });
}

/* -------- Server settings (invite, roles, danger zone) -------- */
function openServerSettingsModal(serverInfo) {
  const isOwner = state.me.id === serverInfo.owner_id;
  const canManageServer = hasPerm(PERMISSIONS.MANAGE_SERVER);
  const canManageChannels = hasPerm(PERMISSIONS.MANAGE_CHANNELS);
  const canManageRoles = hasPerm(PERMISSIONS.MANAGE_ROLES);
  const canBan = hasPerm(PERMISSIONS.BAN_MEMBERS);

  openModal(`
    <h2>${escapeHtml(serverInfo.name)}</h2>
    <p class="modal-sub">Server Settings</p>

    ${canManageServer ? `
    <label>Server Name</label>
    <div style="display:flex; gap:8px;">
      <input type="text" id="server-name-input" value="${escapeHtml(serverInfo.name)}" maxlength="100" style="flex:1;">
      <button type="button" class="btn-secondary" id="save-server-name-btn">Save</button>
    </div>
    ` : ''}

    <label style="margin-top:16px;">Default Invite Link</label>
    <div class="invite-code-box">
      <code id="default-invite-link">${escapeHtml(inviteLink(serverInfo.invite_code))}</code>
      <button type="button" class="btn-secondary" id="copy-invite">Copy</button>
      ${canManageServer ? '<button type="button" class="btn-secondary" id="regen-invite-btn">New Code</button>' : ''}
    </div>
    ${canManageServer ? '<p class="modal-sub" style="margin:4px 0 0;">Generating a new code invalidates the old one — anyone with the old link can no longer join.</p>' : ''}

    ${canManageServer ? `
    <label style="margin-top:16px;">Server Icon</label>
    <input type="file" id="icon-input" accept="image/*">
    ` : ''}

    ${canManageChannels ? `
    <label style="margin-top:20px;">Channel Headers</label>
    <div class="permission-row" style="cursor:default;">
      <div class="perm-name">Show "Text Channels" header</div>
      <button type="button" class="toggle ${serverInfo.hide_text_header ? '' : 'on'}" id="toggle-text-header"></button>
    </div>
    <div class="permission-row" style="cursor:default;">
      <div class="perm-name">Show "Voice Channels" header</div>
      <button type="button" class="toggle ${serverInfo.hide_voice_header ? '' : 'on'}" id="toggle-voice-header"></button>
    </div>
    ` : ''}

    <div class="modal-actions" style="justify-content:flex-start; margin-top:20px; gap:10px; flex-wrap:wrap;">
      ${canManageRoles ? '<button type="button" class="btn-secondary" id="open-roles-btn">Manage Roles</button>' : ''}
      ${canManageServer ? '<button type="button" class="btn-secondary" id="open-invites-btn">Manage Invites</button>' : ''}
      ${canManageServer ? '<button type="button" class="btn-secondary" id="open-emojis-btn">Manage Emojis</button>' : ''}
      ${canBan ? '<button type="button" class="btn-secondary" id="open-bans-btn">Banned Users</button>' : ''}
    </div>

    ${!isOwner ? `
      <label style="margin-top:24px;">Leave</label>
      <button type="button" class="btn-secondary" id="leave-server-btn">🚪 Leave Server</button>
    ` : ''}

    ${isOwner ? `
      <label style="margin-top:24px;">Danger Zone</label>
      <button type="button" class="btn-danger" id="delete-server-btn">Delete Server</button>
    ` : ''}

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-settings-btn">Close</button>
    </div>
  `);

  document.getElementById('close-settings-btn').addEventListener('click', closeModal);
  document.getElementById('copy-invite').addEventListener('click', () => {
    navigator.clipboard.writeText(inviteLink(serverInfo.invite_code));
    const btn = document.getElementById('copy-invite');
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 1500);
  });

  const nameSaveBtn = document.getElementById('save-server-name-btn');
  if (nameSaveBtn) {
    nameSaveBtn.addEventListener('click', async () => {
      const name = document.getElementById('server-name-input').value.trim();
      if (!name || name === serverInfo.name) return;
      const json = await apiPost('servers.php?action=rename', { server_id: serverInfo.id, name });
      if (!json.success) { alert(json.error); return; }
      serverInfo.name = name;
      await loadServers();
      await loadChannelsForSidebar(serverInfo.id, serverInfo);
      openServerSettingsModal(serverInfo);
    });
  }

  const regenBtn = document.getElementById('regen-invite-btn');
  if (regenBtn) {
    regenBtn.addEventListener('click', async () => {
      if (!confirm('Generate a new invite code? The current one will stop working.')) return;
      const json = await apiPost('servers.php?action=regenerate_invite', { server_id: serverInfo.id });
      if (!json.success) { alert(json.error); return; }
      serverInfo.invite_code = json.invite_code;
      openServerSettingsModal(serverInfo);
    });
  }

  const iconInput = document.getElementById('icon-input');
  if (iconInput) {
    iconInput.addEventListener('change', async (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const formData = new FormData();
      formData.append('icon', file);
      formData.append('server_id', serverInfo.id);
      const json = await apiUpload('upload.php?action=server_icon', formData);
      if (json.success) {
        await loadServers();
        closeModal();
      } else {
        alert(json.error);
      }
    });
  }

  const rolesBtn = document.getElementById('open-roles-btn');
  if (rolesBtn) rolesBtn.addEventListener('click', () => openRolesModal(serverInfo));

  const bansBtn = document.getElementById('open-bans-btn');
  if (bansBtn) bansBtn.addEventListener('click', () => openServerBansModal(serverInfo));

  const invitesBtn = document.getElementById('open-invites-btn');
  if (invitesBtn) invitesBtn.addEventListener('click', () => openServerInvitesModal(serverInfo));

  const emojisBtn = document.getElementById('open-emojis-btn');
  if (emojisBtn) emojisBtn.addEventListener('click', () => openServerEmojisModal(serverInfo));

  const bindHeaderToggle = (btnId, type) => {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', async () => {
      const nowHidden = btn.classList.contains('on'); // was showing -> now hiding
      const json = await apiPost('channels.php?action=set_default_group_hidden', {
        server_id: serverInfo.id, type, hidden: nowHidden,
      });
      if (!json.success) { alert(json.error); return; }
      btn.classList.toggle('on');
      if (type === 'text') serverInfo.hide_text_header = nowHidden ? 1 : 0;
      else serverInfo.hide_voice_header = nowHidden ? 1 : 0;
      await loadChannelsForSidebar(serverInfo.id, serverInfo);
    });
  };
  bindHeaderToggle('toggle-text-header', 'text');
  bindHeaderToggle('toggle-voice-header', 'voice');

  const leaveBtn = document.getElementById('leave-server-btn');
  if (leaveBtn) {
    leaveBtn.addEventListener('click', async () => {
      if (!confirm(`Leave "${serverInfo.name}"? You'll need a new invite to rejoin.`)) return;
      const json = await apiPost('servers.php?action=leave', { server_id: serverInfo.id });
      if (json.success) {
        closeModal();
        await loadServers();
        showDMHome();
      } else {
        alert(json.error);
      }
    });
  }

  const deleteBtn = document.getElementById('delete-server-btn');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', async () => {
      if (!confirm(`Delete "${serverInfo.name}"? This cannot be undone.`)) return;
      const json = await apiPost('servers.php?action=delete', { server_id: serverInfo.id });
      if (json.success) {
        closeModal();
        await loadServers();
        showDMHome();
      } else {
        alert(json.error);
      }
    });
  }
}

async function openServerBansModal(serverInfo) {
  const json = await apiGet(`servers.php?action=bans&server_id=${serverInfo.id}`);
  if (!json.success) { alert(json.error); return; }
  renderServerBansModal(serverInfo, json.bans);
}

function renderServerBansModal(serverInfo, bans) {
  const rows = bans.map(b => `
    <div class="permission-row" style="cursor:default; align-items:flex-start;">
      <div>
        <div class="perm-name">${escapeHtml(b.username)}</div>
        <div class="perm-desc">${b.reason ? escapeHtml(b.reason) : 'No reason given'} — banned by ${escapeHtml(b.banned_by_username)} on ${formatTime(b.banned_at)}</div>
      </div>
      <button type="button" class="btn-secondary" data-unban="${b.user_id}">Unban</button>
    </div>
  `).join('') || '<p class="modal-sub">No one is banned from this server.</p>';

  openModal(`
    <h2>Banned Users — ${escapeHtml(serverInfo.name)}</h2>
    <p class="modal-sub">Banned users can't rejoin via invite until unbanned.</p>
    <div id="server-bans-list">${rows}</div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-server-bans-btn">Close</button>
    </div>
  `, { wide: true });

  document.getElementById('close-server-bans-btn').addEventListener('click', closeModal);
  document.querySelectorAll('[data-unban]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const userId = parseInt(btn.dataset.unban, 10);
      const json = await apiPost('servers.php?action=unban', { server_id: serverInfo.id, user_id: userId });
      if (!json.success) { alert(json.error); return; }
      await openServerBansModal(serverInfo);
    });
  });
}

/* -------- Multi-invite management -------- */
async function openServerInvitesModal(serverInfo) {
  const json = await apiGet(`servers.php?action=invites_list&server_id=${serverInfo.id}`);
  if (!json.success) { alert(json.error); return; }
  renderServerInvitesModal(serverInfo, json.invites);
}

function renderServerInvitesModal(serverInfo, invites) {
  const rows = invites.map(inv => {
    const expired = inv.expires_at && new Date(inv.expires_at.replace(' ', 'T') + 'Z').getTime() < Date.now();
    const usedUp = inv.max_uses !== null && inv.uses >= inv.max_uses;
    const dead = expired || usedUp;
    return `
      <div class="permission-row" style="cursor:default; align-items:flex-start; ${dead ? 'opacity:.5;' : ''}">
        <div>
          <div class="perm-name">${escapeHtml(inv.code)}${dead ? ` <span style="color:var(--red);font-weight:400;">(${usedUp ? 'used up' : 'expired'})</span>` : ''}</div>
          <div class="perm-desc">
            ${inv.uses}${inv.max_uses !== null ? ` / ${inv.max_uses}` : ''} uses ·
            ${inv.expires_at ? `expires ${formatTime(inv.expires_at)}` : 'never expires'} ·
            by ${escapeHtml(inv.created_by_username)}
          </div>
        </div>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn-secondary" data-copy-invite="${inv.code}">Copy</button>
          <button type="button" class="btn-secondary" data-revoke-invite="${inv.id}">Revoke</button>
        </div>
      </div>
    `;
  }).join('') || '<p class="modal-sub">No extra invites yet — create one below. (The Default Invite Link in Server Settings always works too.)</p>';

  openModal(`
    <h2>🔗 Invites — ${escapeHtml(serverInfo.name)}</h2>
    <p class="modal-sub">Create as many invite links as you want, each with its own limits — or leave both Unlimited / Never for a link that works forever.</p>
    <div id="server-invites-list">${rows}</div>

    <label style="margin-top:16px;">New Invite</label>
    <div style="display:flex; gap:8px;">
      <select id="new-invite-max-uses" style="flex:1;">
        <option value="">Unlimited uses</option>
        <option value="1">1 use</option>
        <option value="5">5 uses</option>
        <option value="10">10 uses</option>
        <option value="25">25 uses</option>
        <option value="100">100 uses</option>
      </select>
      <select id="new-invite-expires" style="flex:1;">
        <option value="">Never expires</option>
        <option value="30">30 minutes</option>
        <option value="60">1 hour</option>
        <option value="360">6 hours</option>
        <option value="1440">1 day</option>
        <option value="10080">7 days</option>
      </select>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-invites-btn">Close</button>
      <button type="button" class="btn-primary" id="create-invite-btn">Create Invite</button>
    </div>
  `, { wide: true });

  document.getElementById('close-invites-btn').addEventListener('click', closeModal);

  document.getElementById('create-invite-btn').addEventListener('click', async () => {
    const maxUsesVal = document.getElementById('new-invite-max-uses').value;
    const expiresVal = document.getElementById('new-invite-expires').value;
    const json = await apiPost('servers.php?action=invite_create', {
      server_id: serverInfo.id,
      max_uses: maxUsesVal ? parseInt(maxUsesVal, 10) : null,
      expires_in_minutes: expiresVal ? parseInt(expiresVal, 10) : null,
    });
    if (!json.success) { alert(json.error); return; }
    await openServerInvitesModal(serverInfo);
  });

  document.querySelectorAll('[data-copy-invite]').forEach(btn => {
    btn.addEventListener('click', () => {
      navigator.clipboard.writeText(inviteLink(btn.dataset.copyInvite));
      btn.textContent = 'Copied!';
      setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
    });
  });

  document.querySelectorAll('[data-revoke-invite]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Revoke this invite? It will stop working immediately.')) return;
      const json = await apiPost('servers.php?action=invite_revoke', { invite_id: parseInt(btn.dataset.revokeInvite, 10) });
      if (!json.success) { alert(json.error); return; }
      await openServerInvitesModal(serverInfo);
    });
  });
}

/* -------- Custom emoji management -------- */
async function openServerEmojisModal(serverInfo) {
  const json = await apiGet(`emojis.php?action=list&server_id=${serverInfo.id}`);
  if (!json.success) { alert(json.error); return; }
  renderServerEmojisModal(serverInfo, json.emojis);
}

function renderServerEmojisModal(serverInfo, emojis) {
  const rows = emojis.map(em => `
    <div class="permission-row" style="cursor:default;">
      <div style="display:flex; align-items:center; gap:10px;">
        <img src="${em.image_url}" alt="" style="width:32px; height:32px; object-fit:contain;">
        <span class="perm-name">:${escapeHtml(em.name)}:</span>
      </div>
      <button type="button" class="btn-secondary" data-delete-emoji="${em.id}">Delete</button>
    </div>
  `).join('') || '<p class="modal-sub">No custom emojis yet — add one below. Use them anywhere as :name:</p>';

  openModal(`
    <h2>😀 Emojis — ${escapeHtml(serverInfo.name)}</h2>
    <p class="modal-sub">Members can use these anywhere by typing :name: — it renders inline automatically.</p>
    <div id="server-emojis-list">${rows}</div>

    <label style="margin-top:16px;">New Emoji</label>
    <div style="display:flex; gap:8px;">
      <input type="text" id="new-emoji-name" placeholder="name (letters, numbers, _)" maxlength="32" style="flex:1;">
      <input type="file" id="new-emoji-file" accept="image/*" style="flex:1;">
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-emojis-btn">Close</button>
      <button type="button" class="btn-primary" id="create-emoji-btn">Add Emoji</button>
    </div>
  `, { wide: true });

  document.getElementById('close-emojis-btn').addEventListener('click', closeModal);

  document.getElementById('create-emoji-btn').addEventListener('click', async () => {
    const name = document.getElementById('new-emoji-name').value.trim().toLowerCase();
    const fileInput = document.getElementById('new-emoji-file');
    const file = fileInput.files[0];
    if (!/^[a-z0-9_]{2,32}$/.test(name)) {
      alert('Emoji name must be 2-32 characters: letters, numbers, underscore.');
      return;
    }
    if (!file) {
      alert('Choose an image file.');
      return;
    }
    const formData = new FormData();
    formData.append('icon', file);
    formData.append('server_id', serverInfo.id);
    formData.append('name', name);
    const json = await apiUpload('upload.php?action=custom_emoji', formData);
    if (!json.success) { alert(json.error); return; }
    loadCustomEmojiMap();
    await openServerEmojisModal(serverInfo);
  });

  document.querySelectorAll('[data-delete-emoji]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this emoji? It will stop rendering in old messages too.')) return;
      const json = await apiPost('emojis.php?action=delete', { emoji_id: parseInt(btn.dataset.deleteEmoji, 10) });
      if (!json.success) { alert(json.error); return; }
      loadCustomEmojiMap();
      await openServerEmojisModal(serverInfo);
    });
  });
}

/* -------- Roles management -------- */
async function openRolesModal(serverInfo) {
  const json = await apiGet(`roles.php?action=list&server_id=${serverInfo.id}`);
  if (!json.success) { alert(json.error); return; }

  const roleListHtml = json.roles.map(r => `
    <div class="dm-item" data-role-id="${r.id}" style="cursor:pointer;">
      <span class="role-pill" style="background:${r.color};color:#1e1f22;">${escapeHtml(r.name)}</span>
      ${r.is_default ? '<span style="color:var(--text-muted); font-size:12px;">(default)</span>' : ''}
    </div>
  `).join('');

  openModal(`
    <h2>Roles</h2>
    <p class="modal-sub">Click a role to edit its permissions.</p>
    <div id="roles-list">${roleListHtml}</div>
    <div class="modal-actions" style="justify-content:flex-start;">
      <button type="button" class="btn-secondary" id="new-role-btn">+ New Role</button>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="back-to-settings">Back</button>
    </div>
  `);

  document.getElementById('back-to-settings').addEventListener('click', () => openServerSettingsModal(serverInfo));
  document.getElementById('new-role-btn').addEventListener('click', () => openRoleEditorModal(serverInfo, null));

  document.querySelectorAll('#roles-list [data-role-id]').forEach(node => {
    node.addEventListener('click', () => {
      const role = json.roles.find(r => r.id === parseInt(node.dataset.roleId, 10));
      openRoleEditorModal(serverInfo, role);
    });
  });
}

function openRoleEditorModal(serverInfo, role) {
  const isNew = role === null;
  const currentPerms = role ? role.permissions : 0;

  const permRowsHtml = Object.entries(PERMISSION_LABELS).map(([key, [label, desc]]) => {
    const flag = PERMISSIONS[key];
    const on = (currentPerms & flag) === flag;
    return `
      <div class="permission-row" data-flag="${flag}">
        <div>
          <div class="perm-name">${label}</div>
          <div class="perm-desc">${desc}</div>
        </div>
        <div class="toggle ${on ? 'on' : ''}"></div>
      </div>
    `;
  }).join('');

  openModal(`
    <h2>${isNew ? 'New Role' : 'Edit Role'}</h2>
    <label>Role Name</label>
    <input type="text" id="role-name-input" value="${role ? escapeHtml(role.name) : 'new-role'}" ${role && role.is_default ? 'disabled' : ''}>
    <label>Role Color</label>
    <input type="color" id="role-color-input" value="${role ? role.color : '#99aab5'}" style="height:40px; padding:4px;">
    <label>Permissions</label>
    <div id="perm-rows">${permRowsHtml}</div>
    <div class="modal-actions">
      ${!isNew && !role.is_default ? '<button type="button" class="btn-danger" id="delete-role-btn">Delete</button>' : ''}
      <button type="button" class="btn-secondary" id="cancel-role-edit">Cancel</button>
      <button type="button" class="btn-primary" id="save-role-btn">Save</button>
    </div>
  `);

  document.querySelectorAll('#perm-rows .permission-row').forEach(row => {
    row.addEventListener('click', () => {
      row.querySelector('.toggle').classList.toggle('on');
    });
  });

  document.getElementById('cancel-role-edit').addEventListener('click', () => openRolesModal(serverInfo));

  document.getElementById('save-role-btn').addEventListener('click', async () => {
    const name = document.getElementById('role-name-input').value.trim();
    const color = document.getElementById('role-color-input').value;
    let permissions = 0;
    document.querySelectorAll('#perm-rows .permission-row').forEach(row => {
      if (row.querySelector('.toggle').classList.contains('on')) {
        permissions |= parseInt(row.dataset.flag, 10);
      }
    });

    let json;
    if (isNew) {
      json = await apiPost('roles.php?action=create', { server_id: serverInfo.id, name, color, permissions });
    } else {
      json = await apiPost('roles.php?action=update', { role_id: role.id, name, color, permissions });
    }
    if (!json.success) { alert(json.error); return; }
    openRolesModal(serverInfo);
  });

  const deleteBtn = document.getElementById('delete-role-btn');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', async () => {
      if (!confirm(`Delete role "${role.name}"?`)) return;
      const json = await apiPost('roles.php?action=delete', { role_id: role.id });
      if (json.success) openRolesModal(serverInfo);
      else alert(json.error);
    });
  }
}

/* ============================================================
   Channel permission overrides
   ============================================================ */
async function openChannelPermissionsModal(serverInfo, channel) {
  const [rolesJson, permsJson] = await Promise.all([
    apiGet(`roles.php?action=list&server_id=${serverInfo.id}`),
    apiGet(`channels.php?action=permissions&channel_id=${channel.id}`),
  ]);
  if (!rolesJson.success) { alert(rolesJson.error); return; }
  if (!permsJson.success) { alert(permsJson.error); return; }
  if (rolesJson.roles.length === 0) { alert('This server has no roles to set overrides for.'); return; }

  const overridesByRole = {};
  permsJson.overrides.forEach(o => {
    overridesByRole[o.role_id] = { allow: o.allow, deny: o.deny };
  });

  renderChannelPermissionsModal(serverInfo, channel, rolesJson.roles, permsJson.flags, overridesByRole, rolesJson.roles[0].id);
}

function renderChannelPermissionsModal(serverInfo, channel, roles, flags, overridesByRole, selectedRoleId) {
  const selected = overridesByRole[selectedRoleId] || { allow: 0, deny: 0 };

  const roleOptionsHtml = roles.map(r =>
    `<option value="${r.id}" ${r.id === selectedRoleId ? 'selected' : ''}>${escapeHtml(r.name)}</option>`
  ).join('');

  const rowsHtml = Object.entries(flags).map(([key, flag]) => {
    const meta = PERMISSION_LABELS[key];
    const label = meta ? meta[0] : key;
    const desc = meta ? meta[1] : '';
    let rowState = 'inherit';
    if (selected.allow & flag) rowState = 'allow';
    else if (selected.deny & flag) rowState = 'deny';
    return `
      <div class="permission-row" style="cursor:default;">
        <div>
          <div class="perm-name">${label}</div>
          <div class="perm-desc">${desc}</div>
        </div>
        <button type="button" class="tristate state-${rowState}" data-flag="${flag}" data-state="${rowState}">
          ${rowState === 'allow' ? '✓ Allow' : rowState === 'deny' ? '✕ Deny' : 'Inherit'}
        </button>
      </div>
    `;
  }).join('');

  openModal(`
    <h2>#${escapeHtml(channel.name)} Permissions</h2>
    <p class="modal-sub">Per-role overrides for this channel — these take priority over the role's server-wide permissions here. Click a permission to cycle Inherit → Allow → Deny.</p>
    <label>Role</label>
    <select id="perm-role-select">${roleOptionsHtml}</select>
    <div id="channel-perm-rows" style="margin-top:12px;">${rowsHtml}</div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-channel-perms-btn">Close</button>
      <button type="button" class="btn-primary" id="save-channel-perms-btn">Save</button>
    </div>
  `, { wide: true });

  document.getElementById('close-channel-perms-btn').addEventListener('click', closeModal);

  document.getElementById('perm-role-select').addEventListener('change', (e) => {
    renderChannelPermissionsModal(serverInfo, channel, roles, flags, overridesByRole, parseInt(e.target.value, 10));
  });

  document.querySelectorAll('#channel-perm-rows .tristate').forEach(btn => {
    btn.addEventListener('click', () => {
      const order = ['inherit', 'allow', 'deny'];
      const next = order[(order.indexOf(btn.dataset.state) + 1) % order.length];
      btn.dataset.state = next;
      btn.className = `tristate state-${next}`;
      btn.textContent = next === 'allow' ? '✓ Allow' : next === 'deny' ? '✕ Deny' : 'Inherit';
    });
  });

  document.getElementById('save-channel-perms-btn').addEventListener('click', async () => {
    let allow = 0;
    let deny = 0;
    document.querySelectorAll('#channel-perm-rows .tristate').forEach(btn => {
      const flag = parseInt(btn.dataset.flag, 10);
      if (btn.dataset.state === 'allow') allow |= flag;
      else if (btn.dataset.state === 'deny') deny |= flag;
    });
    const roleId = parseInt(document.getElementById('perm-role-select').value, 10);
    const json = await apiPost('channels.php?action=set_permission', { channel_id: channel.id, role_id: roleId, allow, deny });
    if (!json.success) { alert(json.error); return; }
    overridesByRole[roleId] = { allow, deny };
    closeModal();
  });
}

/* ============================================================
   User profile card (click a member in the member list)
   ============================================================ */
/** Small badge row shared by the popover and full profile: site badge, Nitro, BOT tag, then any custom badges. */
function profileBadgesHtml(profile) {
  const custom = (profile.custom_badges || []).map(b =>
    `<img class="custom-badge-icon" src="${b.icon_url}" alt="${escapeHtml(b.name)}" title="${escapeHtml(b.name)}">`
  ).join('');
  return `${badgeIconHtml(profile.site_badge)}${nitroBadgeHtml(profile.nitro_until)}${botTagHtml(profile.is_bot)}${custom}`;
}

function formatShortDate(isoString) {
  const d = new Date(isoString.replace(' ', 'T') + 'Z');
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

/** "X Mutual Friends • Y Mutual Servers" — omits whichever side has no data (e.g. viewing your own profile). */
function profileMutualHtml(profile) {
  const parts = [];
  if (typeof profile.mutual_friends === 'number') {
    parts.push(`👥 ${profile.mutual_friends} Mutual Friend${profile.mutual_friends === 1 ? '' : 's'}`);
  }
  if (profile.mutual_servers) {
    parts.push(`🌐 ${profile.mutual_servers.length} Mutual Server${profile.mutual_servers.length === 1 ? '' : 's'}`);
  }
  if (!parts.length) return '';
  return `<div class="profile-mutual-row">${parts.join(' &nbsp;•&nbsp; ')}</div>`;
}

/** Two dates side by side: Discordish account created, and (if viewed in server context) when they joined this server. */
function profileMemberSinceHtml(profile) {
  const accountDate = formatShortDate(profile.created_at);
  const joinedDate = profile.joined_at ? formatShortDate(profile.joined_at) : null;
  return `
    <div class="profile-label">Member Since</div>
    <div class="profile-since-row">
      <span>🖥️ ${accountDate}</span>
      ${joinedDate ? `<span class="profile-since-sep">•</span><span>🛡️ ${joinedDate}</span>` : ''}
    </div>
  `;
}

function profileFriendsSinceHtml(profile) {
  if (!profile.friend || profile.friend.status !== 'friends' || !profile.friend.since) return '';
  return `
    <div class="profile-label" style="margin-top:10px;">Friends Since</div>
    <div class="profile-since-row"><span>${formatShortDate(profile.friend.since)}</span></div>
  `;
}

function profileRolesPillsHtml(profile) {
  return (profile.roles || [])
    .filter(r => r.name !== '@everyone')
    .map(r => `<span class="role-pill" style="background:${r.color};color:#1e1f22;">${escapeHtml(r.name)}</span>`)
    .join('') || '<span class="profile-empty">No roles yet</span>';
}

function profileFriendBtnHtml(profile) {
  if (profile.id === state.me.id || !profile.friend) return '';
  switch (profile.friend.status) {
    case 'friends':
      return `<button type="button" class="btn-secondary profile-friend-btn" data-action="remove">✓ Friends</button>`;
    case 'incoming':
      return `<button type="button" class="btn-primary profile-friend-btn" data-action="accept">Accept Request</button>`;
    case 'outgoing':
      return `<button type="button" class="btn-secondary profile-friend-btn" data-action="cancel">Cancel Request</button>`;
    default:
      return `<button type="button" class="btn-primary profile-friend-btn" data-action="add">Add Friend</button>`;
  }
}

/** Binds a `.profile-friend-btn` found anywhere inside `container`. `onDone` runs after a successful action (re-render). */
function bindFriendBtn(container, profile, onDone) {
  const friendBtn = container.querySelector('.profile-friend-btn');
  if (!friendBtn) return;
  friendBtn.addEventListener('click', async () => {
    const action = friendBtn.dataset.action;
    let json;
    if (action === 'add') json = await apiPost('friends.php?action=send_request', { username: profile.username });
    else if (action === 'accept') json = await apiPost('friends.php?action=accept_request', { request_id: profile.friend.request_id });
    else if (action === 'cancel') json = await apiPost('friends.php?action=remove_request', { request_id: profile.friend.request_id });
    else if (action === 'remove') {
      if (!confirm(`Remove ${profile.username} from your friends?`)) return;
      json = await apiPost('friends.php?action=remove_friend', { user_id: profile.id });
    }
    if (!json || !json.success) { alert(json ? json.error : 'Something went wrong.'); return; }
    state.friendsData = null; // stale — reload next time Friends view is opened
    if (onDone) onDone();
  });
}

/** After a role change: refresh the member list, and if it was your own roles in the currently open server, reload permissions too (so gated UI like channel management updates immediately). */
function refreshAfterRoleChange(targetUserId, serverId) {
  if (serverId !== state.activeServerId) return;
  if (targetUserId === state.me.id) {
    openServer(serverId); // also refreshes state.myPermissions
  } else {
    loadMembers(serverId);
  }
}

async function openUserProfileModal(userId, serverId) {
  const json = await apiGet(`users.php?action=profile&user_id=${userId}&server_id=${serverId || ''}`);
  if (!json.success) { alert(json.error); return; }
  renderUserProfileModal(json.profile, serverId);
}

function renderUserProfileModal(profile, serverId) {
  const isMe = profile.id === state.me.id;
  const accent = profile.accent_color || '#5865f2';
  const displayName = profile.nickname || profile.username;

  const manageRolesHtml = (profile.all_roles) ? `
    <label style="margin-top:16px;">Roles</label>
    <div class="role-assign-rows">
      ${profile.all_roles.filter(r => !r.is_default).map(r => {
        const on = (profile.roles || []).some(pr => pr.id === r.id);
        return `
          <div class="permission-row" data-role-id="${r.id}">
            <div class="perm-name" style="display:flex;align-items:center;gap:8px;">
              <span class="role-pill" style="background:${r.color};color:#1e1f22;">${escapeHtml(r.name)}</span>
            </div>
            <div class="toggle ${on ? 'on' : ''}"></div>
          </div>
        `;
      }).join('') || '<p class="modal-sub" style="margin:8px 0 0;">No assignable roles yet — create one in Server Settings.</p>'}
    </div>
  ` : '';

  const bannerStyle = profile.banner_url
    ? `background-image:url(${profile.banner_url});`
    : `background-color:${accent};`;

  openModal(`
    <div class="profile-banner" style="${bannerStyle}"></div>
    <div class="profile-card">
      <img class="avatar profile-avatar" src="${avatarUrl(profile.avatar_url, profile.username)}">
      <h2 style="margin-top:8px;">${escapeHtml(displayName)}${profileBadgesHtml(profile)}</h2>
      ${profile.nickname ? `<div class="profile-username-sub">@${escapeHtml(profile.username)}</div>` : ''}
      ${profile.pronouns ? `<div class="profile-pronouns">${escapeHtml(profile.pronouns)}</div>` : ''}
      ${!isMe ? profileMutualHtml(profile) : ''}

      ${profile.bio ? `
        <div class="profile-section">
          <div class="profile-label">About Me</div>
          <p class="profile-bio">${escapeHtml(profile.bio)}</p>
        </div>
      ` : ''}

      ${profile.roles ? `
        <div class="profile-section">
          <div class="profile-label">Roles</div>
          <div class="profile-roles">${profileRolesPillsHtml(profile)}</div>
        </div>
      ` : ''}

      <div class="profile-section">
        ${profileMemberSinceHtml(profile)}
        ${profileFriendsSinceHtml(profile)}
      </div>

      ${manageRolesHtml}
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-profile-btn">Close</button>
      ${profileFriendBtnHtml(profile)}
      ${!isMe ? '<button type="button" class="btn-secondary profile-report-btn">🚩 Report</button>' : ''}
      ${!isMe ? '<button type="button" class="btn-primary" id="profile-message-btn">Send Message</button>' : ''}
    </div>
  `);

  const modalRoot = el.modalContent;
  document.getElementById('close-profile-btn').addEventListener('click', closeModal);
  bindFriendBtn(modalRoot, profile, () => openUserProfileModal(profile.id, serverId));

  const messageBtn = document.getElementById('profile-message-btn');
  if (messageBtn) {
    messageBtn.addEventListener('click', () => {
      closeModal();
      startDM(profile.id);
    });
  }

  const reportBtn = modalRoot.querySelector('.profile-report-btn');
  if (reportBtn) {
    reportBtn.addEventListener('click', () => openReportModal(profile.id, profile.username, null));
  }

  modalRoot.querySelectorAll('.role-assign-rows .permission-row').forEach(row => {
    row.addEventListener('click', async () => {
      const roleId = parseInt(row.dataset.roleId, 10);
      const toggle = row.querySelector('.toggle');
      const nowOn = !toggle.classList.contains('on');
      const action = nowOn ? 'assign' : 'unassign';
      const json = await apiPost(`roles.php?action=${action}`, { role_id: roleId, user_id: profile.id });
      if (!json.success) { alert(json.error); return; }
      toggle.classList.toggle('on');
      refreshAfterRoleChange(profile.id, serverId);
    });
  });
}

/* ============================================================
   Compact profile popover (Discord-style mini card on click)
   ============================================================ */
async function openUserProfilePopover(anchorEl, userId, serverId) {
  document.querySelectorAll('.profile-popover').forEach(p => p.remove());
  const json = await apiGet(`users.php?action=profile&user_id=${userId}&server_id=${serverId || ''}`);
  if (!json.success) { alert(json.error); return; }
  renderUserProfilePopover(anchorEl, json.profile, serverId);
}

function renderUserProfilePopover(anchorEl, profile, serverId) {
  document.querySelectorAll('.profile-popover').forEach(p => p.remove());
  const isMe = profile.id === state.me.id;
  const accent = profile.accent_color || '#5865f2';
  const displayName = profile.nickname || profile.username;

  const editableRoles = (profile.all_roles);
  const rolesChipsHtml = editableRoles ? `
    <div class="profile-role-chip-row" id="popover-role-chips">
      ${(profile.roles || []).filter(r => r.name !== '@everyone').map(r => `
        <span class="role-pill role-pill-removable" style="background:${r.color};color:#1e1f22;">
          ${escapeHtml(r.name)}<span class="role-pill-x" data-remove-role="${r.id}">×</span>
        </span>
      `).join('')}
      <button type="button" class="role-pill-add" id="popover-add-role-btn">+</button>
      <div class="role-add-dropdown" id="popover-role-dropdown" hidden>
        ${profile.all_roles.filter(r => !r.is_default && !(profile.roles || []).some(pr => pr.id === r.id)).map(r => `
          <div class="role-add-option" data-add-role="${r.id}" style="color:${r.color};">${escapeHtml(r.name)}</div>
        `).join('') || '<div class="role-add-option" style="color:var(--text-muted);">No more roles</div>'}
      </div>
    </div>
  ` : `<div class="profile-role-chip-row">${profileRolesPillsHtml(profile)}</div>`;

  const popoverBannerStyle = profile.banner_url
    ? `background-image:url(${profile.banner_url});`
    : `background-color:${accent};`;

  const popover = document.createElement('div');
  popover.className = 'profile-popover';
  popover.innerHTML = `
    <div class="profile-banner profile-banner-popover" style="${popoverBannerStyle}"></div>
    <div class="profile-popover-body">
      <img class="avatar profile-popover-avatar" src="${avatarUrl(profile.avatar_url, profile.username)}">
      <div class="profile-popover-name">${escapeHtml(displayName)}${profileBadgesHtml(profile)}</div>
      <div class="profile-popover-sub">${profile.nickname ? `@${escapeHtml(profile.username)}` : ''}${profile.pronouns ? ` · ${escapeHtml(profile.pronouns)}` : ''}</div>
      ${!isMe ? profileMutualHtml(profile) : ''}
      ${profile.bio ? `<div class="profile-popover-bio">${escapeHtml(profile.bio)}</div>` : ''}
      <div class="profile-section">${profileMemberSinceHtml(profile)}</div>
      ${profileFriendsSinceHtml(profile)}
      ${profile.roles ? `<div class="profile-label" style="margin-top:10px;">Roles</div>${rolesChipsHtml}` : ''}
      <div class="profile-popover-actions">
        ${profileFriendBtnHtml(profile)}
        ${!isMe ? '<button type="button" class="btn-secondary profile-report-btn" title="Report">🚩</button>' : ''}
        <button type="button" class="btn-secondary" id="popover-expand-btn" title="Full Profile">⛶</button>
      </div>
      ${!isMe ? `
        <form id="popover-message-form" class="popover-message-form">
          <input type="text" id="popover-message-input" placeholder="Message @${escapeHtml(profile.username)}" maxlength="4000">
          <button type="submit">➤</button>
        </form>
      ` : ''}
    </div>
  `;
  document.body.appendChild(popover);
  positionFloatingPopup(popover, anchorEl, 320);

  popover.querySelector('#popover-expand-btn').addEventListener('click', () => {
    popover.remove();
    openUserProfileModal(profile.id, serverId);
  });

  bindFriendBtn(popover, profile, () => renderUserProfilePopoverRefresh(anchorEl, profile.id, serverId));

  const reportBtn = popover.querySelector('.profile-report-btn');
  if (reportBtn) {
    reportBtn.addEventListener('click', () => {
      popover.remove();
      openReportModal(profile.id, profile.username, null);
    });
  }

  const msgForm = popover.querySelector('#popover-message-form');
  if (msgForm) {
    msgForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = document.getElementById('popover-message-input');
      const content = input.value.trim();
      if (!content) return;
      const startJson = await apiPost('dm.php?action=start', { user_id: profile.id });
      if (!startJson.success) { alert(startJson.error); return; }
      await apiPost('dm.php?action=send', { conversation_id: startJson.conversation.id, content });
      popover.remove();
      await loadConversations();
      showDMHome();
      openConversation(startJson.conversation.id, startJson.other_user);
    });
  }

  if (editableRoles) {
    const addBtn = popover.querySelector('#popover-add-role-btn');
    const dropdown = popover.querySelector('#popover-role-dropdown');
    addBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dropdown.hidden = !dropdown.hidden;
    });
    popover.querySelectorAll('[data-add-role]').forEach(opt => {
      opt.addEventListener('click', async () => {
        const roleId = parseInt(opt.dataset.addRole, 10);
        const json = await apiPost('roles.php?action=assign', { role_id: roleId, user_id: profile.id });
        if (!json.success) { alert(json.error); return; }
        refreshAfterRoleChange(profile.id, serverId);
        renderUserProfilePopoverRefresh(anchorEl, profile.id, serverId);
      });
    });
    popover.querySelectorAll('[data-remove-role]').forEach(x => {
      x.addEventListener('click', async (e) => {
        e.stopPropagation();
        const roleId = parseInt(x.dataset.removeRole, 10);
        const json = await apiPost('roles.php?action=unassign', { role_id: roleId, user_id: profile.id });
        if (!json.success) { alert(json.error); return; }
        refreshAfterRoleChange(profile.id, serverId);
        renderUserProfilePopoverRefresh(anchorEl, profile.id, serverId);
      });
    });
  }
}

async function renderUserProfilePopoverRefresh(anchorEl, userId, serverId) {
  const json = await apiGet(`users.php?action=profile&user_id=${userId}&server_id=${serverId || ''}`);
  if (!json.success) return;
  renderUserProfilePopover(anchorEl, json.profile, serverId);
}

/** Positions a floating popup (profile card, etc.) below `anchorEl`, clamped to stay on-screen, and closes it on an outside click. */
function positionFloatingPopup(popupEl, anchorEl, width) {
  popupEl.style.position = 'absolute';
  popupEl.style.width = `${width}px`;
  popupEl.style.zIndex = '80';
  const rect = anchorEl.getBoundingClientRect();
  let left = rect.left + window.scrollX;
  const maxLeft = window.scrollX + document.documentElement.clientWidth - width - 8;
  left = Math.max(8, Math.min(left, maxLeft));
  popupEl.style.left = `${left}px`;
  popupEl.style.top = `${rect.bottom + window.scrollY + 6}px`;

  setTimeout(() => {
    document.addEventListener('click', function onDocClick(e) {
      if (!popupEl.contains(e.target) && e.target !== anchorEl && !anchorEl.contains(e.target)) {
        popupEl.remove();
        document.removeEventListener('click', onDocClick);
      }
    });
  }, 0);
}

/* ============================================================
   User settings (avatar, bio, pronouns, accent color)
   ============================================================ */
function openUserSettingsModal() {
  const me = state.me;
  const nitroActive = isNitroActive(me.nitro_until);
  const bannerStyle = me.banner_url
    ? `background-image:url(${me.banner_url});`
    : `background-color:${me.accent_color || '#5865f2'};`;

  const bannerSectionHtml = nitroActive ? `
    <label>Profile Banner <span class="nitro-badge" title="Nitro perk">⚡</span></label>
    <div class="settings-banner-preview" id="settings-banner-preview" style="${bannerStyle}"></div>
    <div class="settings-banner-actions">
      <button type="button" class="btn-secondary" id="settings-change-banner-btn">Change Banner</button>
      <button type="button" class="btn-secondary" id="settings-remove-banner-btn" ${me.banner_url ? '' : 'hidden'}>Remove Banner</button>
    </div>
  ` : `
    <label>Profile Banner</label>
    <div class="settings-banner-preview settings-banner-locked" id="settings-banner-preview" style="${bannerStyle}">
      <span>⚡ Custom banners are a Nitro perk</span>
    </div>
    <button type="button" class="btn-secondary" id="settings-get-nitro-btn" style="width:100%;">Get Nitro</button>
  `;

  openModal(`
    <h2>User Settings</h2>
    <p class="modal-sub">Personalize your profile.</p>

    <div class="settings-avatar-row">
      <img class="avatar profile-avatar" id="settings-avatar-preview" src="${avatarUrl(me.avatar_url, me.username)}">
      <div>
        <div class="user-panel-name" style="font-size:15px;">${escapeHtml(me.username)}</div>
        <button type="button" class="btn-secondary" id="settings-change-avatar-btn" style="margin-top:6px;">Change Avatar</button>
      </div>
    </div>

    ${bannerSectionHtml}

    <label>Pronouns</label>
    <input type="text" id="settings-pronouns" maxlength="40" placeholder="e.g. she/her, they/them" value="${escapeHtml(me.pronouns || '')}">

    <label>About Me</label>
    <textarea id="settings-bio" maxlength="190" rows="3" placeholder="Tell people a bit about yourself...">${escapeHtml(me.bio || '')}</textarea>

    <label>Profile Accent Color</label>
    <input type="color" id="settings-accent-color" value="${me.accent_color || '#5865f2'}" style="height:40px; padding:4px;">

    <button type="button" class="btn-secondary" id="view-standing-btn" style="margin-top:16px; width:100%;">🛡️ View Account Standing</button>
    <button type="button" class="btn-secondary" id="open-dev-portal-btn" style="margin-top:8px; width:100%;">🤖 Developer Portal</button>

    <div id="settings-error" class="auth-error" hidden></div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-settings-modal-btn">Cancel</button>
      <button type="button" class="btn-primary" id="save-settings-btn">Save Changes</button>
    </div>
  `);

  document.getElementById('close-settings-modal-btn').addEventListener('click', closeModal);
  document.getElementById('settings-change-avatar-btn').addEventListener('click', () => el.avatarInput.click());
  document.getElementById('view-standing-btn').addEventListener('click', openAccountStandingModal);
  document.getElementById('open-dev-portal-btn').addEventListener('click', openDeveloperPortalModal);

  const changeBannerBtn = document.getElementById('settings-change-banner-btn');
  if (changeBannerBtn) changeBannerBtn.addEventListener('click', () => el.bannerInput.click());
  const removeBannerBtn = document.getElementById('settings-remove-banner-btn');
  if (removeBannerBtn) removeBannerBtn.addEventListener('click', handleBannerRemove);
  const getNitroBtn = document.getElementById('settings-get-nitro-btn');
  if (getNitroBtn) getNitroBtn.addEventListener('click', () => { closeModal(); openQuestsModal(); });

  document.getElementById('save-settings-btn').addEventListener('click', async () => {
    const bio = document.getElementById('settings-bio').value.trim();
    const pronouns = document.getElementById('settings-pronouns').value.trim();
    const accent_color = document.getElementById('settings-accent-color').value;

    const json = await apiPost('auth.php?action=update_profile', { bio, pronouns, accent_color });
    if (!json.success) {
      const err = document.getElementById('settings-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    state.me = json.user;
    closeModal();
  });
}

/* ============================================================
   Developer Portal (bot accounts)
   ============================================================ */
async function openDeveloperPortalModal() {
  const json = await apiGet('bots.php?action=list');
  if (!json.success) { alert(json.error); return; }
  renderDeveloperPortalModal(json.bots);
}

function renderDeveloperPortalModal(bots) {
  const rows = bots.map(b => `
    <div class="quest-card" style="margin-bottom:10px;">
      <img class="avatar avatar-sm" src="${avatarUrl(b.avatar_url, b.username)}" alt="" style="margin-right:4px;">
      <div class="quest-card-info">
        <div class="quest-card-title">${escapeHtml(b.username)}<span class="bot-tag" style="margin-left:6px;">BOT</span></div>
        <div class="quest-card-sub">Created ${new Date(b.created_at.replace(' ', 'T') + 'Z').toLocaleDateString()}</div>
      </div>
      <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <button type="button" class="btn-secondary" data-invite="${b.id}" data-bname="${escapeHtml(b.username)}">🔗 Invite Link</button>
        <button type="button" class="btn-secondary" data-servers="${b.id}" data-bname="${escapeHtml(b.username)}">🌐 Servers</button>
        <button type="button" class="btn-secondary" data-automations="${b.id}" data-bname="${escapeHtml(b.username)}">⚙ Automations</button>
        <button type="button" class="btn-secondary" data-script="${b.id}" data-bname="${escapeHtml(b.username)}">&lt;/&gt; Script</button>
        <button type="button" class="btn-secondary" data-regen="${b.id}">New Token</button>
        <button type="button" class="btn-secondary" data-delete="${b.id}" data-name="${escapeHtml(b.username)}">Delete</button>
      </div>
    </div>
  `).join('') || '<p class="modal-sub">No bots yet — create one below.</p>';

  openModal(`
    <h2>🤖 Developer Portal</h2>
    <p class="modal-sub">A bot is a regular account that authenticates with a token instead of a password. Add
      <code>Authorization: Bot &lt;token&gt;</code> to any API request — send a message via
      <code>POST api/messages.php?action=send</code>, or poll <code>GET api/messages.php?action=list&amp;channel_id=&lt;id&gt;&amp;after_id=&lt;id&gt;</code>
      for new ones. It only has the permissions its server role grants it, same as any member. Use
      <strong>🔗 Invite Link</strong> below to add it to a server — share that link with anyone who has Manage Server
      permission there, they don't need the bot's token.</p>
    <div id="dev-bots-list">${rows}</div>
    <div class="modal-actions" style="justify-content:flex-start; gap:10px;">
      <input type="text" id="new-bot-name" placeholder="Bot name" maxlength="32" style="flex:1;">
      <button type="button" class="btn-primary" id="create-bot-btn">Create Bot</button>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-dev-portal-btn">Close</button>
    </div>
  `, { wide: true });

  document.getElementById('close-dev-portal-btn').addEventListener('click', closeModal);

  document.getElementById('create-bot-btn').addEventListener('click', async () => {
    const nameInput = document.getElementById('new-bot-name');
    const name = nameInput.value.trim();
    if (!name) return;
    const json = await apiPost('bots.php?action=create', { name });
    if (!json.success) { alert(json.error); return; }
    showBotTokenModal(json.bot.username, json.bot.token);
  });

  document.querySelectorAll('[data-regen]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const botId = parseInt(btn.dataset.regen, 10);
      if (!confirm('Generate a new token? The old token stops working immediately.')) return;
      const json = await apiPost('bots.php?action=regenerate_token', { bot_id: botId });
      if (!json.success) { alert(json.error); return; }
      showBotTokenModal(null, json.token);
    });
  });

  document.querySelectorAll('[data-delete]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const botId = parseInt(btn.dataset.delete, 10);
      if (!confirm(`Delete bot "${btn.dataset.name}"? This can't be undone — it leaves every server and its message history is deleted.`)) return;
      const json = await apiPost('bots.php?action=delete', { bot_id: botId });
      if (!json.success) { alert(json.error); return; }
      await openDeveloperPortalModal();
    });
  });

  document.querySelectorAll('[data-automations]').forEach(btn => {
    btn.addEventListener('click', () => openBotAutomationsModal(parseInt(btn.dataset.automations, 10), btn.dataset.bname));
  });

  document.querySelectorAll('[data-script]').forEach(btn => {
    btn.addEventListener('click', () => openBotScriptModal(parseInt(btn.dataset.script, 10), btn.dataset.bname));
  });

  document.querySelectorAll('[data-invite]').forEach(btn => {
    btn.addEventListener('click', () => showBotInviteLinkModal(parseInt(btn.dataset.invite, 10), btn.dataset.bname));
  });

  document.querySelectorAll('[data-servers]').forEach(btn => {
    btn.addEventListener('click', () => openBotServersModal(parseInt(btn.dataset.servers, 10), btn.dataset.bname));
  });
}

function showBotInviteLinkModal(botId, botName) {
  const link = `${location.origin}${location.pathname.split('/').slice(0, -1).join('/')}/add_bot.php?bot_id=${botId}`;
  openModal(`
    <h2>🔗 Invite ${escapeHtml(botName)}</h2>
    <p class="modal-sub">Share this link with anyone who has <strong>Manage Server</strong> permission on the server you want the bot in — they open it, pick a server, done. No token needed on their end.</p>
    <input type="text" readonly value="${escapeHtml(link)}" onclick="this.select()">
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-invite-link-btn">Close</button>
      <button type="button" class="btn-primary" id="copy-invite-link-btn">Copy Link</button>
    </div>
  `);
  document.getElementById('close-invite-link-btn').addEventListener('click', closeModal);
  document.getElementById('copy-invite-link-btn').addEventListener('click', async (e) => {
    try {
      await navigator.clipboard.writeText(link);
      e.target.textContent = 'Copied!';
      setTimeout(() => { e.target.textContent = 'Copy Link'; }, 1500);
    } catch {
      alert('Could not copy automatically — select the text above and copy manually.');
    }
  });
}

async function openBotServersModal(botId, botName) {
  const json = await apiGet(`bots.php?action=bot_servers&bot_id=${botId}`);
  if (!json.success) { alert(json.error); return; }
  renderBotServersModal(botId, botName, json.servers);
}

function renderBotServersModal(botId, botName, servers) {
  const rows = servers.map(s => `
    <div class="permission-row" style="cursor:default;">
      <div class="perm-name">${escapeHtml(s.name)}</div>
      <button type="button" class="btn-secondary" data-remove-server="${s.id}">Remove</button>
    </div>
  `).join('') || '<p class="modal-sub">Not in any servers yet — use the Invite Link to add it somewhere.</p>';

  openModal(`
    <h2>🌐 ${escapeHtml(botName)} — Servers</h2>
    <div id="bot-servers-list">${rows}</div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-bot-servers-btn">Close</button>
    </div>
  `);
  document.getElementById('close-bot-servers-btn').addEventListener('click', closeModal);
  document.querySelectorAll('[data-remove-server]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const serverId = parseInt(btn.dataset.removeServer, 10);
      if (!confirm(`Remove ${botName} from this server?`)) return;
      const json = await apiPost('bots.php?action=remove_from_server', { bot_id: botId, server_id: serverId });
      if (!json.success) { alert(json.error); return; }
      await openBotServersModal(botId, botName);
    });
  });
}

/** No-code GUI rule builder: "if message contains/equals/starts with X, reply Y" — runs automatically on your own server, no bot process needed. */
async function openBotAutomationsModal(botId, botName) {
  const json = await apiGet(`bots.php?action=rules_list&bot_id=${botId}`);
  if (!json.success) { alert(json.error); return; }
  renderBotAutomationsModal(botId, botName, json.rules);
}

function renderBotAutomationsModal(botId, botName, rules) {
  const triggerLabel = { contains: 'contains', equals: 'exactly equals', starts_with: 'starts with' };
  const rows = rules.map(r => `
    <div class="permission-row" style="cursor:default; align-items:flex-start;">
      <div>
        <div class="perm-name">If message ${triggerLabel[r.trigger_type]} "${escapeHtml(r.trigger_value)}"</div>
        <div class="perm-desc">Reply: ${escapeHtml(r.response_text)}</div>
      </div>
      <div style="display:flex; gap:6px; align-items:center;">
        <button type="button" class="toggle ${r.enabled ? 'on' : ''}" data-toggle-rule="${r.id}" data-enabled="${r.enabled ? 1 : 0}"></button>
        <button type="button" class="btn-secondary" data-del-rule="${r.id}">🗑</button>
      </div>
    </div>
  `).join('') || '<p class="modal-sub">No automations yet — add one below.</p>';

  openModal(`
    <h2>⚙ ${escapeHtml(botName)} — Automations <span class="bot-tag" style="background:var(--green);">BEGINNER</span></h2>
    <p class="modal-sub">Runs automatically on this server whenever someone sends a message — no bot process to host, works 24/7 as long as the site is up. Use <code>{user}</code> or <code>{server}</code> to fill in names, or <code>{random:a|b|c}</code> to reply with one of several options at random.</p>
    <div id="bot-rules-list">${rows}</div>

    <label style="margin-top:16px;">Quick Start</label>
    <select id="rule-preset-select">
      <option value="">Choose a template (optional)...</option>
      <option value="ping">🏓 Ping Pong — replies "Pong!" to !ping</option>
      <option value="greet">👋 Greeter — replies to "hello"</option>
      <option value="faq">❓ Help Command — replies to !help</option>
    </select>

    <label style="margin-top:12px;">New Automation</label>
    <select id="new-rule-trigger-type">
      <option value="contains">Message contains...</option>
      <option value="equals">Message exactly equals...</option>
      <option value="starts_with">Message starts with...</option>
    </select>
    <input type="text" id="new-rule-trigger-value" placeholder="Trigger text, e.g. !hello" maxlength="200" style="margin-top:8px;">
    <input type="text" id="new-rule-response" placeholder="Bot's reply, e.g. Hey {user}, welcome to {server}!" maxlength="2000" style="margin-top:8px;">
    <div id="bot-rule-error" class="auth-error" hidden></div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-automations-btn">Close</button>
      <button type="button" class="btn-primary" id="add-rule-btn">Add Automation</button>
    </div>
  `, { wide: true });

  document.getElementById('close-automations-btn').addEventListener('click', closeModal);

  const PRESETS = {
    ping: { type: 'equals', value: '!ping', response: 'Pong! 🏓' },
    greet: { type: 'contains', value: 'hello', response: 'Hey {user}! 👋 Welcome to {server}.' },
    faq: { type: 'equals', value: '!help', response: "Here's what I can do: just ask, or check the pinned messages!" },
  };
  document.getElementById('rule-preset-select').addEventListener('change', (e) => {
    const preset = PRESETS[e.target.value];
    if (!preset) return;
    document.getElementById('new-rule-trigger-type').value = preset.type;
    document.getElementById('new-rule-trigger-value').value = preset.value;
    document.getElementById('new-rule-response').value = preset.response;
  });

  document.getElementById('add-rule-btn').addEventListener('click', async () => {
    const trigger_type = document.getElementById('new-rule-trigger-type').value;
    const trigger_value = document.getElementById('new-rule-trigger-value').value.trim();
    const response_text = document.getElementById('new-rule-response').value.trim();
    const json = await apiPost('bots.php?action=rule_create', { bot_id: botId, trigger_type, trigger_value, response_text });
    if (!json.success) {
      const err = document.getElementById('bot-rule-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    await openBotAutomationsModal(botId, botName);
  });

  document.querySelectorAll('[data-toggle-rule]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const ruleId = parseInt(btn.dataset.toggleRule, 10);
      const newEnabled = btn.dataset.enabled !== '1';
      const json = await apiPost('bots.php?action=rule_set_enabled', { rule_id: ruleId, enabled: newEnabled });
      if (!json.success) { alert(json.error); return; }
      await openBotAutomationsModal(botId, botName);
    });
  });

  document.querySelectorAll('[data-del-rule]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this automation?')) return;
      const json = await apiPost('bots.php?action=rule_delete', { rule_id: parseInt(btn.dataset.delRule, 10) });
      if (!json.success) { alert(json.error); return; }
      await openBotAutomationsModal(botId, botName);
    });
  });
}

/** Code editor: a saved draft script for a self-hosted bot process. Never executed on the server — you download and run it yourself. */
/** Shared bot-helper runtime, prepended so the user's snippet can just call bot.send/bot.onMessage/etc — works identically in the browser sandbox and in a downloaded Node.js script. */
function buildBrowserWorkerSource(token, apiBase, userCode) {
  return `
const __realFetch = fetch;
self.fetch = undefined; // disabled so pasted code can't ride your session or call arbitrary URLs — only the bot.* helpers below can talk to the API

const API_BASE = ${JSON.stringify(apiBase)};
const TOKEN = ${JSON.stringify(token)};

async function __apiCall(path, method, body) {
  const res = await __realFetch(API_BASE + '/' + path, {
    method: method || 'GET',
    credentials: 'omit',
    headers: Object.assign({ Authorization: 'Bot ' + TOKEN }, body ? { 'Content-Type': 'application/json' } : {}),
    body: body ? JSON.stringify(body) : undefined,
  });
  return res.json();
}

const __lastIds = {};
const bot = {
  send: (channelId, content) => __apiCall('messages.php?action=send', 'POST', { channel_id: channelId, content }),
  editMessage: (messageId, content) => __apiCall('messages.php?action=edit', 'POST', { message_id: messageId, content }),
  react: (messageId, emoji) => __apiCall('messages.php?action=react', 'POST', { message_id: messageId, emoji }),
  listMessages: async (channelId, afterId) => {
    const r = await __apiCall('messages.php?action=list&channel_id=' + channelId + '&after_id=' + (afterId || 0), 'GET');
    return r.messages || [];
  },
  onMessage: (channelId, handler) => {
    __lastIds[channelId] = __lastIds[channelId] || 0;
    (async function poll() {
      try {
        const msgs = await bot.listMessages(channelId, __lastIds[channelId]);
        for (const m of msgs) {
          __lastIds[channelId] = Math.max(__lastIds[channelId], m.id);
          try { await handler(m); } catch (e) { bot.log('Handler error:', (e && e.message) || e); }
        }
      } catch (e) { bot.log('Poll error:', (e && e.message) || e); }
      setTimeout(poll, 3000);
    })();
  },
  sleep: (ms) => new Promise(r => setTimeout(r, ms)),
  log: (...args) => postMessage({ type: 'log', text: args.map(a => (typeof a === 'string' ? a : JSON.stringify(a))).join(' ') }),
};

(async () => {
  try {
${userCode}
  } catch (e) {
    postMessage({ type: 'error', text: String((e && e.stack) || e) });
  }
})();
`;
}

/** Same bot.* helper shape as the browser sandbox, but for a downloaded standalone Node.js (18+) script — the exact snippet the user wrote works unchanged in either place. */
function buildNodeDownloadSource(botName, apiBase, userCode) {
  return `// ${botName} — bot script (Node.js 18+, no npm packages needed)
// 1. Replace YOUR_BOT_TOKEN_HERE below with a real token (Developer Portal > New Token).
// 2. Run with: node bot.js
// 3. Keep this process running wherever you like — it does NOT run on the web server.

const TOKEN = 'YOUR_BOT_TOKEN_HERE';
const API_BASE = ${JSON.stringify(apiBase)};

async function __apiCall(path, method, body) {
  const res = await fetch(\`\${API_BASE}/\${path}\`, {
    method: method || 'GET',
    headers: Object.assign({ Authorization: \`Bot \${TOKEN}\` }, body ? { 'Content-Type': 'application/json' } : {}),
    body: body ? JSON.stringify(body) : undefined,
  });
  return res.json();
}

const __lastIds = {};
const bot = {
  send: (channelId, content) => __apiCall('messages.php?action=send', 'POST', { channel_id: channelId, content }),
  editMessage: (messageId, content) => __apiCall('messages.php?action=edit', 'POST', { message_id: messageId, content }),
  react: (messageId, emoji) => __apiCall('messages.php?action=react', 'POST', { message_id: messageId, emoji }),
  listMessages: async (channelId, afterId) => {
    const r = await __apiCall('messages.php?action=list&channel_id=' + channelId + '&after_id=' + (afterId || 0), 'GET');
    return r.messages || [];
  },
  onMessage: (channelId, handler) => {
    __lastIds[channelId] = __lastIds[channelId] || 0;
    (async function poll() {
      try {
        const msgs = await bot.listMessages(channelId, __lastIds[channelId]);
        for (const m of msgs) {
          __lastIds[channelId] = Math.max(__lastIds[channelId], m.id);
          try { await handler(m); } catch (e) { bot.log('Handler error:', (e && e.message) || e); }
        }
      } catch (e) { bot.log('Poll error:', (e && e.message) || e); }
      setTimeout(poll, 3000);
    })();
  },
  sleep: (ms) => new Promise(r => setTimeout(r, ms)),
  log: (...args) => console.log(...args),
};

(async () => {
${userCode}
})();
`;
}

let activeBotWorker = null;

function stopBotWorkerRun() {
  if (activeBotWorker) {
    activeBotWorker.terminate();
    activeBotWorker = null;
    appendScriptConsoleLine('info', '■ Stopped.');
  }
}

function appendScriptConsoleLine(type, text) {
  const consoleEl = document.getElementById('script-console');
  if (!consoleEl) return;
  const line = document.createElement('div');
  line.className = `script-console-line script-console-${type}`;
  line.textContent = text;
  consoleEl.appendChild(line);
  consoleEl.scrollTop = consoleEl.scrollHeight;
}

async function openBotScriptModal(botId, botName) {
  const json = await apiGet(`bots.php?action=get_script&bot_id=${botId}`);
  if (!json.success) { alert(json.error); return; }

  const defaultScript = `// Simple bot script — this exact snippet works both here ("Run in Browser")
// and downloaded as bot.js to run with Node.js anywhere.
//
// Available helpers:
//   bot.onMessage(channelId, async (msg) => { ... })  — call handler for every new message
//   bot.send(channelId, text)                         — send a message
//   bot.editMessage(messageId, text)                  — edit a message
//   bot.react(messageId, emoji)                       — add a reaction (e.g. '👍')
//   bot.log(...)                                       — print below (or to your terminal)
//   bot.sleep(ms)                                       — wait

const CHANNEL_ID = 0; // fill in the channel this bot should watch

bot.onMessage(CHANNEL_ID, async (msg) => {
  if (msg.content.toLowerCase() === '!ping') {
    await bot.send(CHANNEL_ID, 'Pong! 🏓');
  }
});
`;
  const script = json.script || defaultScript;
  const apiBase = `${location.origin}${location.pathname.split('/').slice(0, -1).join('/')}/api`;

  openModal(`
    <h2>&lt;/&gt; ${escapeHtml(botName)} — Code Editor <span class="bot-tag" style="background:#a855f7;">ADVANCED</span></h2>
    <p class="modal-sub">Write real JavaScript using the <code>bot.*</code> helpers (see the comments in the editor). <strong>Run in Browser</strong> tries it instantly, sandboxed — it can only reach this site's API using the token you paste below, nothing else on your computer or account. For something that keeps running after you close this tab, <strong>Download bot.js</strong> and run it with Node.js wherever you like.</p>

    <label>Bot Token <span style="font-weight:400; color:var(--text-muted);">(only used to test-run here — never saved)</span></label>
    <input type="text" id="script-run-token" placeholder="Paste a bot token to enable Run in Browser" autocomplete="off">

    <textarea id="bot-script-textarea" spellcheck="false" style="width:100%; min-height:280px; font-family:monospace; font-size:12.5px; white-space:pre; background:var(--bg-mid); color:var(--text-normal); border:1px solid var(--border); border-radius:var(--radius); padding:12px; margin-top:10px;">${escapeHtml(script)}</textarea>

    <label style="margin-top:10px;">Console</label>
    <div id="script-console" class="script-console"></div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-script-btn">Close</button>
      <button type="button" class="btn-secondary" id="download-script-btn">Download bot.js</button>
      <button type="button" class="btn-secondary" id="deploy-guide-btn">📦 Run 24/7</button>
      <button type="button" class="btn-secondary" id="save-script-btn">Save Draft</button>
      <button type="button" class="btn-secondary" id="stop-script-btn">■ Stop</button>
      <button type="button" class="btn-primary" id="run-script-btn">▶ Run in Browser</button>
    </div>
  `, { wide: true });

  document.getElementById('close-script-btn').addEventListener('click', () => {
    stopBotWorkerRun();
    closeModal();
  });

  document.getElementById('download-script-btn').addEventListener('click', () => {
    const code = document.getElementById('bot-script-textarea').value;
    const blob = new Blob([buildNodeDownloadSource(botName, apiBase, code)], { type: 'text/javascript' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'bot.js';
    a.click();
    URL.revokeObjectURL(url);
  });

  document.getElementById('save-script-btn').addEventListener('click', async () => {
    const value = document.getElementById('bot-script-textarea').value;
    const json2 = await apiPost('bots.php?action=save_script', { bot_id: botId, script: value });
    if (!json2.success) { alert(json2.error); return; }
    closeModal();
  });

  document.getElementById('stop-script-btn').addEventListener('click', stopBotWorkerRun);

  document.getElementById('deploy-guide-btn').addEventListener('click', () => openDeployGuideModal(botName));

  document.getElementById('run-script-btn').addEventListener('click', () => {
    const token = document.getElementById('script-run-token').value.trim();
    if (!token) { alert('Paste a bot token above first.'); return; }
    stopBotWorkerRun();
    document.getElementById('script-console').innerHTML = '';
    appendScriptConsoleLine('info', '▶ Starting…');

    const code = document.getElementById('bot-script-textarea').value;
    const src = buildBrowserWorkerSource(token, apiBase, code);
    const blob = new Blob([src], { type: 'application/javascript' });
    const workerUrl = URL.createObjectURL(blob);
    activeBotWorker = new Worker(workerUrl);
    activeBotWorker.addEventListener('message', (e) => {
      appendScriptConsoleLine(e.data.type === 'error' ? 'error' : 'log', e.data.text);
    });
    activeBotWorker.addEventListener('error', (e) => {
      appendScriptConsoleLine('error', e.message);
    });
  });
}

function buildPm2EcosystemConfig(botName) {
  const slug = botName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'bot';
  return `module.exports = {
  apps: [{
    name: '${slug}',
    script: './bot.js',
    autorestart: true,      // restarts automatically if it ever crashes
    watch: false,
    max_restarts: 20,
  }],
};
`;
}

function openDeployGuideModal(botName) {
  const config = buildPm2EcosystemConfig(botName);
  openModal(`
    <h2>📦 Run ${escapeHtml(botName)} 24/7</h2>
    <p class="modal-sub">"Run in Browser" only lasts while this tab is open, and a plain <code>node bot.js</code> in a terminal stops the moment you close it or your computer sleeps. To keep it running forever, run it as a proper background service with <a href="https://pm2.keymetrics.io/" target="_blank" rel="noopener">PM2</a> — it auto-restarts on crashes and can start on boot. Works on a VPS, a Raspberry Pi, or this same machine.</p>

    <label>1. Download bot.js (from the button behind this dialog) and ecosystem.config.js:</label>
    <textarea readonly spellcheck="false" style="width:100%; min-height:110px; font-family:monospace; font-size:12px; white-space:pre; background:var(--bg-mid); color:var(--text-normal); border:1px solid var(--border); border-radius:var(--radius); padding:10px;">${escapeHtml(config)}</textarea>
    <button type="button" class="btn-secondary" id="download-pm2-btn" style="margin-top:8px;">Download ecosystem.config.js</button>

    <label style="margin-top:16px;">2. On the machine that'll run it forever (any OS with Node.js 18+):</label>
    <textarea readonly spellcheck="false" style="width:100%; min-height:110px; font-family:monospace; font-size:12px; white-space:pre; background:var(--bg-mid); color:var(--text-normal); border:1px solid var(--border); border-radius:var(--radius); padding:10px;">npm install -g pm2
pm2 start ecosystem.config.js
pm2 save
pm2 startup   # prints a command to run once — makes it survive a reboot too</textarea>

    <p class="modal-sub" style="margin-top:14px;">Prefer not to run your own machine 24/7? Any small VPS works ($4-6/mo — DigitalOcean, Hetzner, etc.), or a free tier on Railway/Render (just point their "start command" at <code>node bot.js</code>, no PM2 needed there since they keep it running for you). A free Raspberry Pi at home works too — it just needs to stay powered on.</p>

    <div class="modal-actions">
      <button type="button" class="btn-primary" id="close-deploy-guide-btn">Got it</button>
    </div>
  `, { wide: true });

  document.getElementById('close-deploy-guide-btn').addEventListener('click', closeModal);
  document.getElementById('download-pm2-btn').addEventListener('click', () => {
    const blob = new Blob([config], { type: 'text/javascript' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ecosystem.config.js';
    a.click();
    URL.revokeObjectURL(url);
  });
}

function showBotTokenModal(username, token) {
  openModal(`
    <h2>${username ? `🤖 ${escapeHtml(username)} created!` : 'New Token Generated'}</h2>
    <p class="modal-sub">Copy this token now — it won't be shown again. Anyone with it can fully control this bot.</p>
    <input type="text" readonly value="${escapeHtml(token)}" style="font-family:monospace;" onclick="this.select()">
    <div class="modal-actions">
      <button type="button" class="btn-primary" id="close-token-modal-btn">Done</button>
    </div>
  `);
  document.getElementById('close-token-modal-btn').addEventListener('click', () => {
    closeModal();
    openDeveloperPortalModal();
  });
}

/* ============================================================
   Admin panel (site admins only)
   ============================================================ */
const adminState = { tab: 'users', query: '' };

/* ============================================================
   Account standing (a user's own ban/violation history)
   ============================================================ */
async function openAccountStandingModal() {
  const json = await apiGet('auth.php?action=standing');
  if (!json.success) { alert(json.error); return; }

  const steps = ['All good', 'Limited', 'Very limited', 'At risk', 'Suspended'];
  const stepIndex = json.severity ?? (json.suspended ? 4 : 0);
  const statusWord = json.suspended ? 'suspended' : (stepIndex > 0 ? 'limited' : 'all good');
  const statusColor = json.suspended ? 'var(--red)' : (stepIndex > 0 ? 'var(--yellow)' : 'var(--green)');
  const me = state.me;

  const stepperHtml = steps.map((label, i) => `
    <div class="standing-step">
      <div class="standing-dot ${i === stepIndex ? 'active' : ''} ${i < stepIndex ? 'passed' : ''}" style="${i === stepIndex ? `background:${statusColor};border-color:${statusColor};` : ''}"></div>
      <span>${label}</span>
    </div>
    ${i < steps.length - 1 ? '<div class="standing-line"></div>' : ''}
  `).join('');

  const violationRow = (v, isActive) => `
    <div class="standing-violation">
      <div class="standing-violation-icon">${isActive ? (v.type === 'ban' ? '⛔' : '⚠️') : '✓'}</div>
      <div>
        <div class="standing-violation-reason">${v.type === 'ban' ? 'Ban' : 'Warning'}: ${escapeHtml(v.reason)}</div>
        <div class="standing-violation-date">
          ${isActive ? 'Issued' : 'Resolved'} ${formatTime(isActive ? v.issued_at : v.resolved_at)}
          ${isActive && v.type === 'warning' && v.expires_at ? ` &middot; Expires ${formatTime(v.expires_at)}` : ''}
        </div>
      </div>
    </div>
  `;

  openModal(`
    <div class="standing-header">
      <img class="avatar profile-avatar" src="${avatarUrl(me.avatar_url, me.username)}">
      <div>
        <h2 style="margin:0;">Your account is <span style="color:${statusColor};">${statusWord}</span></h2>
        <p class="modal-sub" style="margin-top:4px;">
          ${json.suspended
            ? 'Your account has been suspended for violating the rules.'
            : stepIndex > 0
              ? 'Your account has active warnings. Repeated violations can lead to suspension.'
              : 'Thanks for following the rules. If you break them, it will show up here.'}
        </p>
      </div>
    </div>

    <div class="standing-stepper">${stepperHtml}</div>

    <div class="standing-section">
      <div class="standing-section-header" data-target="standing-active">
        <span>Active violations — ${json.active_violations.length}</span>
        <span class="standing-chevron">▾</span>
      </div>
      <div class="standing-section-body" id="standing-active">
        ${json.active_violations.length
          ? json.active_violations.map(v => violationRow(v, true)).join('')
          : '<p class="profile-empty">These affect your account status until they expire.</p>'}
      </div>
    </div>

    <div class="standing-section">
      <div class="standing-section-header" data-target="standing-expired">
        <span>Expired violations — ${json.expired_violations.length}</span>
        <span class="standing-chevron">▾</span>
      </div>
      <div class="standing-section-body" id="standing-expired" hidden>
        ${json.expired_violations.length
          ? json.expired_violations.map(v => violationRow(v, false)).join('')
          : '<p class="profile-empty">These no longer affect your account status.</p>'}
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-standing-btn">Close</button>
    </div>
  `, { wide: true });

  document.getElementById('close-standing-btn').addEventListener('click', closeModal);
  document.querySelectorAll('.standing-section-header').forEach(header => {
    header.addEventListener('click', () => {
      const body = document.getElementById(header.dataset.target);
      body.hidden = !body.hidden;
      header.querySelector('.standing-chevron').textContent = body.hidden ? '▾' : '▴';
    });
  });
}

async function openAdminPanelModal() {
  adminState.tab = 'users';
  adminState.query = '';
  await renderAdminPanel();
}

async function renderAdminPanel() {
  const [overviewJson, dataJson] = await Promise.all([
    apiGet('admin.php?action=overview'),
    adminState.tab === 'bans'
      ? apiGet('admin.php?action=bans')
      : adminState.tab === 'warnings'
        ? apiGet('admin.php?action=warnings')
        : adminState.tab === 'reports'
          ? apiGet('admin.php?action=reports&status=open')
          : adminState.tab === 'badges'
            ? apiGet('admin.php?action=custom_badges')
            : adminState.tab === 'servers'
              ? apiGet(`admin.php?action=servers&q=${encodeURIComponent(adminState.query)}`)
              : apiGet(`admin.php?action=users&q=${encodeURIComponent(adminState.query)}`),
  ]);

  if (!overviewJson.success) { alert(overviewJson.error); closeModal(); return; }
  if (!dataJson.success) { alert(dataJson.error); closeModal(); return; }

  const s = overviewJson.stats;
  const statsHtml = `
    <div class="admin-stats">
      <div class="admin-stat"><strong>${s.users}</strong><span>Users</span></div>
      <div class="admin-stat"><strong>${s.servers}</strong><span>Servers</span></div>
      <div class="admin-stat"><strong>${s.messages}</strong><span>Messages</span></div>
      <div class="admin-stat"><strong>${s.banned}</strong><span>Banned</span></div>
      <div class="admin-stat"><strong>${s.warned}</strong><span>Warned</span></div>
      <div class="admin-stat"><strong>${s.admins}</strong><span>Admins</span></div>
      <div class="admin-stat"><strong>${s.open_reports}</strong><span>Open Reports</span></div>
    </div>
  `;

  const bodyHtml = adminState.tab === 'bans'
    ? renderBanLogTable(dataJson.bans)
    : adminState.tab === 'warnings'
      ? renderWarningLogTable(dataJson.warnings)
      : adminState.tab === 'reports'
        ? renderReportsTable(dataJson.reports)
        : adminState.tab === 'badges'
          ? renderBadgesTable(dataJson.badges)
          : adminState.tab === 'servers'
            ? renderServersTable(dataJson.servers)
            : renderUserTable(dataJson.users);

  openModal(`
    <h2>Admin Panel</h2>
    <p class="modal-sub">Manage users, warnings, bans, and reports.</p>

    ${statsHtml}

    <div class="auth-tabs admin-tabs">
      <button class="auth-tab admin-tab ${adminState.tab === 'users' ? 'active' : ''}" data-tab="users">Users</button>
      <button class="auth-tab admin-tab ${adminState.tab === 'servers' ? 'active' : ''}" data-tab="servers">Servers</button>
      <button class="auth-tab admin-tab ${adminState.tab === 'reports' ? 'active' : ''}" data-tab="reports">Reports${s.open_reports ? ` (${s.open_reports})` : ''}</button>
      <button class="auth-tab admin-tab ${adminState.tab === 'warnings' ? 'active' : ''}" data-tab="warnings">Warning Log</button>
      <button class="auth-tab admin-tab ${adminState.tab === 'bans' ? 'active' : ''}" data-tab="bans">Ban Log</button>
      <button class="auth-tab admin-tab ${adminState.tab === 'badges' ? 'active' : ''}" data-tab="badges">Badges</button>
    </div>

    ${adminState.tab === 'users' || adminState.tab === 'servers' ? `
      <input type="text" id="admin-user-search" placeholder="${adminState.tab === 'servers' ? 'Search by server name or owner...' : 'Search by username or email...'}" value="${escapeHtml(adminState.query)}" style="margin-top:12px;">
    ` : ''}

    <div id="admin-panel-body" style="margin-top:14px;">${bodyHtml}</div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-admin-panel-btn">Close</button>
    </div>
  `, { wide: true });

  document.getElementById('close-admin-panel-btn').addEventListener('click', closeModal);

  document.querySelectorAll('.admin-tab').forEach(tab => {
    tab.addEventListener('click', async () => {
      adminState.tab = tab.dataset.tab;
      await renderAdminPanel();
    });
  });

  const searchInput = document.getElementById('admin-user-search');
  if (searchInput) {
    searchInput.focus();
    searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    let debounce;
    searchInput.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(async () => {
        adminState.query = searchInput.value.trim();
        await renderAdminPanel();
      }, 300);
    });
  }

  bindAdminRowActions();
  if (adminState.tab === 'badges') bindBadgesTabActions();
}

function renderUserTable(users) {
  if (users.length === 0) {
    return '<p class="profile-empty">No users found.</p>';
  }
  const rows = users.map(u => `
    <div class="admin-row" data-user-id="${u.id}">
      <img class="avatar avatar-sm" src="${avatarUrl(u.avatar_url, u.username)}" alt="">
      <div class="admin-row-info">
        <div class="user-panel-name">
          ${escapeHtml(u.username)}${badgeIconHtml(u.site_badge)}
          ${u.is_admin ? '<span class="badge badge-admin">Admin</span>' : ''}
          ${u.is_banned ? '<span class="badge badge-banned">Banned</span>' : ''}
        </div>
        <div class="user-panel-status">${escapeHtml(u.email)}</div>
      </div>
      <div class="admin-row-actions">
        <select class="admin-badge-select" data-user-id="${u.id}" title="Assign a site badge">
          <option value="none" ${u.site_badge === 'none' ? 'selected' : ''}>No badge</option>
          <option value="owner" ${u.site_badge === 'owner' ? 'selected' : ''}>Owner</option>
          <option value="co_owner" ${u.site_badge === 'co_owner' ? 'selected' : ''}>Co-Owner</option>
          <option value="staff" ${u.site_badge === 'staff' ? 'selected' : ''}>Staff</option>
          <option value="trusted" ${u.site_badge === 'trusted' ? 'selected' : ''}>Trusted User</option>
        </select>
        <button type="button" class="btn-secondary admin-manage-warnings-btn" data-user-id="${u.id}" data-username="${escapeHtml(u.username)}">Warnings</button>
        <button type="button" class="btn-secondary admin-manage-badges-btn" data-user-id="${u.id}" data-username="${escapeHtml(u.username)}">Badges</button>
        ${u.is_admin ? '' : (
          u.is_banned
            ? `<button type="button" class="btn-secondary admin-unban-btn" data-user-id="${u.id}">Unban</button>`
            : `
              <button type="button" class="btn-secondary admin-warn-btn" data-user-id="${u.id}" data-username="${escapeHtml(u.username)}">Warn</button>
              <button type="button" class="btn-danger admin-ban-btn" data-user-id="${u.id}" data-username="${escapeHtml(u.username)}">Ban</button>
            `
        )}
      </div>
    </div>
  `).join('');
  return `<div class="admin-table">${rows}</div>`;
}

function renderServersTable(servers) {
  if (servers.length === 0) {
    return '<p class="profile-empty">No servers found.</p>';
  }
  const rows = servers.map(s => `
    <div class="admin-row" data-server-id="${s.id}">
      <img class="avatar avatar-sm" src="${avatarUrl(s.icon_url, s.name)}" alt="">
      <div class="admin-row-info">
        <div class="user-panel-name">
          ${escapeHtml(s.name)}
          ${s.is_disabled ? '<span class="badge badge-banned">Disabled</span>' : ''}
        </div>
        <div class="user-panel-status">
          Owner: ${escapeHtml(s.owner_username)} &middot; ${s.member_count} member${s.member_count === 1 ? '' : 's'}
          ${s.is_disabled && s.disabled_reason ? ` &middot; Reason: ${escapeHtml(s.disabled_reason)}` : ''}
        </div>
      </div>
      <div class="admin-row-actions">
        ${s.is_disabled
          ? `<button type="button" class="btn-secondary admin-enable-server-btn" data-server-id="${s.id}">Enable</button>`
          : `<button type="button" class="btn-danger admin-disable-server-btn" data-server-id="${s.id}" data-server-name="${escapeHtml(s.name)}">Disable</button>`
        }
      </div>
    </div>
  `).join('');
  return `<div class="admin-table">${rows}</div>`;
}

function renderWarningLogTable(warnings) {
  if (warnings.length === 0) {
    return '<p class="profile-empty">No warnings have been issued yet.</p>';
  }
  const now = Date.now();
  const rows = warnings.map(w => {
    const isActive = new Date(w.expires_at).getTime() > now;
    return `
    <div class="admin-row admin-row-static">
      <div class="admin-row-info">
        <div class="user-panel-name">
          ${escapeHtml(w.username)}
          ${isActive ? '<span class="badge badge-banned">Active</span>' : '<span class="badge">Expired</span>'}
        </div>
        <div class="user-panel-status">${escapeHtml(w.reason)}</div>
        <div class="user-panel-status">
          Warned by ${escapeHtml(w.warned_by_username)} on ${formatTime(w.warned_at)}
          &middot; ${isActive ? 'Expires' : 'Expired'} ${formatTime(w.expires_at)}
        </div>
      </div>
      ${isActive ? `<div class="admin-row-actions"><button type="button" class="btn-secondary admin-revoke-warning-btn" data-warning-id="${w.id}">Revoke</button></div>` : ''}
    </div>
  `;
  }).join('');
  return `<div class="admin-table">${rows}</div>`;
}

function renderBanLogTable(bans) {
  if (bans.length === 0) {
    return '<p class="profile-empty">No bans have been issued yet.</p>';
  }
  const rows = bans.map(b => `
    <div class="admin-row admin-row-static">
      <div class="admin-row-info">
        <div class="user-panel-name">
          ${escapeHtml(b.username)}
          ${b.lifted_at ? '<span class="badge">Lifted</span>' : '<span class="badge badge-banned">Active</span>'}
        </div>
        <div class="user-panel-status">${escapeHtml(b.reason)}</div>
        <div class="user-panel-status">
          Banned by ${escapeHtml(b.banned_by_username)} on ${formatTime(b.banned_at)}
          ${b.lifted_at ? ` &middot; Lifted by ${escapeHtml(b.lifted_by_username || '—')} on ${formatTime(b.lifted_at)}` : ''}
        </div>
      </div>
    </div>
  `).join('');
  return `<div class="admin-table">${rows}</div>`;
}

function renderReportsTable(reports) {
  if (reports.length === 0) {
    return '<p class="profile-empty">No open reports. 🎉</p>';
  }
  const rows = reports.map(r => `
    <div class="admin-row admin-row-static" data-report-id="${r.id}">
      <img class="avatar avatar-sm" src="${avatarUrl(r.reported_avatar_url, r.reported_username)}" alt="">
      <div class="admin-row-info">
        <div class="user-panel-name">Reported: ${escapeHtml(r.reported_username)}</div>
        <div class="user-panel-status">${escapeHtml(r.reason)}</div>
        ${r.message_snapshot ? `<div class="user-panel-status" style="font-style:italic;">Message: "${escapeHtml(r.message_snapshot)}"</div>` : ''}
        <div class="user-panel-status">Reported by ${escapeHtml(r.reporter_username)} on ${formatTime(r.created_at)}</div>
      </div>
      <div class="admin-row-actions" style="display:flex; gap:6px;">
        <button type="button" class="btn-secondary admin-dismiss-report-btn" data-report-id="${r.id}">Dismiss</button>
        <button type="button" class="btn-primary admin-resolve-report-btn" data-report-id="${r.id}">Mark Resolved</button>
      </div>
    </div>
  `).join('');
  return `<div class="admin-table">${rows}</div>`;
}

function renderBadgesTable(badges) {
  const rows = badges.map(b => `
    <div class="admin-row admin-row-static" data-badge-id="${b.id}">
      <img class="custom-badge-icon" style="width:32px;height:32px;" src="${b.icon_url}" alt="">
      <div class="admin-row-info">
        <div class="user-panel-name">${escapeHtml(b.name)}</div>
        <div class="user-panel-status">Created ${formatTime(b.created_at)}</div>
      </div>
      <button type="button" class="btn-danger admin-delete-badge-btn" data-badge-id="${b.id}" data-name="${escapeHtml(b.name)}">Delete</button>
    </div>
  `).join('') || '<p class="profile-empty">No custom badges yet — upload one below.</p>';

  return `
    <div class="admin-table">${rows}</div>
    <label style="margin-top:16px;">Upload New Badge</label>
    <form id="upload-badge-form">
      <input type="text" id="new-badge-name" placeholder="Badge name, e.g. Event Winner" maxlength="50">
      <input type="file" id="new-badge-icon" accept="image/png,image/jpeg,image/gif,image/webp" style="margin-top:8px;">
      <div id="upload-badge-error" class="auth-error" hidden></div>
      <button type="submit" class="btn-primary" style="margin-top:8px;">Upload Badge</button>
    </form>
  `;
}

function bindBadgesTabActions() {
  document.querySelectorAll('.admin-delete-badge-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`Delete the "${btn.dataset.name}" badge? It'll be removed from everyone who has it.`)) return;
      const json = await apiPost('admin.php?action=custom_badge_delete', { badge_id: parseInt(btn.dataset.badgeId, 10) });
      if (!json.success) { alert(json.error); return; }
      await renderAdminPanel();
    });
  });

  const form = document.getElementById('upload-badge-form');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const name = document.getElementById('new-badge-name').value.trim();
      const fileInput = document.getElementById('new-badge-icon');
      const errEl = document.getElementById('upload-badge-error');
      errEl.hidden = true;
      if (!name || !fileInput.files[0]) {
        errEl.textContent = 'Name and an image are both required.';
        errEl.hidden = false;
        return;
      }
      const formData = new FormData();
      formData.append('name', name);
      formData.append('icon', fileInput.files[0]);
      const json = await apiUpload('upload.php?action=custom_badge_icon', formData);
      if (!json.success) {
        errEl.textContent = json.error;
        errEl.hidden = false;
        return;
      }
      await renderAdminPanel();
    });
  }
}

async function openManageUserBadgesModal(userId, username) {
  const json = await apiGet(`admin.php?action=user_badges&user_id=${userId}`);
  if (!json.success) { alert(json.error); return; }

  const rows = json.all_badges.map(b => {
    const on = json.assigned_ids.includes(b.id);
    return `
      <div class="permission-row" data-badge-id="${b.id}" style="cursor:default;">
        <div class="perm-name" style="display:flex;align-items:center;gap:8px;">
          <img class="custom-badge-icon" style="margin-left:0;" src="${b.icon_url}" alt="">
          ${escapeHtml(b.name)}
        </div>
        <button type="button" class="toggle ${on ? 'on' : ''}" data-badge-toggle="${b.id}"></button>
      </div>
    `;
  }).join('') || '<p class="modal-sub">No custom badges exist yet — upload one from the Badges tab first.</p>';

  openModal(`
    <h2>Badges for ${escapeHtml(username)}</h2>
    <div id="user-badges-list">${rows}</div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="close-user-badges-btn">Close</button>
    </div>
  `);
  document.getElementById('close-user-badges-btn').addEventListener('click', closeModal);
  document.querySelectorAll('[data-badge-toggle]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const badgeId = parseInt(btn.dataset.badgeToggle, 10);
      const nowOn = !btn.classList.contains('on');
      const action = nowOn ? 'assign_badge' : 'unassign_badge';
      const json2 = await apiPost(`admin.php?action=${action}`, { user_id: userId, badge_id: badgeId });
      if (!json2.success) { alert(json2.error); return; }
      btn.classList.toggle('on');
    });
  });
}

function bindAdminRowActions() {
  document.querySelectorAll('.admin-badge-select').forEach(sel => {
    sel.addEventListener('change', async () => {
      const userId = parseInt(sel.dataset.userId, 10);
      const json = await apiPost('admin.php?action=set_badge', { user_id: userId, badge: sel.value });
      if (!json.success) { alert(json.error); await renderAdminPanel(); return; }
      await renderAdminPanel();
    });
  });
  document.querySelectorAll('.admin-ban-btn').forEach(btn => {
    btn.addEventListener('click', () => openBanReasonModal(parseInt(btn.dataset.userId, 10), btn.dataset.username));
  });
  document.querySelectorAll('.admin-warn-btn').forEach(btn => {
    btn.addEventListener('click', () => openWarnReasonModal(parseInt(btn.dataset.userId, 10), btn.dataset.username));
  });
  document.querySelectorAll('.admin-manage-warnings-btn').forEach(btn => {
    btn.addEventListener('click', () => openManageWarningsModal(parseInt(btn.dataset.userId, 10), btn.dataset.username));
  });
  document.querySelectorAll('.admin-manage-badges-btn').forEach(btn => {
    btn.addEventListener('click', () => openManageUserBadgesModal(parseInt(btn.dataset.userId, 10), btn.dataset.username));
  });
  document.querySelectorAll('.admin-revoke-warning-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Revoke this warning? It will move to Expired immediately.')) return;
      const warningId = parseInt(btn.dataset.warningId, 10);
      const json = await apiPost('admin.php?action=revoke_warning', { warning_id: warningId });
      if (!json.success) { alert(json.error); return; }
      await renderAdminPanel();
    });
  });
  document.querySelectorAll('.admin-unban-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const userId = parseInt(btn.dataset.userId, 10);
      const json = await apiPost('admin.php?action=unban', { user_id: userId });
      if (!json.success) { alert(json.error); return; }
      await renderAdminPanel();
    });
  });
  document.querySelectorAll('.admin-disable-server-btn').forEach(btn => {
    btn.addEventListener('click', () => openDisableServerModal(parseInt(btn.dataset.serverId, 10), btn.dataset.serverName));
  });
  document.querySelectorAll('.admin-enable-server-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const serverId = parseInt(btn.dataset.serverId, 10);
      const json = await apiPost('admin.php?action=enable_server', { server_id: serverId });
      if (!json.success) { alert(json.error); return; }
      await renderAdminPanel();
    });
  });
  document.querySelectorAll('.admin-resolve-report-btn, .admin-dismiss-report-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const reportId = parseInt(btn.dataset.reportId, 10);
      const status = btn.classList.contains('admin-resolve-report-btn') ? 'resolved' : 'dismissed';
      const json = await apiPost('admin.php?action=resolve_report', { report_id: reportId, status });
      if (!json.success) { alert(json.error); return; }
      await renderAdminPanel();
    });
  });
}

// Re-bind the admin panel's tab/search/row listeners after returning
// from the ban-reason sub-view (which replaced modal-content's innerHTML).
async function openManageWarningsModal(userId, username) {
  const previousHtml = el.modalContent.innerHTML;
  const wasWide = el.modalContent.classList.contains('modal-wide');

  const renderInner = async () => {
    const json = await apiGet(`admin.php?action=user_warnings&user_id=${userId}`);
    const warnings = json.success ? json.warnings : [];
    const now = Date.now();

    const rowsHtml = warnings.length === 0
      ? '<p class="profile-empty">No warnings on record for this user.</p>'
      : warnings.map(w => {
          const isActive = new Date(w.expires_at).getTime() > now;
          return `
            <div class="standing-violation">
              <div class="standing-violation-icon">${isActive ? '⚠️' : '✓'}</div>
              <div style="flex:1;">
                <div class="standing-violation-reason">${escapeHtml(w.reason)}</div>
                <div class="standing-violation-date">
                  Warned by ${escapeHtml(w.warned_by_username)} on ${formatTime(w.warned_at)}
                  &middot; ${isActive ? 'Expires' : 'Expired'} ${formatTime(w.expires_at)}
                </div>
              </div>
              ${isActive ? `<button type="button" class="btn-secondary manage-revoke-warning-btn" data-warning-id="${w.id}" style="flex-shrink:0;">Revoke</button>` : ''}
            </div>
          `;
        }).join('');

    el.modalContent.innerHTML = `
      <h2>Warnings — ${escapeHtml(username)}</h2>
      <p class="modal-sub">Full warning history for this user. Active warnings count toward their account standing.</p>

      <div class="standing-section-body" style="padding:4px 0;">${rowsHtml}</div>

      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="close-manage-warnings-btn">Back</button>
      </div>
    `;

    document.getElementById('close-manage-warnings-btn').addEventListener('click', () => {
      el.modalContent.innerHTML = previousHtml;
      el.modalContent.classList.toggle('modal-wide', wasWide);
      bindAdminPanelAfterRestore();
    });

    document.querySelectorAll('.manage-revoke-warning-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Revoke this warning? It will move to Expired immediately.')) return;
        const warningId = parseInt(btn.dataset.warningId, 10);
        const revokeJson = await apiPost('admin.php?action=revoke_warning', { warning_id: warningId });
        if (!revokeJson.success) { alert(revokeJson.error); return; }
        await renderInner();
      });
    });
  };

  el.modalContent.classList.add('modal-wide');
  await renderInner();
}

function openBanReasonModal(userId, username) {
  const previousHtml = el.modalContent.innerHTML;
  const wasWide = el.modalContent.classList.contains('modal-wide');

  openModal(`
    <h2>Ban ${escapeHtml(username)}</h2>
    <p class="modal-sub">This immediately blocks their access site-wide. A reason is required and will be shown to them.</p>

    <label>Reason</label>
    <textarea id="ban-reason-input" maxlength="255" rows="3" placeholder="e.g. Repeated harassment in server chat"></textarea>
    <div id="ban-reason-error" class="auth-error" hidden></div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="cancel-ban-btn">Cancel</button>
      <button type="button" class="btn-danger" id="confirm-ban-btn">Ban User</button>
    </div>
  `);

  const restore = () => {
    el.modalContent.innerHTML = previousHtml;
    el.modalContent.classList.toggle('modal-wide', wasWide);
    bindAdminPanelAfterRestore();
  };

  document.getElementById('cancel-ban-btn').addEventListener('click', restore);
  document.getElementById('ban-reason-input').focus();

  document.getElementById('confirm-ban-btn').addEventListener('click', async () => {
    const reason = document.getElementById('ban-reason-input').value.trim();
    if (!reason) {
      const err = document.getElementById('ban-reason-error');
      err.textContent = 'Please provide a reason for the ban.';
      err.hidden = false;
      return;
    }
    const json = await apiPost('admin.php?action=ban', { user_id: userId, reason });
    if (!json.success) {
      const err = document.getElementById('ban-reason-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    await renderAdminPanel();
  });
}

// Same pattern as openBanReasonModal, but disables an entire server instead
// of banning a user. Members stay listed but every channel/message endpoint
// refuses the server, and the client shows a takeover screen with the reason.
function openDisableServerModal(serverId, serverName) {
  const previousHtml = el.modalContent.innerHTML;
  const wasWide = el.modalContent.classList.contains('modal-wide');

  openModal(`
    <h2>Disable ${escapeHtml(serverName)}</h2>
    <p class="modal-sub">This immediately blocks all channel access and messaging for everyone in the server. A reason is required and will be shown to its members.</p>

    <label>Reason</label>
    <textarea id="disable-server-reason-input" maxlength="255" rows="3" placeholder="e.g. Repeated ToS violations"></textarea>
    <div id="disable-server-reason-error" class="auth-error" hidden></div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="cancel-disable-server-btn">Cancel</button>
      <button type="button" class="btn-danger" id="confirm-disable-server-btn">Disable Server</button>
    </div>
  `);

  const restore = () => {
    el.modalContent.innerHTML = previousHtml;
    el.modalContent.classList.toggle('modal-wide', wasWide);
    bindAdminPanelAfterRestore();
  };

  document.getElementById('cancel-disable-server-btn').addEventListener('click', restore);
  document.getElementById('disable-server-reason-input').focus();

  document.getElementById('confirm-disable-server-btn').addEventListener('click', async () => {
    const reason = document.getElementById('disable-server-reason-input').value.trim();
    if (!reason) {
      const err = document.getElementById('disable-server-reason-error');
      err.textContent = 'Please provide a reason for disabling this server.';
      err.hidden = false;
      return;
    }
    const json = await apiPost('admin.php?action=disable_server', { server_id: serverId, reason });
    if (!json.success) {
      const err = document.getElementById('disable-server-reason-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    await renderAdminPanel();
  });
}

// Re-bind the admin panel's tab/search/row listeners after returning
// from the ban-reason sub-view (which replaced modal-content's innerHTML).
function openWarnReasonModal(userId, username) {
  const previousHtml = el.modalContent.innerHTML;
  const wasWide = el.modalContent.classList.contains('modal-wide');

  openModal(`
    <h2>Warn ${escapeHtml(username)}</h2>
    <p class="modal-sub">This doesn't restrict their account, but logs a violation on their Account Standing for 90 days. A reason is required and will be shown to them.</p>

    <label>Reason</label>
    <textarea id="warn-reason-input" maxlength="255" rows="3" placeholder="e.g. Off-topic spam in #general"></textarea>
    <div id="warn-reason-error" class="auth-error" hidden></div>

    <div class="modal-actions">
      <button type="button" class="btn-secondary" id="cancel-warn-btn">Cancel</button>
      <button type="button" class="btn-danger" id="confirm-warn-btn">Warn User</button>
    </div>
  `);

  const restore = () => {
    el.modalContent.innerHTML = previousHtml;
    el.modalContent.classList.toggle('modal-wide', wasWide);
    bindAdminPanelAfterRestore();
  };

  document.getElementById('cancel-warn-btn').addEventListener('click', restore);
  document.getElementById('warn-reason-input').focus();

  document.getElementById('confirm-warn-btn').addEventListener('click', async () => {
    const reason = document.getElementById('warn-reason-input').value.trim();
    if (!reason) {
      const err = document.getElementById('warn-reason-error');
      err.textContent = 'Please provide a reason for the warning.';
      err.hidden = false;
      return;
    }
    const json = await apiPost('admin.php?action=warn', { user_id: userId, reason });
    if (!json.success) {
      const err = document.getElementById('warn-reason-error');
      err.textContent = json.error;
      err.hidden = false;
      return;
    }
    await renderAdminPanel();
  });
}

function bindAdminPanelAfterRestore() {
  const closeBtn = document.getElementById('close-admin-panel-btn');
  if (closeBtn) closeBtn.addEventListener('click', closeModal);

  document.querySelectorAll('.admin-tab').forEach(tab => {
    tab.addEventListener('click', async () => {
      adminState.tab = tab.dataset.tab;
      await renderAdminPanel();
    });
  });

  const searchInput = document.getElementById('admin-user-search');
  if (searchInput) {
    let debounce;
    searchInput.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(async () => {
        adminState.query = searchInput.value.trim();
        await renderAdminPanel();
      }, 300);
    });
  }

  bindAdminRowActions();
}

/* ============================================================
   Calling (audio-only, WebRTC mesh, DB-polled signaling)
   ============================================================ */
// Small inline icon set for the call CTAs (DM header + voice channel).
// Plain strings (not emoji) so they inherit `color` via currentColor and
// stay crisp/consistent across platforms.
const ICON_PHONE = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>`;
const ICON_PHONE_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
const ICON_MIC = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>`;
const ICON_MIC_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9v3a3 3 0 0 0 4.6 2.55M15 9.34V4a3 3 0 0 0-5.94-.6"/><path d="M17 16.95A7 7 0 0 1 5 12v-2"/><path d="M19 10v2a7 7 0 0 1-.11 1.23"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
const ICON_VIDEO_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="M9.5 5H14a2 2 0 0 1 2 2v3.5"/><polygon points="23 7 16 12 23 17 23 7"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
const ICON_SCREEN_OFF = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
// STUN alone is never enough to get calls working end-to-end — it fails
// behind symmetric NATs / corporate firewalls, which is exactly why calls
// get stuck in the ICE "checking" state with no audio. That needs a real
// TURN relay. TURN is configured server-side in config/config.php — either
// Cloudflare Realtime TURN (CF_TURN_KEY_ID/CF_TURN_API_TOKEN, preferred) or
// a static provider/self-hosted coturn (TURN_URLS/TURN_USERNAME/
// TURN_CREDENTIAL) — and passed down as window.ICE_CONFIG. See that file
// for how to set either one up.
function buildIceServers() {
  const servers = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ];
  const cfg = window.ICE_CONFIG || {};

  if (Array.isArray(cfg.iceServers) && cfg.iceServers.length > 0) {
    // Cloudflare (or any provider) returning a full, ready-to-use
    // iceServers array — includes both STUN and TURN entries already.
    servers.push(...cfg.iceServers);
  } else if (Array.isArray(cfg.turnUrls) && cfg.turnUrls.length > 0 && cfg.turnUsername && cfg.turnCredential) {
    // Legacy single-provider static credentials.
    servers.push({ urls: cfg.turnUrls, username: cfg.turnUsername, credential: cfg.turnCredential });
  } else {
    console.warn(
      '[call] No TURN server configured — calls will get stuck in "checking" ' +
      'with no audio for anyone behind a restrictive NAT/firewall. Set ' +
      'CF_TURN_KEY_ID/CF_TURN_API_TOKEN (or TURN_URLS/TURN_USERNAME/TURN_CREDENTIAL) ' +
      'in config/config.php.'
    );
  }
  return servers;
}
const ICE_SERVERS = buildIceServers();

const callRuntime = {
  callId: null,
  kind: null,          // 'dm' | 'channel'
  conversationId: null,
  channelId: null,
  channelName: null,
  serverId: null,      // for channel calls, so the call bar can jump back to it
  otherUser: null,     // for DM calls
  localStream: null,
  localSpeakingDetector: null,
  speakingUserIds: new Set(), // userIds currently detected as talking
  muted: false,
  peers: {},           // userId -> { pc, audioEl, username, avatarUrl, speakingDetector }
  lastSignalId: 0,
  signalTimer: null,
  participantsTimer: null,
  ringTimeout: null,
};

const incomingCall = { data: null, timer: null };

/* -------- Incoming DM call watcher (runs at all times) -------- */
function startIncomingCallWatcher() {
  if (incomingCall.timer) clearInterval(incomingCall.timer);
  incomingCall.timer = setInterval(checkIncomingDmCall, 3000);
}

async function checkIncomingDmCall() {
  if (callRuntime.callId) return; // already in a call, don't interrupt it
  const json = await apiGet('calls.php?action=incoming_dm');
  if (!json.success) return;

  if (!json.call) {
    if (incomingCall.data) hideIncomingCallToast();
    return;
  }
  if (incomingCall.data && incomingCall.data.id === json.call.id) return; // already showing it
  showIncomingCallToast(json.call);
}

function showIncomingCallToast(call) {
  incomingCall.data = call;
  el.callToastAvatar.src = avatarUrl(call.caller.avatar_url, call.caller.username);
  el.callToastTitle.textContent = call.caller.username;
  el.callToastAccept.hidden = !!state.me.is_banned;
  el.callToast.hidden = false;
}

function hideIncomingCallToast() {
  incomingCall.data = null;
  el.callToast.hidden = true;
}

async function acceptIncomingCall() {
  const call = incomingCall.data;
  if (!call) return;
  hideIncomingCallToast();
  await joinDmCall(call.dm_conversation_id, call.caller);
}

async function declineIncomingCall() {
  const call = incomingCall.data;
  if (!call) return;
  hideIncomingCallToast();
  await apiPost('calls.php?action=decline_dm', { conversation_id: call.dm_conversation_id });
}

/**
 * Analyzes a MediaStream's audio level in real time (via Web Audio API)
 * and adds/removes userId from callRuntime.speakingUserIds as they start
 * and stop talking. A short "hangover" period after volume drops avoids
 * the indicator flickering between words/syllables.
 * Returns { stop() } to tear down the analyser, or null if unsupported.
 */
function attachSpeakingDetector(stream, userId) {
  if (!stream || stream.getAudioTracks().length === 0) return null;
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  if (!AudioCtx) return null;

  let audioCtx, source, analyser;
  try {
    audioCtx = new AudioCtx();
    source = audioCtx.createMediaStreamSource(stream);
    analyser = audioCtx.createAnalyser();
    analyser.fftSize = 512;
    analyser.smoothingTimeConstant = 0.6;
    source.connect(analyser);
  } catch (e) {
    console.warn('[call] Could not attach speaking detector:', e);
    return null;
  }

  const data = new Uint8Array(analyser.frequencyBinCount);
  const SPEAKING_THRESHOLD = 14; // tuned for typical mic levels, 0-255 scale
  const HANGOVER_MS = 250;       // keep the ring lit briefly between words
  let isSpeaking = false;
  let lastLoudAt = 0;
  let rafId = null;

  function tick() {
    analyser.getByteFrequencyData(data);
    let sum = 0;
    for (let i = 0; i < data.length; i++) sum += data[i];
    const avg = sum / data.length;

    const now = performance.now();
    if (avg > SPEAKING_THRESHOLD) {
      lastLoudAt = now;
      if (!isSpeaking) {
        isSpeaking = true;
        callRuntime.speakingUserIds.add(userId);
      }
    } else if (isSpeaking && now - lastLoudAt > HANGOVER_MS) {
      isSpeaking = false;
      callRuntime.speakingUserIds.delete(userId);
    }
    rafId = requestAnimationFrame(tick);
  }
  rafId = requestAnimationFrame(tick);

  return {
    stop() {
      if (rafId) cancelAnimationFrame(rafId);
      callRuntime.speakingUserIds.delete(userId);
      try { source.disconnect(); } catch (e) { /* ignore */ }
      try { audioCtx.close(); } catch (e) { /* ignore */ }
    },
  };
}

// Every element with data-speaking-id gets a glowing ring toggled on/off
// based on callRuntime.speakingUserIds — decoupled from the network polls
// so the indicator feels instant rather than waiting on a 4s roster refresh.
let speakingIndicatorTimer = null;
function startSpeakingIndicatorLoop() {
  if (speakingIndicatorTimer) return;
  speakingIndicatorTimer = setInterval(() => {
    document.querySelectorAll('[data-speaking-id]').forEach(node => {
      const uid = parseInt(node.dataset.speakingId, 10);
      node.classList.toggle('speaking', callRuntime.speakingUserIds.has(uid));
    });
  }, 200);
}
function stopSpeakingIndicatorLoop() {
  if (speakingIndicatorTimer) {
    clearInterval(speakingIndicatorTimer);
    speakingIndicatorTimer = null;
  }
}

/* -------- Starting / joining calls -------- */
async function ensureLocalStream() {
  if (callRuntime.localStream) return callRuntime.localStream;
  try {
    callRuntime.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    callRuntime.localSpeakingDetector = attachSpeakingDetector(callRuntime.localStream, state.me.id);
    return callRuntime.localStream;
  } catch (e) {
    alert('Microphone access is required to make or receive calls.');
    return null;
  }
}

async function joinDmCall(conversationId, otherUser) {
  if (callRuntime.callId) {
    alert('You are already in a call. Leave it first.');
    return;
  }
  const stream = await ensureLocalStream();
  if (!stream) return;

  const json = await apiPost('calls.php?action=join_dm', { conversation_id: conversationId });
  if (!json.success) { alert(json.error); return; }

  callRuntime.callId = json.call_id;
  callRuntime.kind = 'dm';
  callRuntime.conversationId = conversationId;
  callRuntime.otherUser = otherUser;

  renderCallBar();
  startSignalPolling();
  startParticipantsPolling();
  json.participants.forEach(p => maybeConnectToPeer(p.user_id, p.username, p.avatar_url));

  // If nobody's connected yet, this is a ring — give up if unanswered.
  if (json.participants.length === 0) {
    callRuntime.ringTimeout = setTimeout(() => {
      if (callRuntime.callId && callRuntime.kind === 'dm' && Object.keys(callRuntime.peers).length === 0) {
        alert('No answer.');
        leaveCall();
      }
    }, 45000);
  }

  if (state.activeConversationId === conversationId) {
    openConversation(conversationId, otherUser); // refresh header into "In Call" state
  }
}

async function joinChannelCall(channelId, channelName) {
  if (callRuntime.callId) {
    if (callRuntime.kind === 'channel' && callRuntime.channelId === channelId) return;
    if (!confirm('Leave your current call to join this voice channel?')) return;
    await leaveCall();
  }
  const stream = await ensureLocalStream();
  if (!stream) return;

  const json = await apiPost('calls.php?action=join_channel', { channel_id: channelId });
  if (!json.success) { alert(json.error); return; }

  callRuntime.callId = json.call_id;
  callRuntime.kind = 'channel';
  callRuntime.channelId = channelId;
  callRuntime.channelName = channelName;
  callRuntime.serverId = state.activeServerId;

  renderCallBar();
  startSignalPolling();
  startParticipantsPolling();
  json.participants.forEach(p => maybeConnectToPeer(p.user_id, p.username, p.avatar_url));

  if (state.activeChannel && state.activeChannel.id === channelId) {
    renderVoiceChannelMain(state.activeChannel);
  }
  if (state.activeServerId) refreshVoiceRoster(state.activeServerId);
}

/* -------- Peer connection management --------
   Glare-free rule: whichever side has the lower user id always
   initiates the offer to the higher id. Both sides apply the same
   rule independently, so it doesn't matter who "joined last". */
function maybeConnectToPeer(peerId, peerUsername, avatarUrlStr) {
  if (peerId === state.me.id) return;

  if (callRuntime.peers[peerId]) {
    callRuntime.peers[peerId].username = peerUsername;
    callRuntime.peers[peerId].avatarUrl = avatarUrlStr;
    renderCallBar();
    return;
  }

  const pc = createPeerConnection(peerId, peerUsername, avatarUrlStr);
  if (state.me.id < peerId) {
    (async () => {
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      sendCallSignal(peerId, 'offer', { sdp: offer });
    })();
  }
}

function createPeerConnection(peerId, peerUsername, avatarUrlStr) {
  const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  const audioEl = document.createElement('audio');
  audioEl.autoplay = true;
  audioEl.playsInline = true;
  el.callAudioSinks.appendChild(audioEl);

  callRuntime.peers[peerId] = { pc, audioEl, username: peerUsername, avatarUrl: avatarUrlStr || null };

  if (callRuntime.localStream) {
    callRuntime.localStream.getTracks().forEach(track => pc.addTrack(track, callRuntime.localStream));
  }

  pc.ontrack = (event) => {
    audioEl.srcObject = event.streams[0];
    // autoplay alone is unreliable for <audio> elements created after the
    // initial page load in some browsers — force playback explicitly.
    audioEl.play().catch(err => console.warn('Call audio playback blocked:', err));

    const peerEntry = callRuntime.peers[peerId];
    if (peerEntry && !peerEntry.speakingDetector) {
      peerEntry.speakingDetector = attachSpeakingDetector(event.streams[0], peerId);
    }
  };

  pc.onicecandidate = (event) => {
    if (event.candidate) {
      const candidate = event.candidate.toJSON ? event.candidate.toJSON() : event.candidate;
      sendCallSignal(peerId, 'candidate', { candidate });
    }
  };

  pc.oniceconnectionstatechange = () => {
    const iceState = pc.iceConnectionState;
    console.log(`[call] ICE state with ${peerUsername || peerId}:`, iceState);

    const peerEntry = callRuntime.peers[peerId];
    if (peerEntry && peerEntry.iceStuckTimer) {
      clearTimeout(peerEntry.iceStuckTimer);
      peerEntry.iceStuckTimer = null;
    }

    if (iceState === 'checking') {
      // Give it a few seconds — "checking" is normal at first. If it's
      // still there after that, no candidate pair is working, which in
      // practice almost always means there's no usable TURN relay for
      // this pair of networks (see ICE_SERVERS above).
      if (peerEntry) {
        peerEntry.iceStuckTimer = setTimeout(() => {
          if (callRuntime.peers[peerId] && pc.iceConnectionState === 'checking') {
            console.warn(
              `[call] Still stuck in "checking" with ${peerUsername || peerId} after 8s — ` +
              'no ICE candidate pair is working. This almost always means no TURN ' +
              'server is configured/reachable. See config/config.php (CF_TURN_KEY_ID or TURN_URLS).'
            );
            peerEntry.stuck = true;
            refreshCallConnectionWarning();
          }
        }, 8000);
      }
    } else if (iceState === 'connected' || iceState === 'completed') {
      if (peerEntry) peerEntry.stuck = false;
      refreshCallConnectionWarning();
    } else if (iceState === 'failed') {
      if (peerEntry) peerEntry.stuck = true;
      refreshCallConnectionWarning();
      if (pc.restartIce) pc.restartIce();
    }
  };

  renderCallBar();
  return pc;
}

function closePeer(peerId) {
  const peer = callRuntime.peers[peerId];
  if (!peer) return;
  if (peer.speakingDetector) peer.speakingDetector.stop();
  callRuntime.speakingUserIds.delete(peerId);
  peer.pc.close();
  peer.audioEl.remove();
  delete callRuntime.peers[peerId];
  renderCallBar();
}

/* -------- Signaling (relayed via short-poll, like messages) -------- */
async function sendCallSignal(toUserId, type, payload) {
  if (!callRuntime.callId) return;
  await apiPost('calls.php?action=signal', { call_id: callRuntime.callId, to_user_id: toUserId, type, payload });
}

function startSignalPolling() {
  callRuntime.lastSignalId = 0;
  stopSignalPolling();
  let inFlight = false;
  callRuntime.signalTimer = setInterval(async () => {
    if (!callRuntime.callId || inFlight) return;
    inFlight = true;
    try {
      const json = await apiGet(`calls.php?action=signals&call_id=${callRuntime.callId}&after_id=${callRuntime.lastSignalId}`);
      if (!json.success) return;
      for (const sig of json.signals) {
        callRuntime.lastSignalId = Math.max(callRuntime.lastSignalId, sig.id);
        await handleCallSignal(sig);
      }
    } finally {
      inFlight = false;
    }
  }, 1000);
}

function stopSignalPolling() {
  if (callRuntime.signalTimer) {
    clearInterval(callRuntime.signalTimer);
    callRuntime.signalTimer = null;
  }
}

async function handleCallSignal(sig) {
  const peerId = sig.from_user_id;

  if (sig.type === 'leave') {
    if (sig.payload && sig.payload.declined) {
      alert(`${callRuntime.otherUser ? callRuntime.otherUser.username : 'They'} declined the call.`);
      leaveCall();
      return;
    }
    if (sig.payload && sig.payload.reason === 'disconnected') {
      // A moderator force-disconnected *us* — hang up regardless of
      // whether this is a DM or channel call, and say why.
      closePeer(peerId);
      alert('You were disconnected from the call by a moderator.');
      leaveCall();
      return;
    }
    closePeer(peerId);
    if (callRuntime.kind === 'dm') {
      leaveCall(); // a 1:1 call is over once the other side hangs up
    }
    return;
  }

  let peer = callRuntime.peers[peerId];
  if (!peer) {
    createPeerConnection(peerId, null, null);
    peer = callRuntime.peers[peerId];
  }

  if (sig.type === 'offer') {
    // A fresh offer should only ever arrive while stable (no pending
    // local offer of our own). If it doesn't, something delivered this
    // signal more than once or out of order — ignore it rather than
    // throwing and killing the whole handler.
    if (peer.pc.signalingState !== 'stable') {
      console.warn('Ignoring offer; unexpected signalingState:', peer.pc.signalingState);
      return;
    }
    await peer.pc.setRemoteDescription(new RTCSessionDescription(sig.payload.sdp));
    const answer = await peer.pc.createAnswer();
    await peer.pc.setLocalDescription(answer);
    sendCallSignal(peerId, 'answer', { sdp: answer });
  } else if (sig.type === 'answer') {
    // Only valid right after we've sent an offer. If we're already
    // stable, this is a duplicate/late answer — applying it again is
    // what throws "Called in wrong state: stable".
    if (peer.pc.signalingState !== 'have-local-offer') {
      console.warn('Ignoring duplicate/late answer; signalingState:', peer.pc.signalingState);
      return;
    }
    await peer.pc.setRemoteDescription(new RTCSessionDescription(sig.payload.sdp));
  } else if (sig.type === 'candidate') {
    try {
      await peer.pc.addIceCandidate(new RTCIceCandidate(sig.payload.candidate));
    } catch (e) { /* ignore late/invalid candidates */ }
  }
}

function startParticipantsPolling() {
  stopParticipantsPolling();
  const poll = async () => {
    if (!callRuntime.callId) return;
    const json = await apiGet(`calls.php?action=participants&call_id=${callRuntime.callId}`);
    if (!json.success) return;

    const activeIds = new Set(json.participants.map(p => p.user_id));
    json.participants.forEach(p => maybeConnectToPeer(p.user_id, p.username, p.avatar_url));

    Object.keys(callRuntime.peers).forEach(id => {
      if (!activeIds.has(parseInt(id, 10))) closePeer(parseInt(id, 10));
    });

    if (callRuntime.kind === 'channel' && state.activeServerId) {
      refreshVoiceRoster(state.activeServerId);
    }
  };
  poll();
  callRuntime.participantsTimer = setInterval(poll, 2500);
}

function stopParticipantsPolling() {
  if (callRuntime.participantsTimer) {
    clearInterval(callRuntime.participantsTimer);
    callRuntime.participantsTimer = null;
  }
}

/* -------- Leaving / UI -------- */
async function leaveCall() {
  if (!callRuntime.callId) return;
  const callId = callRuntime.callId;
  const kind = callRuntime.kind;
  const channelId = callRuntime.channelId;
  const conversationId = callRuntime.conversationId;
  const otherUser = callRuntime.otherUser;

  // Stop polling *before* telling the server we're leaving. Both timers
  // fire independently of this async function, so if we left them
  // running during the await below, one could poll (participants or
  // signals) right after the server has marked us as having left —
  // which it correctly rejects with a 403, spamming the console.
  stopSignalPolling();
  stopParticipantsPolling();
  callRuntime.callId = null; // guards against a re-entrant leaveCall() firing the POST twice

  await apiPost('calls.php?action=leave', { call_id: callId });

  Object.keys(callRuntime.peers).forEach(id => closePeer(parseInt(id, 10)));
  if (callRuntime.localSpeakingDetector) {
    callRuntime.localSpeakingDetector.stop();
    callRuntime.localSpeakingDetector = null;
  }
  if (callRuntime.localStream) {
    callRuntime.localStream.getTracks().forEach(t => t.stop());
  }
  if (callRuntime.ringTimeout) clearTimeout(callRuntime.ringTimeout);

  callRuntime.callId = null;
  callRuntime.kind = null;
  callRuntime.conversationId = null;
  callRuntime.channelId = null;
  callRuntime.channelName = null;
  callRuntime.serverId = null;
  callRuntime.otherUser = null;
  callRuntime.localStream = null;
  callRuntime.muted = false;
  callRuntime.peers = {};
  callRuntime.speakingUserIds.clear();
  callRuntime.lastSignalId = 0;
  callRuntime.ringTimeout = null;

  stopSpeakingIndicatorLoop();
  el.callBar.hidden = true;

  if (kind === 'dm' && state.activeConversationId === conversationId && otherUser) {
    openConversation(conversationId, otherUser);
  }
  if (kind === 'channel') {
    if (state.activeChannel && state.activeChannel.id === channelId) {
      renderVoiceChannelMain(state.activeChannel);
    }
    if (state.activeServerId) refreshVoiceRoster(state.activeServerId);
  }
}

function toggleMute() {
  if (!callRuntime.localStream) return;
  callRuntime.muted = !callRuntime.muted;
  callRuntime.localStream.getAudioTracks().forEach(t => { t.enabled = !callRuntime.muted; });

  if (callRuntime.kind === 'channel' && state.activeChannel && state.activeChannel.id === callRuntime.channelId) {
    renderVoiceChannelMain(state.activeChannel);
  }
  const dmMuteBtn = document.getElementById('dm-call-mute-btn');
  if (dmMuteBtn) {
    dmMuteBtn.classList.toggle('muted', callRuntime.muted);
    dmMuteBtn.innerHTML = callRuntime.muted ? ICON_MIC_OFF : ICON_MIC;
    dmMuteBtn.title = callRuntime.muted ? 'Unmute' : 'Mute';
  }
}

// Clicking the persistent call bar jumps you back to wherever the call
// actually lives — its voice channel (switching servers if needed) or its
// DM conversation — since mute/leave controls now live there, not on the bar.
async function returnToActiveCall() {
  if (!callRuntime.callId) return;

  if (callRuntime.kind === 'dm') {
    closeMobileSidebar();
    showDMHome();
    openConversation(callRuntime.conversationId, callRuntime.otherUser);
    return;
  }

  if (callRuntime.kind === 'channel') {
    const channelId = callRuntime.channelId;
    const serverId = callRuntime.serverId;
    if (!serverId) return;
    if (state.activeServerId !== serverId) {
      await openServer(serverId);
    }
    const json = await apiGet(`channels.php?action=list&server_id=${serverId}`);
    if (!json.success) return;
    const channel = json.channels.find(c => c.id === channelId);
    if (channel) selectChannel(channel);
  }
}

function renderCallBar() {
  if (!callRuntime.callId) {
    el.callBar.hidden = true;
    stopSpeakingIndicatorLoop();
    refreshCallConnectionWarning();
    return;
  }
  el.callBar.hidden = false;

  const connectedCount = Object.keys(callRuntime.peers).length;
  if (callRuntime.kind === 'dm') {
    el.callBarStatus.textContent = connectedCount > 0
      ? `On a call with ${callRuntime.otherUser.username}`
      : `Calling ${callRuntime.otherUser.username}...`;
  } else {
    el.callBarStatus.textContent = `Voice — ${callRuntime.channelName} (${connectedCount + 1})`;
  }

  const youAvatar = `<img class="avatar" src="${avatarUrl(state.me.avatar_url, state.me.username)}" title="You" data-speaking-id="${state.me.id}">`;
  const peerAvatars = Object.entries(callRuntime.peers).map(([id, p]) => `
    <img class="avatar" src="${avatarUrl(p.avatarUrl, p.username || '?')}" title="${escapeHtml(p.username || '')}" data-speaking-id="${id}">
  `).join('');
  el.callBarAvatars.innerHTML = youAvatar + peerAvatars;

  startSpeakingIndicatorLoop();
  refreshCallConnectionWarning();
}

// Shows/hides the "⚠️ Connection trouble" indicator in the call bar based
// on whether any current peer connection is stuck (no working ICE
// candidate pair — almost always a missing/unreachable TURN server).
function refreshCallConnectionWarning() {
  if (!el.callBarWarning) return;
  if (!callRuntime.callId) {
    el.callBarWarning.hidden = true;
    return;
  }
  const anyStuck = Object.values(callRuntime.peers).some(p => p.stuck);
  el.callBarWarning.hidden = !anyStuck;
}