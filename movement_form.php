<?php
require_once __DIR__ . '/helpers.php';
require_login();

$id   = (int)get_num('id');
$edit = null;
if ($id) {
    $edit = fetch_one('SELECT * FROM movements WHERE id = ?', [$id]);
    if (!$edit) { flash('Movement not found.', 'danger'); redirect('movements.php'); }
    guard_company((int)$edit['company_id']);
    require_can('delete_movement');   // editing reverses and re-posts, so it needs the higher right
} else {
    require_can('record_movement');
}

$scope      = scope_company_id();
$errors     = [];
$itemRow    = $edit ? fetch_one('SELECT * FROM items WHERE id = ?', [(int)$edit['item_id']]) : null;
$formCo     = $edit ? (int)$edit['company_id'] : ($scope ?: (int)fetch_val('SELECT id FROM companies ORDER BY id LIMIT 1'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $companyId = $scope ?: (int)post_num('company_id');
    $itemId    = (int)post_num('item_id');
    $type      = post('movement_type');
    $qty       = (float)post_num('quantity');
    $date      = post('movement_date');
    $cost      = (float)post_num('unit_cost');
    $proj      = (int)post_num('project_id');
    $fromP     = (int)post_num('from_project_id');
    $toP       = (int)post_num('to_project_id');
    $sup       = (int)post_num('supplier_id');
    $ref       = post('reference');
    $notes     = post('notes');

    $itemRow = $itemId ? fetch_one('SELECT * FROM items WHERE id = ?', [$itemId]) : null;

    if (!$itemId || !$itemRow)                              { $errors[] = 'Please select a valid item.'; }
    if (!array_key_exists($type, MOVEMENT_DIRECTION))        { $errors[] = 'Please select a movement type.'; }
    if ($qty <= 0)                                           { $errors[] = 'Quantity must be greater than zero.'; }
    if (!is_dob($date))                                      { $errors[] = 'Please provide a valid movement date.'; }
    if ($itemRow && (int)$itemRow['company_id'] !== $companyId && $companyId) {
        $errors[] = 'The selected item belongs to a different company.';
    }
    if ($type === 'IN' && !$proj && !get('allow_main')) { /* main store = 0 is valid */ }
    if ($type === 'TRANSFER_IN' && !$toP)  { $errors[] = 'Select the destination store for the transfer in.'; }
    if ($type === 'TRANSFER_OUT' && !$fromP){ $errors[] = 'Select the source store for the transfer out.'; }
    if (in_array($type, ['TRANSFER_IN', 'TRANSFER_OUT'], true) && $fromP === $toP) {
        $errors[] = 'Source and destination must be different.';
    }
    if ($type === 'OUT') {
        $loc = fetch_one('SELECT quantity FROM stock_locations WHERE item_id = ? AND project_id = ?', [$itemId, $proj]);
        $avail = $loc ? (float)$loc['quantity'] : 0;
        if ($edit && (int)$edit['item_id'] === $itemId && (int)$edit['project_id'] === $proj
            && in_array($edit['movement_type'], ['OUT'], true)) {
            $avail += (float)$edit['quantity'];   // the old issue is reversed first
        }
        if ($qty > $avail + 0.0001) {
            $errors[] = 'Cannot issue ' . fmt_qty($qty) . ' — only ' . fmt_qty($avail) . ' available at '
                . e(project_name($proj)) . '.';
        }
    }
    if ($type === 'TRANSFER_OUT') {
        $loc = fetch_one('SELECT quantity FROM stock_locations WHERE item_id = ? AND project_id = ?', [$itemId, $fromP]);
        $avail = $loc ? (float)$loc['quantity'] : 0;
        if ($edit && (int)$edit['item_id'] === $itemId && (int)$edit['from_project_id'] === $fromP
            && $edit['movement_type'] === 'TRANSFER_OUT') {
            $avail += (float)$edit['quantity'];
        }
        if ($qty > $avail + 0.0001) {
            $errors[] = 'Cannot transfer ' . fmt_qty($qty) . ' — only ' . fmt_qty($avail) . ' available at '
                . e(project_name($fromP)) . '.';
        }
    }

    if (!$errors) {
        if ($cost <= 0 && $itemRow) { $cost = (float)$itemRow['unit_cost']; }
        db()->beginTransaction();
        try {
            if ($edit) {
                reverse_movement_effect($edit);
                q('DELETE FROM movements WHERE id = ?', [$id]);
            }
            $newId = record_movement([
                'company_id' => $companyId, 'item_id' => $itemId, 'movement_type' => $type,
                'quantity' => $qty, 'uom' => $itemRow['uom'], 'unit_cost' => $cost,
                'project_id' => $proj, 'from_project_id' => $fromP, 'to_project_id' => $toP,
                'supplier_id' => $sup, 'transfer_ref' => post('transfer_ref') ?: null,
                'reference' => $ref !== '' ? $ref : 'MOV-' . date('ymd', strtotime($date)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)),
                'movement_date' => $date, 'notes' => $notes,
            ]);
            db()->commit();
            flash($edit ? 'Movement updated (previous entry reversed).' : 'Movement recorded successfully.', 'success');
            redirect('movements.php?term=' . urlencode($itemRow['item_code']));
        } catch (Throwable $ex) {
            db()->rollBack();
            $errors[] = 'Could not save the movement: ' . e($ex->getMessage());
        }
    }
    $edit = array_merge($edit ?: [], [
        'company_id' => $companyId, 'item_id' => $itemId, 'movement_type' => $type, 'quantity' => $qty,
        'movement_date' => $date, 'unit_cost' => $cost, 'project_id' => $proj, 'from_project_id' => $fromP,
        'to_project_id' => $toP, 'supplier_id' => $sup, 'reference' => $ref, 'notes' => $notes,
    ]);
}

