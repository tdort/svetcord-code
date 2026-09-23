<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::bootSession();
if (Auth::isLoggedIn()) {
    header('Location: app.php');
    exit;
}
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discordish — Reset Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <form id="reset-form" class="auth-form" hidden>
      <h1>Set a new password</h1>
      <div id="reset-error" class="auth-error" hidden></div>
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES) ?>">
      <label>New Password</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
      <label>Confirm New Password</label>
      <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      <button type="submit" class="btn-primary">Reset Password</button>
    </form>

    <div id="reset-invalid" class="auth-form" hidden>
      <h1>Link invalid or expired</h1>
      <p class="auth-sub">This password reset link no longer works. Request a new one below.</p>
      <a href="forgot_password.php"><button type="button" class="btn-primary" style="width:100%;">Request a new link</button></a>
    </div>

    <div id="reset-done" class="auth-form" hidden>
      <h1>Password updated</h1>
      <p class="auth-sub">You can now log in with your new password.</p>
      <a href="index.php"><button type="button" class="btn-primary" style="width:100%;">Back to login</button></a>
    </div>
  </div>

<script>
const token = <?php echo json_encode($token) ?>;
const formEl = document.getElementById('reset-form');
const invalidEl = document.getElementById('reset-invalid');
const doneEl = document.getElementById('reset-done');
const errorEl = document.getElementById('reset-error');

(async () => {
  if (!token) { invalidEl.hidden = false; return; }
  const res = await fetch(`../api/auth.php?action=check_reset_token&token=${encodeURIComponent(token)}`);
  const json = await res.json();
  if (json.success && json.valid) {
    formEl.hidden = false;
  } else {
    invalidEl.hidden = false;
  }
})();

formEl.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.hidden = true;
  const password = formEl.password.value;
  const confirm = formEl.password_confirm.value;
  if (password !== confirm) {
    errorEl.textContent = "Passwords don't match.";
    errorEl.hidden = false;
    return;
  }
  const submitBtn = formEl.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  try {
    const res = await fetch('../api/auth.php?action=reset_password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, password })
    });
    const json = await res.json();
    if (!json.success) {
      errorEl.textContent = json.error || 'Something went wrong.';
      errorEl.hidden = false;
      submitBtn.disabled = false;
      return;
    }
    formEl.hidden = true;
    doneEl.hidden = false;
  } catch (err) {
    errorEl.textContent = 'Network error. Please try again.';
    errorEl.hidden = false;
    submitBtn.disabled = false;
  }
});
</script>
</body>
</html>
