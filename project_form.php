<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_projects');

$id = (int)get_num('id');
$p  = $id ? fetch_one('SELECT * FROM projects WHERE id = ?', [$id]) : null;
if ($id && !$p) { flash('Project not found.', 'danger'); redirect('projects.php'); }
if ($p) { guard_company((int)$p['company_id']); }

$scope   = scope_company_id();
$errors  = [];
$defCo   = $p['company_id'] ?? ($scope ?: (int)fetch_val('SELECT id FROM companies ORDER BY id LIMIT 1'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $co     = $scope ?: (int)post_num('company_id');
    $code   = post('code');
    $name   = post('name');
    $client = post('client_name');
    $loc    = post('location');
    $status = post('status');

    if (!$co)                       { $errors[] = 'Select a company.'; }
    else { guard_company($co); }
    if ($code === '')               { $errors[] = 'Project code is required.'; }
    if ($name === '')               { $errors[] = 'Project name is required.'; }
    if (!in_array($status, ['active', 'completed', 'on_hold'], true)) { $status = 'active'; }
    if (fetch_val('SELECT id FROM projects WHERE company_id = ? AND code = ? AND id <> ?', [$co, $code, $id ?: 0])) {
        $errors[] = 'Project code "' . e($code) . '" already exists in this company.';
    }

    if (!$errors) {
        if ($p) {
            q('UPDATE projects SET company_id=?, code=?, name=?, client_name=?, location=?, status=? WHERE id=?',
              [$co, $code, $name, $client, $loc, $status, $id]);
            audit('update', 'projects', $id, $code . ' / ' . $name);
            flash('Project <strong>' . e($code) . '</strong> updated.', 'success');
        } else {
            q('INSERT INTO projects (company_id, code, name, client_name, location, status) VALUES (?,?,?,?,?,?)',
              [$co, $code, $name, $client, $loc, $status]);
            $newId = (int)db()->lastInsertId();
            audit('insert', 'projects', $newId, $code . ' / ' . $name);
            flash('Project <strong>' . e($code) . '</strong> created — it is now available as a stock location.', 'success');
        }
        redirect('projects.php');
    }
    $p = array_merge($p ?: [], ['company_id' => $co, 'code' => $code, 'name' => $name,
        'client_name' => $client, 'location' => $loc, 'status' => $status]);
}

$page_title = $p && $id ? 'Edit project' : 'New project';
$active_nav = 'projects.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit project' : 'New project / site store' ?></h1>
    <p class="text-muted mb-0">Projects appear as stock locations for movements and delivery notes</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('projects.php')) ?>"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-lg-8">
    <div class="form-section">
      <h6>Project details</h6>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label required">Company</label>
          <?php if ($scope): ?>
            <input class="form-control" value="<?= e(company_name($scope)) ?>" disabled>
            <input type="hidden" name="company_id" value="<?= (int)$scope ?>">
          <?php else: ?>
            <select name="company_id" class="form-select" required>
              <?php foreach (companies_for_user() as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$defCo === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="col-md-3">
          <label class="form-label required">Project code</label>
          <input type="text" name="code" class="form-control mono" value="<?= e($p['code'] ?? '') ?>" required maxlength="30">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['active' => 'Active', 'completed' => 'Completed', 'on_hold' => 'On hold'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($p['status'] ?? 'active') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label required">Project name</label>
          <input type="text" name="name" class="form-control" value="<?= e($p['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Client / employer</label>
          <input type="text" name="client_name" class="form-control" value="<?= e($p['client_name'] ?? '') ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">Location</label>
          <input type="text" name="location" class="form-control" value="<?= e($p['location'] ?? '') ?>" placeholder="City / area / plot">
        </div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $id ? 'Save changes' : 'Create project' ?></button>
      <a class="btn btn-outline-secondary" href="<?= e(url('projects.php')) ?>">Cancel</a>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-diagram-3"></i> Stock at this location</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if ($id):
            $locs = fetch_all('SELECT s.quantity, i.item_code, i.item_name, i.uom FROM stock_locations s
                               JOIN items i ON i.id = s.item_id WHERE s.project_id = ? AND s.quantity > 0.0001
                               ORDER BY s.quantity DESC LIMIT 15', [$id]);
            foreach ($locs as $l): ?>
              <tr><td><span class="mono small"><?= e($l['item_code']) ?></span><div class="small text-muted"><?= e($l['item_name']) ?></div></td>
                  <td class="text-end"><?= e(fmt_qty($l['quantity'])) ?> <small class="text-muted"><?= e($l['uom']) ?></small></td></tr>
            <?php endforeach; ?>
            <?php if (!$locs): ?><tr><td class="text-muted">No stock at this project yet.</td></tr><?php endif; ?>
          <?php else: ?>
            <tr><td class="text-muted">Save the project first to see its stock.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