$page_title = $edit ? 'Edit movement' : 'Record movement';
$active_nav = 'movements.php';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1><?= $id ? 'Edit movement #' . (int)$id : 'Record movement' ?></h1>
    <p class="text-muted mb-0">Receive, issue or adjust stock at any store or project location</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(url('movements.php')) ?>"><i class="bi bi-arrow-left"></i> Movement register</a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" class="row g-3" id="movement-form">
  <?= csrf_field() ?>
  <div class="col-lg-8">
    <div class="form-section">
      <h6>Movement details</h6>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label required">Company</label>
          <?php if ($scope): ?>
            <input class="form-control" value="<?= e(company_name($scope)) ?>" disabled>
            <input type="hidden" name="company_id" value="<?= (int)$scope ?>">
          <?php else: ?>
            <select name="company_id" class="form-select" id="company_id">
              <?php foreach (companies_for_user() as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $formCo === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label required">Movement type</label>
          <select name="movement_type" id="movement_type" class="form-select" required>
            <?php foreach (['IN' => 'Receipt — goods received (IN)',
                            'OUT' => 'Issue — consumption / dispatch (OUT)',
                            'TRANSFER_OUT' => 'Transfer out — send to another site',
                            'TRANSFER_IN' => 'Transfer in — receive from another site',
                            'ADJUST' => 'Adjustment — stock count correction'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($edit['movement_type'] ?? 'IN') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label required">Date</label>
          <input type="date" name="movement_date" class="form-control" required
                 value="<?= e($edit['movement_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="col-md-6 item-lookup">
          <label class="form-label required">Item</label>
          <input type="text" id="item_search" class="form-control" autocomplete="off" placeholder="Type at least 2 characters…"
                 data-endpoint="<?= e(url('item_lookup.php')) ?>"
                 value="<?= e($itemRow ? $itemRow['item_code'] . ' — ' . $itemRow['item_name'] : '') ?>">
          <input type="hidden" name="item_id" id="item_id" value="<?= (int)($edit['item_id'] ?? 0) ?>">
          <div class="lookup-results" id="lookup_results"></div>
          <div id="item_info" class="small mt-2 <?= $itemRow ? '' : 'd-none' ?>">
            <?php if ($itemRow): ?><strong><?= e($itemRow['item_code']) ?></strong> — <?= e($itemRow['item_name']) ?>
              <span class="badge bg-secondary"><?= e($itemRow['uom']) ?></span>
              <span class="badge bg-info text-dark">On hand: <?= e(fmt_qty($itemRow['quantity'])) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label required">Quantity</label>
          <input type="number" step="0.001" min="0.001" name="quantity" id="quantity" class="form-control" required
                 value="<?= e($edit['quantity'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Unit cost (<?= e(APP_CURRENCY) ?>)</label>
          <input type="number" step="0.01" min="0" name="unit_cost" id="unit_cost" class="form-control"
                 value="<?= e($edit['unit_cost'] ?? ($itemRow['unit_cost'] ?? '')) ?>">
        </div>

        <div class="col-md-6" data-row="projectRow">
          <label class="form-label" id="target-label">Store location</label>
          <select name="project_id" id="project_id" class="form-select">
            <?php foreach (locations_for_select($formCo) as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= (int)($edit['project_id'] ?? ($itemRow['project_id'] ?? 0)) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6" data-row="fromProjectRow">
          <label class="form-label required">Transfer from</label>
          <select name="from_project_id" id="from_project_id" class="form-select">
            <?php foreach (locations_for_select($formCo) as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= (int)($edit['from_project_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6" data-row="toProjectRow">
          <label class="form-label required">Transfer to</label>
          <select name="to_project_id" class="form-select">
            <?php foreach (locations_for_select($formCo) as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= (int)($edit['to_project_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6" data-row="supplierRow">
          <label class="form-label">Supplier</label>
          <select name="supplier_id" class="form-select">
            <option value="0">— none / not applicable —</option>
            <?php foreach (suppliers_for_select($formCo) as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)($edit['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' — ' . $s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-8" data-row="refRow">
          <label class="form-label">Reference (GRN / MRN / PO no.)</label>
          <input type="text" name="reference" class="form-control mono" value="<?= e($edit['reference'] ?? '') ?>"
                 placeholder="Leave blank to auto-generate">
        </div>
        <div class="col-md-4">
          <label class="form-label">Transfer reference</label>
          <input type="text" name="transfer_ref" class="form-control mono" value="<?= e($edit['transfer_ref'] ?? '') ?>"
                 placeholder="optional">
        </div>

        <div class="col-12">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Purpose, site activity or remarks"><?= e($edit['notes'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <div id="availability" class="alert alert-light border small mb-0">Select an item to see available stock.</div>
        </div>
        <div class="col-12">
          <div class="totals-box">
            <div class="row-t"><span>Line value (qty × unit cost)</span><strong><?= e(APP_CURRENCY) ?> <span id="line_total">0.00</span></strong></div>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $id ? 'Update movement' : 'Save movement' ?></button>
      <a class="btn btn-outline-secondary" href="<?= e(url('movements.php')) ?>">Cancel</a>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-signpost-split"></i> Which type to choose</h5></div>
      <div class="card-body small">
        <p class="mb-2"><span class="badge bg-success">IN</span> Materials received from a supplier — increases stock at the chosen store.</p>
        <p class="mb-2"><span class="badge bg-danger">OUT</span> Material issued/consumed at a site — decreases stock.</p>
        <p class="mb-2"><span class="badge bg-warning">TRANSFER</span> Moves stock between the main warehouse and site stores. Use
          <a href="<?= e(url('transfers.php')) ?>">Transfer stock</a> to post both legs (out + in) in one go.</p>
        <p class="mb-0"><span class="badge bg-secondary">ADJUST</span> Corrects a physical stock-count difference.</p>
      </div>
    </div>
    <?php if ($id): ?>
    <div class="alert alert-warning small mt-3 mb-0">
      <i class="bi bi-exclamation-triangle"></i> Saving will <strong>reverse</strong> the current movement and post a new one,
      so balances and the audit trail stay consistent.
    </div>
    <?php endif; ?>
  </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
