<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_suppliers');

$id = (int)get_num('id');
$s  = $id ? fetch_one('SELECT * FROM suppliers WHERE id = ?', [$id]) : null;
if ($id && !$s) { flash('Supplier not found.', 'danger'); redirect('suppliers.php'); }
if ($s) { guard_company((int)$s['company_id']); }

$scope = scope_company_id();
$errors = [];
$defCo  = $s['company_id'] ?? ($scope ?: (int)fetch_val('SELECT id FROM companies ORDER BY id LIMIT 1'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $co   = $scope ?: (int)post_num('company_id');
    $code = strtoupper(post('code'));
    $name = post('name');
    if (!$co) { $errors[] = 'Select a company.'; } else { guard_company($co); }
    if ($code === '') { $errors[] = 'Supplier code is required.'; }
    if ($name === '') { $errors[] = 'Supplier name is required.'; }
    if (fetch_val('SELECT id FROM suppliers WHERE company_id = ? AND code = ? AND id <> ?', [$co, $code, $id ?: 0])) {
        $errors[] = 'Supplier code "' . e($code) . '" already exists in this company.';
    }
    if (!$errors) {
        $vals = [$co, $code, $name, post('contact_person'), post('phone'), post('email'), post('address'),
                 post('trn'), post('active') === '0' ? 0 : 1];
        if ($s) {
            q('UPDATE suppliers SET company_id=?, code=?, name=?, contact_person=?, phone=?, email=?, address=?, trn=?, active=? WHERE id=?',
              array_merge($vals, [$id]));
            audit('update', 'suppliers', $id, $code . ' / ' . $name);
            flash('Supplier <strong>' . e($name) . '</strong> updated.', 'success');
        } else {
            q('INSERT INTO suppliers (company_id, code, name, contact_person, phone, email, address, trn, active) VALUES (?,?,?,?,?,?,?,?,?)', $vals);
            $newId = (int)db()->lastInsertId();
            audit('insert', 'suppliers', $newId, $code . ' / ' . $name);
            flash('Supplier <strong>' . e($name) . '</strong> created.', 'success');
        }
        redirect('suppliers.php');
    }
    $s = array_merge($s ?: [], ['company_id' => $co, 'code' => $code, 'name' => $name,
        'contact_person' => post('contact_person'), 'phone' => post('phone'), 'email' => post('email'),
        'address' => post('address'), 'trn' => post('trn'), 'active' => post('active') === '0' ? 0 : 1]);
}

$page_title = $s && $id ? 'Edit supplier' : 'New supplier';
$active_nav = 'suppliers.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit supplier' : 'New supplier' ?></h1>
    <p class="text-muted mb-0">Suppliers can be assigned to items and recorded on receipts (IN movements)</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('suppliers.php')) ?>"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-lg-8">
    <div class="form-section">
      <h6>Supplier details</h6>
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
          <label class="form-label required">Supplier code</label>
          <input type="text" name="code" class="form-control mono" value="<?= e($s['code'] ?? '') ?>" required maxlength="30">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="active" class="form-select">
            <option value="1" <?= (int)($s['active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= isset($s['active']) && (int)$s['active'] === 0 ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label required">Supplier name</label>
          <input type="text" name="name" class="form-control" value="<?= e($s['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Contact person</label>
          <input type="text" name="contact_person" class="form-control" value="<?= e($s['contact_person'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Telephone</label>
          <input type="text" name="phone" class="form-control" value="<?= e($s['phone'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" value="<?= e($s['email'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">TRN / VAT number</label>
          <input type="text" name="trn" class="form-control mono" value="<?= e($s['trn'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" value="<?= e($s['address'] ?? '') ?>">
        </div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $id ? 'Save changes' : 'Create supplier' ?></button>
      <a class="btn btn-outline-secondary" href="<?= e(url('suppliers.php')) ?>">Cancel</a>
    </div>
  </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
