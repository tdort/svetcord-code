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
<title>Discordish — Forgot Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <form id="forgot-form" class="auth-form">
      <h1>Forgot your password?</h1>
      <p class="auth-sub">Enter the email on your account and we'll send you a reset link.</p>
      <div id="forgot-error" class="auth-error" hidden></div>
      <div id="forgot-success" class="auth-error" style="background:rgba(45,212,167,.12);border-color:var(--green);color:var(--green);" hidden></div>
      <label>Email</label>
      <input type="email" name="email" required autocomplete="email" autofocus>
      <button type="submit" class="btn-primary">Send Reset Link</button>
      <p class="auth-sub" style="margin-top:14px;"><a href="index.php">Back to login</a></p>
    </form>
  </div>

<script>
const form = document.getElementById('forgot-form');
const errorEl = document.getElementById('forgot-error');
const successEl = document.getElementById('forgot-success');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  errorEl.hidden = true;
  successEl.hidden = true;
  const email = form.email.value.trim();
  const submitBtn = form.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  try {
    const res = await fetch('../api/auth.php?action=request_password_reset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });
    const json = await res.json();
    if (!json.success) {
      errorEl.textContent = json.error || 'Something went wrong.';
      errorEl.hidden = false;
      submitBtn.disabled = false;
      return;
    }
    successEl.textContent = json.message;
    successEl.hidden = false;
    form.email.disabled = true;
  } catch (err) {
    errorEl.textContent = 'Network error. Please try again.';
    errorEl.hidden = false;
    submitBtn.disabled = false;
  }
});
</script>
</body>
</html>
