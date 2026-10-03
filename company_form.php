<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_companies');

$id   = (int)get_num('id');
$co   = $id ? fetch_one('SELECT * FROM companies WHERE id = ?', [$id]) : null;
if ($id && !$co) { flash('Company not found.', 'danger'); redirect('companies.php'); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = [
        'code' => strtoupper(post('code')), 'name' => post('name'), 'address' => post('address'),
        'phone' => post('phone'), 'email' => post('email'), 'trn' => post('trn'),
        'active' => post('active') === '0' ? 0 : 1,
    ];
    if ($data['code'] === '') { $errors[] = 'Company code is required.'; }
    if ($data['name'] === '') { $errors[] = 'Company name is required.'; }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'The e-mail address looks invalid.'; }
    if (fetch_val('SELECT id FROM companies WHERE code = ? AND id <> ?', [$data['code'], $id ?: 0])) {
        $errors[] = 'Company code "' . e($data['code']) . '" is already used.';
    }

    /* -------------------------------------------------- letterhead upload */
    $letterhead = $co['letterhead'] ?? null;
    if (!empty($_FILES['letterhead']['name']) && ($_FILES['letterhead']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['letterhead']['tmp_name'];
        $size = (int)$_FILES['letterhead']['size'];
        $info = @getimagesize($tmp);
        $ext  = strtolower(pathinfo($_FILES['letterhead']['name'], PATHINFO_EXTENSION));
        if (!$info) {
            $errors[] = 'The letterhead file is not a readable image (use PNG, JPG or GIF).';
        } elseif ($size > 3 * 1024 * 1024) {
            $errors[] = 'Letterhead image is larger than 3 MB. Please shrink it (recommended ~1200 × 400 px).';
        } elseif (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $errors[] = 'Unsupported letterhead format. Use PNG, JPG or GIF.';
        } else {
            $dir = __DIR__ . '/assets/letterheads';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $fname = 'lh_' . preg_replace('/[^A-Za-z0-9]/', '', $data['code'] ?: 'co') . '_' . date('YmdHis') . '.' . $ext;
            if (@move_uploaded_file($tmp, $dir . '/' . $fname)) {
                if ($letterhead && is_file($dir . '/' . $letterhead)) { @unlink($dir . '/' . $letterhead); }
                $letterhead = $fname;
            } else {
                $errors[] = 'Could not save the uploaded letterhead — check that assets/letterheads is writable.';
            }
        }
    }
    if (post('remove_letterhead') === '1' && $letterhead) {
        $f = __DIR__ . '/assets/letterheads/' . $letterhead;
        if (is_file($f)) { @unlink($f); }
        $letterhead = null;
    }

    if (!$errors) {
        if ($co) {
            q('UPDATE companies SET code=?, name=?, address=?, phone=?, email=?, trn=?, letterhead=?, active=? WHERE id=?',
              [$data['code'], $data['name'], $data['address'], $data['phone'], $data['email'], $data['trn'], $letterhead, $data['active'], $id]);
            audit('update', 'companies', $id, $data['name']);
            flash('Company <strong>' . e($data['name']) . '</strong> updated.', 'success');
        } else {
            q('INSERT INTO companies (code, name, address, phone, email, trn, letterhead, active) VALUES (?,?,?,?,?,?,?,?)',
              [$data['code'], $data['name'], $data['address'], $data['phone'], $data['email'], $data['trn'], $letterhead, $data['active']]);
            $newId = (int)db()->lastInsertId();
            audit('insert', 'companies', $newId, $data['name']);
            flash('Company <strong>' . e($data['name']) . '</strong> created.', 'success');
        }
        redirect('companies.php');
    }
    $co = array_merge($co ?: [], $data, ['letterhead' => $letterhead]);
}

$page_title = $co && $id ? 'Edit company' : 'New company';
$active_nav = 'companies.php';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit company' : 'New company' ?></h1>
    <p class="text-muted mb-0">Company details also supply the header printed on reports and delivery notes</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('companies.php')) ?>"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-lg-7">
    <div class="form-section">
      <h6>Company details</h6>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label required">Company code</label>
          <input type="text" name="code" class="form-control mono" value="<?= e($co['code'] ?? '') ?>" required maxlength="20">
          <small class="text-muted">Short code used in document numbers (e.g. ANC).</small>
        </div>
        <div class="col-md-8">
          <label class="form-label required">Company name</label>
          <input type="text" name="name" class="form-control" value="<?= e($co['name'] ?? '') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" value="<?= e($co['address'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Telephone</label>
          <input type="text" name="phone" class="form-control" value="<?= e($co['phone'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" value="<?= e($co['email'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Tax / TRN number</label>
          <input type="text" name="trn" class="form-control mono" value="<?= e($co['trn'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="active" class="form-select">
            <option value="1" <?= (int)($co['active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= isset($co['active']) && (int)$co['active'] === 0 ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $id ? 'Save changes' : 'Create company' ?></button>
      <a class="btn btn-outline-secondary" href="<?= e(url('companies.php')) ?>">Cancel</a>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-image"></i> Letterhead for printing</h5></div>
      <div class="card-body">
        <p class="small text-muted">Upload your pre-printed letterhead (PNG or JPG, recommended 1200 × 400 px, max 3 MB).
          It will be placed at the top of every printed report and delivery note for this company.</p>
        <?php if (!empty($co['letterhead'])): ?>
          <div class="mb-3 p-2 border rounded">
            <img src="<?= e(url('assets/letterheads/' . $co['letterhead'])) ?>" class="img-fluid" alt="Letterhead preview">
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="remove_letterhead" value="1" id="rmlh">
              <label class="form-check-label small" for="rmlh">Remove this letterhead</label>
            </div>
          </div>
        <?php endif; ?>
        <input type="file" name="letterhead" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp">
        <p class="small text-muted mt-2 mb-0">If no letterhead is uploaded, the printed header shows the company name,
          address, phone, e-mail and TRN as text.</p>
      </div>
    </div>
    <?php if ($co && $id): ?>
    <div class="card app-card mt-3">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-graph-up"></i> Data in this company</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><td>Items</td><td class="text-end"><?= (int)fetch_val('SELECT COUNT(*) FROM items WHERE company_id = ?', [$id]) ?></td></tr>
            <tr><td>Projects</td><td class="text-end"><?= (int)fetch_val('SELECT COUNT(*) FROM projects WHERE company_id = ?', [$id]) ?></td></tr>
            <tr><td>Suppliers</td><td class="text-end"><?= (int)fetch_val('SELECT COUNT(*) FROM suppliers WHERE company_id = ?', [$id]) ?></td></tr>
            <tr><td>Movements</td><td class="text-end"><?= (int)fetch_val('SELECT COUNT(*) FROM movements WHERE company_id = ?', [$id]) ?></td></tr>
            <tr><td>Delivery notes</td><td class="text-end"><?= (int)fetch_val('SELECT COUNT(*) FROM delivery_notes WHERE company_id = ?', [$id]) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
