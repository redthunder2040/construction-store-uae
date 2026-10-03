<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_items');

$id     = (int)get_num('id');
$item   = $id ? fetch_one('SELECT * FROM items WHERE id = ?', [$id]) : null;
if ($id && !$item) { flash('Item not found.', 'danger'); redirect('items.php'); }
if ($item) { guard_company((int)$item['company_id']); }

$scope   = scope_company_id();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $companyId = (int)post_num('company_id');
    if ($scope) { $companyId = $scope; }
    if (!$companyId && !$scope) { $errors[] = 'Please select a company.'; }
    else { guard_company($companyId); }

    $code  = post('item_code');
    $name  = post('item_name');
    $uom   = post('uom');
    $qty   = (float)post_num('quantity');
    $cost  = (float)post_num('unit_cost');
    $reord = (float)post_num('reorder_level');
    $proj  = (int)post_num('project_id');
    $cat   = (int)post_num('category_id') ?: null;
    $sup   = (int)post_num('supplier_id') ?: null;
    $rack  = post('rack_no');
    $active= post('active') === '0' ? 0 : 1;

    if ($code === '')                       { $errors[] = 'Item code is required.'; }
    if ($name === '')                       { $errors[] = 'Item name is required.'; }
    if ($uom === '')                        { $errors[] = 'Unit of measure is required.'; }
    if ($qty < 0)                           { $errors[] = 'Quantity cannot be negative.'; }
    if ($cost < 0)                          { $errors[] = 'Unit cost cannot be negative.'; }
    if ($proj && !fetch_val('SELECT id FROM projects WHERE id = ? AND company_id = ?', [$proj, $companyId])) {
        $errors[] = 'The selected project does not belong to the chosen company.';
    }

    $dupe = fetch_one('SELECT id FROM items WHERE company_id = ? AND item_code = ? AND id <> ?', [$companyId, $code, $id ?: 0]);
    if ($dupe) { $errors[] = 'Item code "' . e($code) . '" already exists for this company.'; }
    if (!$cat && $name !== '') { $cat = null; }

    if (!$errors) {
        db()->beginTransaction();
        try {
            if ($item) {
                $oldQty = (float)$item['quantity'];
                q('UPDATE items SET company_id = ?, item_code = ?, item_name = ?, category_id = ?, uom = ?,
                        reorder_level = ?, unit_cost = ?, supplier_id = ?, rack_no = ?, active = ?, updated_at = NOW()
                   WHERE id = ?',
                   [$companyId, $code, $name, $cat, $uom, $reord, $cost, $sup, $rack, $active, $id]);

                // Quantity edited manually → record an ADJUST movement so stock history stays explainable.
                $diff = round($qty - $oldQty, 3);
                if (abs($diff) > 0.0001) {
                    record_movement([
                        'company_id' => $companyId, 'item_id' => $id,
                        'movement_type' => 'ADJUST', 'quantity' => $diff,
                        'uom' => $uom, 'unit_cost' => $cost, 'project_id' => $proj,
                        'reference' => 'ADJ-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)),
                        'movement_date' => date('Y-m-d'),
                        'notes' => 'Manual adjustment via item master edit (' . fmt_qty($oldQty) . ' → ' . fmt_qty($qty) . ')',
                    ]);
                } elseif ((int)$item['project_id'] !== $proj) {
                    // Relocation: move the whole balance to the newly selected location.
                    $loc = fetch_one('SELECT quantity FROM stock_locations WHERE item_id = ? AND project_id = ?', [$id, (int)$item['project_id']]);
                    $bal = $loc ? (float)$loc['quantity'] : 0.0;
                    if ($bal > 0) {
                        q('UPDATE stock_locations SET quantity = 0 WHERE item_id = ? AND project_id = ?', [$id, (int)$item['project_id']]);
                        q('INSERT INTO stock_locations (item_id, company_id, project_id, quantity) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)', [$id, $companyId, $proj, $bal]);
                    }
                    q('UPDATE items SET project_id = ? WHERE id = ?', [$proj, $id]);
                }
                audit('update', 'items', $id, ['code' => $code, 'qty_before' => $oldQty, 'qty_after' => $qty]);
                flash('Item <strong>' . e($code) . '</strong> updated.', 'success');
            } else {
                q('INSERT INTO items (company_id, item_code, item_name, category_id, uom, quantity, opening_qty,
                        reorder_level, unit_cost, project_id, supplier_id, rack_no, active)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                   [$companyId, $code, $name, $cat, $uom, 0, 0, $reord, $cost, $proj, $sup, $rack, $active]);
                $newId = (int)db()->lastInsertId();

                if (abs($qty) > 0.0001) {
                    record_movement([
                        'company_id' => $companyId, 'item_id' => $newId, 'movement_type' => 'IN',
                        'quantity' => $qty, 'uom' => $uom, 'unit_cost' => $cost, 'project_id' => $proj,
                        'supplier_id' => $sup, 'reference' => 'OPENING-' . $code,
                        'movement_date' => post('opening_date') !== '' ? post('opening_date') : date('Y-m-d'),
                        'notes' => 'Opening stock entered with the item record',
                    ]);
                }
                audit('insert', 'items', $newId, ['code' => $code, 'qty' => $qty, 'cost' => $cost]);
                flash('Item <strong>' . e($code) . '</strong> created with opening stock ' . fmt_qty($qty) . ' ' . e($uom) . '.', 'success');
            }
            db()->commit();
            redirect('items.php');
        } catch (Throwable $ex) {
            db()->rollBack();
            $errors[] = 'Could not save: ' . e($ex->getMessage());
        }
    }
    $item = array_merge($item ?: [], [
        'company_id' => $companyId, 'item_code' => $code, 'item_name' => $name, 'category_id' => $cat,
        'uom' => $uom, 'quantity' => $qty, 'reorder_level' => $reord, 'unit_cost' => $cost,
        'project_id' => $proj, 'supplier_id' => $sup, 'rack_no' => $rack, 'active' => $active,
    ]);
}

