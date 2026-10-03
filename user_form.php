<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_users');

$id = (int)get_num('id');
$u  = $id ? fetch_one('SELECT * FROM users WHERE id = ?', [$id]) : null;
if ($id && !$u) { flash('User not found.', 'danger'); redirect('users.php'); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = strtolower(preg_replace('/[^a-z0-9._-]/i', '', post('username')));
    $fullname = post('full_name');
    $email    = post('email');
    $role     = post('role');
    $co       = post('company_id') === 'all' ? null : (int)post_num('company_id');
    $active   = post('active') === '0' ? 0 : 1;
    $pw       = post('password');

    if ($username === '') { $errors[] = 'Username is required.'; }
    if ($fullname === '') { $errors[] = 'Full name is required.'; }
    if (!in_array($role, ['admin', 'store_manager', 'store_keeper'], true)) { $errors[] = 'Select a valid role.'; }
    if ($role === 'admin') { $co = null; }
    if ($role !== 'admin' && !$co) { $errors[] = 'Store managers and store keepers must be linked to a company.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'The e-mail address looks invalid.'; }
    if (fetch_val('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id ?: 0])) {
        $errors[] = 'That username is already taken.';
    }
    if (!$u && strlen($pw) < 6) { $errors[] = 'The password must be at least 6 characters.'; }
    if ($u && $pw !== '' && strlen($pw) < 6) { $errors[] = 'The new password must be at least 6 characters.'; }
    if ($u && (int)$u['id'] === (int)current_user()['id'] && $role !== 'admin') {
        $errors[] = 'You cannot remove your own administrator role.';
    }

    if (!$errors) {
        if ($u) {
            q('UPDATE users SET username=?, full_name=?, email=?, role=?, company_id=?, active=? WHERE id=?',
              [$username, $fullname, $email, $role, $co, $active, $id]);
            if ($pw !== '') {
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            }
            audit('update', 'users', $id, $username . ' (' . $role . ')');
            flash('User <strong>' . e($username) . '</strong> updated.', 'success');
        } else {
            q('INSERT INTO users (username, password_hash, full_name, email, role, company_id, active) VALUES (?,?,?,?,?,?,?)',
              [$username, password_hash($pw, PASSWORD_DEFAULT), $fullname, $email, $role, $co, $active]);
            $newId = (int)db()->lastInsertId();
            audit('insert', 'users', $newId, $username . ' (' . $role . ')');
            flash('User <strong>' . e($username) . '</strong> created.', 'success');
        }
        redirect('users.php');
    }
    $u = array_merge($u ?: [], ['username' => $username, 'full_name' => $fullname, 'email' => $email,
        'role' => $role, 'company_id' => $co, 'active' => $active]);
}

$page_title = $u && $id ? 'Edit user' : 'New user';
$active_nav = 'users.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit user' : 'New user' ?></h1>
    <p class="text-muted mb-0">Assign one of the three access levels and (for non-admins) a company scope</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('users.php')) ?>"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-lg-8">
    <div class="form-section">
      <h6>Account</h6>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label required">Username</label>
          <input type="text" name="username" class="form-control mono" value="<?= e($u['username'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label required">Full name</label>
          <input type="text" name="full_name" class="form-control" value="<?= e($u['full_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" value="<?= e($u['email'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label required">Access level</label>
          <select name="role" class="form-select" id="role">
            <?php foreach (['admin' => 'Administrator', 'store_manager' => 'Store Manager', 'store_keeper' => 'Store Keeper'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($u['role'] ?? 'store_keeper') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Company scope</label>
          <select name="company_id" class="form-select">
            <option value="all" <?= empty($u['company_id']) ? 'selected' : '' ?>>All companies (administrators)</option>
            <?php foreach (fetch_all('SELECT * FROM companies ORDER BY name') as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($u['company_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= $id ? 'New password (leave blank to keep)' : 'Password' ?></label>
          <input type="text" name="password" class="form-control" <?= $id ? '' : 'required' ?> minlength="6">
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="active" class="form-select">
            <option value="1" <?= (int)($u['active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= isset($u['active']) && (int)$u['active'] === 0 ? 'selected' : '' ?>>Disabled</option>
          </select>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $id ? 'Save changes' : 'Create user' ?></button>
      <a class="btn btn-outline-secondary" href="<?= e(url('users.php')) ?>">Cancel</a>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-shield-check"></i> Permission matrix</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Ability</th><th class="text-center">Admin</th><th class="text-center">Manager</th><th class="text-center">Keeper</th></tr></thead>
          <tbody>
          <?php
          $matrix = [
              'View dashboard & reports' => [1, 1, 1],
              'Record movements'         => [1, 1, 1],
              'Transfers between stores' => [1, 1, 1],
              'Prepare delivery notes'   => [1, 1, 1],
              'Issue / approve DN'       => [1, 1, 0],
              'Manage items & masters'   => [1, 1, 0],
              'Delete items/movements'   => [1, 1, 0],
              'Export to Excel / CSV'    => [1, 1, 0],
              'Manage users'             => [1, 0, 0],
              'Manage companies'         => [1, 0, 0],
              'View audit trail'         => [1, 0, 0],
          ];
          foreach ($matrix as $label => $row): ?>
            <tr><td><small><?= e($label) ?></small></td>
              <?php foreach ($row as $ok): ?>
                <td class="text-center"><?= $ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
