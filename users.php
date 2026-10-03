<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_users');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('action');

    if ($act === 'toggle') {
        $id = (int)post('id');
        if ($id === (int)current_user()['id']) {
            flash('You cannot disable your own account.', 'warning');
        } else {
            q('UPDATE users SET active = 1 - active WHERE id = ?', [$id]);
            audit('update', 'users', $id, 'Active flag toggled');
            flash('User status changed.', 'success');
        }
    } elseif ($act === 'reset') {
        $id  = (int)post('id');
        $new = post('password');
        if (strlen($new) < 6) { flash('The new password must be at least 6 characters.', 'warning'); }
        else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $id]);
            audit('update', 'users', $id, 'Password reset by administrator');
            flash('Password reset successfully.', 'success');
        }
    } elseif ($act === 'delete') {
        $id = (int)post('id');
        if ($id === (int)current_user()['id']) {
            flash('You cannot delete your own account.', 'warning');
        } elseif ((int)fetch_val('SELECT COUNT(*) FROM movements WHERE created_by = ?', [$id])
               || (int)fetch_val('SELECT COUNT(*) FROM delivery_notes WHERE created_by = ?', [$id])) {
            q('UPDATE users SET active = 0 WHERE id = ?', [$id]);
            flash('That user has transaction history, so the account was deactivated instead of deleted.', 'warning');
        } else {
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('delete', 'users', $id);
            flash('User deleted.', 'success');
        }
    }
    redirect('users.php');
}

$rows = fetch_all('SELECT u.*, c.code AS company_code, c.name AS company_name FROM users u
                   LEFT JOIN companies c ON c.id = u.company_id ORDER BY u.role, u.username');

if (get('export')) {
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['username' => $r['username'], 'full_name' => $r['full_name'], 'email' => $r['email'],
                  'role' => str_replace('_', ' ', $r['role']), 'company' => $r['company_name'] ?: 'All companies',
                  'last_login' => $r['last_login'], 'status' => (int)$r['active'] === 1 ? 'Active' : 'Disabled'];
    }
    $headers = ['Username', 'Full name', 'E-mail', 'Role', 'Company', 'Last sign-in', 'Status'];
    export_xls('users', 'System Users', $headers, $out, null,
        ['Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)]);
}

$page_title = 'Users';
$active_nav = 'users.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>System Users</h1>
    <p class="text-muted mb-0"><?= count($rows) ?> account(s) — roles control what a user may view and do</p>
  </div>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-outline-secondary" href="?export=xls"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <a class="btn btn-brand" href="<?= e(url('user_form.php')) ?>"><i class="bi bi-person-plus"></i> New user</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <?php
  $roleInfo = [
      ['admin', 'Administrator', 'Full access: companies, users, all masters, every movement and report, audit trail.', 'shield-lock', 'red'],
      ['store_manager', 'Store Manager', 'Manages items, projects, suppliers, movements, transfers, delivery notes, approvals and exports.', 'briefcase', 'blue'],
      ['store_keeper', 'Store Keeper', 'Records movements and transfers, prepares delivery notes, views reports. Cannot approve or delete.', 'box-seam', 'green'],
  ];
  foreach ($roleInfo as $ri):
    $count = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = ? AND active = 1', [$ri[0]]); ?>
    <div class="col-md-4">
      <div class="card app-card h-100">
        <div class="card-body d-flex gap-3">
          <div class="stat-icon"><i class="bi bi-<?= e($ri[3]) ?>"></i></div>
          <div>
            <h6 class="mb-1"><?= e($ri[1]) ?> <span class="badge bg-light text-dark"><?= $count ?></span></h6>
            <p class="small text-muted mb-0"><?= e($ri[2]) ?></p>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Username</th><th>Full name</th><th>E-mail</th><th>Role</th><th>Company scope</th>
        <th>Last sign-in</th><th>Status</th><th class="text-end no-print">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= (int)$r['active'] === 0 ? 'table-secondary' : '' ?>">
          <td class="mono fw-semibold"><?= e($r['username']) ?></td>
          <td><?= e($r['full_name']) ?></td>
          <td><small><?= e($r['email']) ?></small></td>
          <td><?= badge_for_role($r['role']) ?></td>
          <td><small><?= e($r['company_name'] ? $r['company_code'] . ' — ' . $r['company_name'] : 'All companies') ?></small></td>
          <td><small class="text-muted"><?= e($r['last_login'] ? fmt_dt($r['last_login']) : 'never') ?></small></td>
          <td><?= (int)$r['active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></td>
          <td class="text-end no-print text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('user_form.php?id=' . (int)$r['id'])) ?>"><i class="bi bi-pencil"></i></a>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pw<?= (int)$r['id'] ?>"><i class="bi bi-key"></i></button>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-warning" title="Enable / disable"><i class="bi bi-power"></i></button>
            </form>
            <form method="post" class="d-inline" data-confirm="Delete user <?= e($r['username']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>

            <div class="modal fade" id="pw<?= (int)$r['id'] ?>" tabindex="-1">
              <div class="modal-dialog modal-sm">
                <form method="post" class="modal-content text-start">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <div class="modal-header"><h6 class="modal-title">Reset password — <?= e($r['username']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <label class="form-label">New password</label>
                    <input type="text" name="password" class="form-control" minlength="6" required>
                    <small class="text-muted">Inform the user and ask them to change it after signing in.</small>
                  </div>
                  <div class="modal-footer">
                    <button class="btn btn-sm btn-brand">Reset password</button>
                  </div>
                </form>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