$page_title = $item && $id ? 'Edit item' : 'New item';
$active_nav = 'items.php';
$defaultCo  = $item['company_id'] ?? ($scope ?: (int)fetch_val('SELECT id FROM companies ORDER BY id LIMIT 1'));
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit item' : 'New item' ?></h1>
    <p class="text-muted mb-0"><?= $id ? 'Code ' . e($item['item_code']) : 'Add an item to the master catalogue' ?></p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('items.php')) ?>"><i class="bi bi-arrow-left"></i> Back to list</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-lg-8">
    <div class="form-section">
      <h6>Identification</h6>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label required">Company</label>
          <?php if ($scope): ?>
            <input class="form-control" value="<?= e(company_name($scope)) ?>" disabled>
            <input type="hidden" name="company_id" value="<?= (int)$scope ?>">
          <?php else: ?>
            <select name="company_id" class="form-select" required>
              <?php foreach (companies_for_user() as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$defaultCo === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label required">Item code</label>
          <input type="text" name="item_code" class="form-control mono" value="<?= e($item['item_code'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Barcode / rack no.</label>
          <input type="text" name="rack_no" class="form-control mono" value="<?= e($item['rack_no'] ?? '') ?>" placeholder="e.g. CEM-04-12">
        </div>
        <div class="col-md-8">
          <label class="form-label required">Item name</label>
          <input type="text" name="item_name" class="form-control" value="<?= e($item['item_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-select">
            <option value="0">— none —</option>
            <?php foreach (fetch_all('SELECT * FROM categories ORDER BY name') as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($item['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-section">
      <h6>Quantity, unit &amp; location</h6>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label required">UOM</label>
          <select name="uom" class="form-select" required>
            <?php foreach (fetch_all('SELECT * FROM uoms ORDER BY code') as $u): ?>
              <option value="<?= e($u['code']) ?>" <?= ($item['uom'] ?? '') === $u['code'] ? 'selected' : '' ?>><?= e($u['code'] . ' — ' . $u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= $id ? 'Quantity on hand (total)' : 'Opening quantity' ?></label>
          <input type="number" step="0.001" min="0" name="quantity" class="form-control" value="<?= e($item['quantity'] ?? 0) ?>">
          <?php if ($id): ?><small class="text-muted">Changing this posts an ADJUST movement.</small><?php endif; ?>
        </div>
        <div class="col-md-3">
          <label class="form-label">Reorder level</label>
          <input type="number" step="0.001" min="0" name="reorder_level" class="form-control" value="<?= e($item['reorder_level'] ?? 0) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Unit cost (<?= e(APP_CURRENCY) ?>)</label>
          <input type="number" step="0.01" min="0" name="unit_cost" class="form-control" value="<?= e($item['unit_cost'] ?? 0) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Storage location</label>
          <select name="project_id" class="form-select">
            <?php foreach (locations_for_select($defaultCo) as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= (int)($item['project_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Default supplier</label>
          <select name="supplier_id" class="form-select">
            <option value="0">— none —</option>
            <?php foreach (suppliers_for_select($defaultCo) as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)($item['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' — ' . $s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!$id): ?>
        <div class="col-md-6">
          <label class="form-label">Opening date</label>
          <input type="date" name="opening_date" class="form-control" value="<?= e(date('Y-m-d')) ?>">
        </div>
        <?php endif; ?>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="active" class="form-select">
            <option value="1" <?= (int)($item['active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= isset($item['active']) && (int)$item['active'] === 0 ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $id ? 'Save changes' : 'Create item' ?></button>
      <a class="btn btn-outline-secondary" href="<?= e(url('items.php')) ?>">Cancel</a>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-info-circle"></i> Guidance</h5></div>
      <div class="card-body small text-muted">
        <ul class="ps-3 mb-0">
          <li>Item codes must be unique <strong>within a company</strong>.</li>
          <li>The quantity you type is the company-wide balance; it is written to the selected storage location.</li>
          <li>Editing the quantity later creates an <span class="badge bg-secondary">ADJUST</span> movement so the audit trail stays complete.</li>
          <li>Use <strong>Movements → Transfer stock</strong> to move quantities between projects instead of editing this field.</li>
          <li>Items with transactions cannot be deleted — they are set to inactive.</li>
        </ul>
      </div>
    </div>
    <?php if ($id):
      $locs = fetch_all('SELECT s.*, p.code, p.name FROM stock_locations s LEFT JOIN projects p ON p.id = s.project_id
                         WHERE s.item_id = ? AND s.quantity > 0.0001 ORDER BY s.quantity DESC', [$id]); ?>
    <div class="card app-card mt-3">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-geo-alt"></i> Where the stock is</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php foreach ($locs as $l): ?>
            <tr><td><?= e($l['code'] ? $l['code'] . ' — ' . $l['name'] : 'Main Store') ?></td>
                <td class="text-end fw-semibold"><?= e(fmt_qty($l['quantity'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$locs): ?><tr><td class="text-muted">No stock at any location.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
