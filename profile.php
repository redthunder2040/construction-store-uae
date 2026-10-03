<?php
require_once __DIR__ . '/helpers.php';
$USER = require_login();
$errors = [];
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('action');

    if ($act === 'profile') {
        $full = post('full_name');
        $mail = post('email');
        if ($full === '') { $errors[] = 'Full name is required.'; }
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'That e-mail address looks invalid.'; }
        if (!$errors) {
            q('UPDATE users SET full_name = ?, email = ? WHERE id = ?', [$full, $mail, (int)$USER['id']]);
            audit('update', 'users', (int)$USER['id'], 'Own profile updated');
            flash('Your profile was updated.', 'success');
            redirect('profile.php');
        }
    } elseif ($act === 'password') {
        $cur = post('current_password');
        $new = post('new_password');
        $rep = post('repeat_password');
        if (!password_verify($cur, (string)fetch_val('SELECT password_hash FROM users WHERE id = ?', [(int)$USER['id']]))) {
            $errors[] = 'Your current password is not correct.';
        }
        if (strlen($new) < 6) { $errors[] = 'The new password must be at least 6 characters.'; }
        if ($new !== $rep)    { $errors[] = 'The new passwords do not match.'; }
        if (!$errors) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), (int)$USER['id']]);
            audit('update', 'users', (int)$USER['id'], 'Password changed by user');
            flash('Your password was changed.', 'success');
            redirect('profile.php');
        }
    }
}

$activity = fetch_all('SELECT * FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 15', [(int)$USER['id']]);
$myMoves  = (int)fetch_val('SELECT COUNT(*) FROM movements WHERE created_by = ?', [(int)$USER['id']]);
$myDns    = (int)fetch_val('SELECT COUNT(*) FROM delivery_notes WHERE created_by = ?', [(int)$USER['id']]);

$page_title = 'My account';
$active_nav = '';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><h1>My account</h1>
    <p class="text-muted mb-0">Signed in as <strong><?= e($USER['username']) ?></strong> <?= badge_for_role($USER['role']) ?></p></div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-person-gear"></i> Profile details</h5></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <div class="mb-3"><label class="form-label required">Full name</label>
            <input type="text" name="full_name" class="form-control" value="<?= e($USER['full_name']) ?>" required></div>
          <div class="mb-3"><label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" value="<?= e($USER['email']) ?>"></div>
          <div class="mb-3"><label class="form-label">Username</label>
            <input type="text" class="form-control mono" value="<?= e($USER['username']) ?>" disabled></div>
          <div class="mb-3"><label class="form-label">Company scope</label>
            <input type="text" class="form-control" value="<?= e($USER['company_name'] ?? 'All companies') ?>" disabled></div>
          <button class="btn btn-brand"><i class="bi bi-save"></i> Save profile</button>
        </form>
      </div>
    </div>

    <div class="card app-card mt-3">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-key"></i> Change password</h5></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="mb-3"><label class="form-label required">Current password</label>
            <input type="password" name="current_password" class="form-control" required></div>
          <div class="mb-3"><label class="form-label required">New password</label>
            <input type="password" name="new_password" class="form-control" minlength="6" required></div>
          <div class="mb-3"><label class="form-label required">Repeat new password</label>
            <input type="password" name="repeat_password" class="form-control" minlength="6" required></div>
          <button class="btn btn-brand"><i class="bi bi-shield-lock"></i> Change password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card app-card mb-3">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-speedometer2"></i> My activity</h5></div>
      <div class="card-body">
        <div class="row text-center">
          <div class="col-4"><div class="stat-value"><?= number_format($myMoves) ?></div><small class="text-muted">Movements recorded</small></div>
          <div class="col-4"><div class="stat-value"><?= number_format($myDns) ?></div><small class="text-muted">Delivery notes</small></div>
          <div class="col-4"><div class="stat-value"><?= e($USER['last_login'] ? date('d-M', strtotime($USER['last_login'])) : '—') ?></div><small class="text-muted">Last sign-in</small></div>
        </div>
      </div>
    </div>
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history"></i> My recent system activity</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php foreach ($activity as $a): ?>
            <tr><td class="text-nowrap small"><?= e(fmt_dt($a['created_at'])) ?></td>
              <td><span class="badge bg-light text-dark"><?= e($a['action']) ?></span></td>
              <td><small><?= e($a['entity']) ?> <?= e($a['entity_id']) ?></small></td>
              <td><small class="text-muted"><?= e(mb_strimwidth((string)$a['details'], 0, 60, '…')) ?></small></td></tr>
          <?php endforeach; ?>
          <?php if (!$activity): ?><tr><td class="text-muted">No activity recorded yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
