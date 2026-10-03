<?php
require_once __DIR__ . '/helpers.php';

if (current_user()) { redirect('index.php'); }

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = post('username');
    $password = post('password');
    $row = fetch_one('SELECT * FROM users WHERE username = ?', [$username]);

    if (!$row || !password_verify($password, $row['password_hash'])) {
        $error = 'Invalid username or password.';
        audit('login_failed', 'users', $row['id'] ?? null, 'Failed sign-in for "' . $username . '"');
    } elseif ((int)$row['active'] !== 1) {
        $error = 'This account has been disabled. Contact your administrator.';
    } else {
        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        unset($_SESSION['active_company']);
        q('UPDATE users SET last_login = NOW() WHERE id = ?', [$row['id']]);
        audit('login', 'users', (int)$row['id'], 'Successful sign-in');
        redirect('index.php');
    }
}

$companies_count = (int)fetch_val('SELECT COUNT(*) FROM companies');
$items_count     = (int)fetch_val('SELECT COUNT(*) FROM items');
$moves_count     = (int)fetch_val('SELECT COUNT(*) FROM movements');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <span class="brand-mark big"><i class="bi bi-box-seam"></i></span>
      <h4><?= e(APP_NAME) ?></h4>
      <p>Inventory &amp; stock movement control for construction sites</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" name="username" class="form-control" value="<?= e($username) ?>" required autofocus>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="password" id="password" class="form-control" required>
          <button class="btn btn-outline-secondary" type="button" onclick="togglePw()"><i class="bi bi-eye" id="pwIcon"></i></button>
        </div>
      </div>
      <button class="btn btn-brand w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
    </form>

    <div class="login-stats">
      <div><strong><?= number_format($companies_count) ?></strong><span>Companies</span></div>
      <div><strong><?= number_format($items_count) ?></strong><span>Items</span></div>
      <div><strong><?= number_format($moves_count) ?></strong><span>Movements</span></div>
    </div>

    <details class="demo-creds">
      <summary><i class="bi bi-info-circle"></i> Demo accounts</summary>
      <table class="table table-sm mb-0">
        <thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
        <tbody>
          <tr><td>Administrator</td><td><code>admin</code></td><td><code>Admin@123</code></td></tr>
          <tr><td>Store Manager</td><td><code>manager1</code></td><td><code>Manager@123</code></td></tr>
          <tr><td>Store Keeper</td><td><code>keeper1</code></td><td><code>Keeper@123</code></td></tr>
        </tbody>
      </table>
      <p class="small text-muted mb-0 mt-2">Also available: manager2 / keeper2 (Gulf Build), manager3 / keeper3 (Desert Rose) — same role passwords.</p>
    </details>
    <?= rt_attribution_html('card') ?>
  </div>
</div>
<script>
function togglePw() {
  var i = document.getElementById('password'), c = document.getElementById('pwIcon');
  i.type = i.type === 'password' ? 'text' : 'password';
  c.className = i.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
</script>
<?= rt_attribution_html('fixed') ?>
</body>
</html>
