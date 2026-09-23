<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::bootSession();
if (Auth::isLoggedIn()) {
    header('Location: app.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discordish — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <div class="auth-tabs">
      <button class="auth-tab active" data-tab="login">Sign In</button>
      <button class="auth-tab" data-tab="register">Register</button>
    </div>

    <form id="login-form" class="auth-form">
      <h1>Welcome back!</h1>
      <p class="auth-sub">We're so excited to see you again.</p>
      <div id="login-error" class="auth-error" hidden></div>
      <label>Email or Username</label>
      <input type="text" name="identifier" required autocomplete="username">
      <label>Password</label>
      <input type="password" name="password" required autocomplete="current-password">
      <button type="submit" class="btn-primary">Log In</button>
      <p class="auth-sub" style="margin-top:14px;"><a href="forgot_password.php">Forgot your password?</a></p>
    </form>

    <form id="register-form" class="auth-form" hidden>
      <h1>Create an account</h1>
      <div id="register-error" class="auth-error" hidden></div>
      <label>Username</label>
      <input type="text" name="username" required minlength="3" maxlength="32" pattern="[a-zA-Z0-9_\-]+">
      <label>Email</label>
      <input type="email" name="email" required>
      <label>Password</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
      <button type="submit" class="btn-primary">Register</button>
    </form>
  </div>

<script>
const tabs = document.querySelectorAll('.auth-tab');
const forms = { login: document.getElementById('login-form'), register: document.getElementById('register-form') };

tabs.forEach(tab => tab.addEventListener('click', () => {
  tabs.forEach(t => t.classList.remove('active'));
  tab.classList.add('active');
  Object.entries(forms).forEach(([key, form]) => form.hidden = key !== tab.dataset.tab);
}));

async function submitAuth(form, action, errorEl) {
  errorEl.hidden = true;
  const data = Object.fromEntries(new FormData(form).entries());
  try {
    const res = await fetch(`../api/auth.php?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const json = await res.json();
    if (!json.success) {
      errorEl.textContent = json.error || 'Something went wrong.';
      errorEl.hidden = false;
      return;
    }
    window.location.href = 'app.php';
  } catch (e) {
    errorEl.textContent = 'Network error. Please try again.';
    errorEl.hidden = false;
  }
}

document.getElementById('login-form').addEventListener('submit', e => {
  e.preventDefault();
  submitAuth(e.target, 'login', document.getElementById('login-error'));
});

document.getElementById('register-form').addEventListener('submit', e => {
  e.preventDefault();
  submitAuth(e.target, 'register', document.getElementById('register-error'));
});
</script>
</body>
</html>