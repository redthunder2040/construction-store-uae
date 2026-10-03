<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/includes/dn_engine.php';
require_login();
require_can('create_delivery_note');

$id = (int)get_num('id');
$dn = $id ? fetch_one('SELECT * FROM delivery_notes WHERE id = ?', [$id]) : null;
if ($id && !$dn) { flash('Delivery note not found.', 'danger'); redirect('delivery_notes.php'); }
if ($dn) { guard_company((int)$dn['company_id']); }

$scope  = scope_company_id();
$errors = [];
$lines  = $dn ? fetch_all('SELECT * FROM delivery_note_items WHERE dn_id = ? ORDER BY id', [$id]) : [];

$formCo = $dn ? (int)$dn['company_id'] : ($scope ?: (int)fetch_val('SELECT id FROM companies ORDER BY id LIMIT 1'));
$form   = $dn ?: [
    'dn_no' => next_dn_no($formCo), 'dn_date' => date('Y-m-d'), 'project_id' => 0, 'from_project_id' => 0,
    'supplier_id' => null, 'issued_to' => '', 'vehicle_no' => '', 'driver_name' => '', 'driver_contact' => '',
    'notes' => '', 'status' => 'draft', 'approved_by' => null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $companyId = $scope ?: (int)post_num('company_id');
    $dnNo      = post('dn_no');
    $dnDate    = post('dn_date');
    $projId    = (int)post_num('project_id');
    $fromP     = (int)post_num('from_project_id');
    $status    = post('status');
    $supId     = (int)post_num('supplier_id') ?: null;

    $itemIds = $_POST['item_id'] ?? [];
    $qtys    = $_POST['qty'] ?? [];
    $remarks = $_POST['remark'] ?? [];
    $uoms    = $_POST['uom'] ?? [];

    $clean = [];
    foreach ($itemIds as $k => $iid) {
        $iid = (int)$iid;
        $q   = (float)($qtys[$k] ?? 0);
        if ($iid > 0 && $q > 0) {
            $it = fetch_one('SELECT i.*, c.name AS cat FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ?', [$iid]);
            if (!$it) { continue; }
            $clean[] = ['item_id' => $iid, 'quantity' => $q, 'uom' => $it['uom'],
                        'remarks' => trim((string)($remarks[$k] ?? '')), 'item' => $it];
        }
    }

    if ($companyId <= 0)                          { $errors[] = 'Please select a company.'; }
    if ($dnNo === '')                             { $errors[] = 'Delivery note number is required.'; }
    if (!is_dob($dnDate))                         { $errors[] = 'Please provide a valid delivery date.'; }
    if ($projId <= 0)                             { $errors[] = 'Select the destination project / site.'; }
    if ($projId > 0 && !fetch_val('SELECT id FROM projects WHERE id = ? AND company_id = ?', [$projId, $companyId])) {
        $errors[] = 'The destination project does not belong to the selected company.';
    }
    if ($fromP === $projId)                       { $errors[] = 'Source and destination stores must be different.'; }
    if (!$clean)                                  { $errors[] = 'Add at least one item line with a quantity.'; }
    if (!in_array($status, ['draft', 'issued', 'received', 'cancelled'], true)) { $status = 'draft'; }
    if ($status === 'cancelled' && !can('approve_delivery_note')) { $errors[] = 'You cannot cancel a delivery note.'; }
    if (in_array($status, ['issued', 'received'], true) && !can('approve_delivery_note')) {
        $status = 'draft';   // keepers may prepare documents but not post them
    }
    $dupe = fetch_val('SELECT id FROM delivery_notes WHERE company_id = ? AND dn_no = ? AND id <> ?', [$companyId, $dnNo, $id ?: 0]);
    if ($dupe) { $errors[] = 'Delivery note number "' . e($dnNo) . '" already exists for this company.'; }

    /* availability check when the note actually moves stock */
    if (!$errors && in_array($status, ['issued', 'received'], true)) {
        foreach ($clean as $ln) {
            $loc = fetch_one('SELECT quantity FROM stock_locations WHERE item_id = ? AND project_id = ?',
                [$ln['item_id'], $fromP]);
            $avail = $loc ? (float)$loc['quantity'] : 0;
            if ($dn) {
                $old = fetch_one('SELECT quantity FROM delivery_note_items WHERE dn_id = ? AND item_id = ?', [$id, $ln['item_id']]);
                if ($old && (int)$dn['from_project_id'] === $fromP && (int)$dn['project_id'] === $projId
                    && in_array($dn['status'], ['issued', 'received'], true)) {
                    $avail += (float)$old['quantity'];
                }
            }
            if ($ln['quantity'] > $avail + 0.0001) {
                $errors[] = 'Item ' . e($ln['item']['item_code']) . ': only ' . fmt_qty($avail)
                    . ' available at ' . e(project_name($fromP)) . ' — cannot deliver ' . fmt_qty($ln['quantity']) . '.';
            }
        }
    }

    if (!$errors) {
        db()->beginTransaction();
        try {
            if ($dn) {
                clear_dn_movements($id);
                q('UPDATE delivery_notes SET company_id=?, dn_no=?, dn_date=?, project_id=?, from_project_id=?,
                       supplier_id=?, issued_to=?, vehicle_no=?, driver_name=?, driver_contact=?, status=?, notes=?
                   WHERE id=?',
                   [$companyId, $dnNo, $dnDate, $projId, $fromP, $supId, post('issued_to'), post('vehicle_no'),
                    post('driver_name'), post('driver_contact'), $status, post('notes'), $id]);
                q('DELETE FROM delivery_note_items WHERE dn_id = ?', [$id]);
                $dnId = $id;
            } else {
                q('INSERT INTO delivery_notes (company_id, dn_no, dn_date, project_id, from_project_id, supplier_id,
                        issued_to, vehicle_no, driver_name, driver_contact, status, notes, prepared_by, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                   [$companyId, $dnNo, $dnDate, $projId, $fromP, $supId, post('issued_to'), post('vehicle_no'),
                    post('driver_name'), post('driver_contact'), $status, post('notes'),
                    current_user()['id'], current_user()['id']]);
                $dnId = (int)db()->lastInsertId();
            }

            foreach ($clean as $ln) {
                q('INSERT INTO delivery_note_items (dn_id, item_id, quantity, uom, remarks) VALUES (?,?,?,?,?)',
                    [$dnId, $ln['item_id'], $ln['quantity'], $ln['uom'], $ln['remarks']]);
            }

            $saved = fetch_one('SELECT * FROM delivery_notes WHERE id = ?', [$dnId]);
            $posted = post_dn_movements($saved, $clean);
            audit($dn ? 'update' : 'insert', 'delivery_notes', $dnId,
                $dnNo . ' | ' . $status . ' | ' . count($clean) . ' lines | ' . $posted . ' movement legs');

            db()->commit();
            flash('Delivery note <strong>' . e($dnNo) . '</strong> saved as ' . e(ucfirst($status))
                . ($posted ? ' — ' . $posted . ' stock movements posted.' : ' (no stock movement yet).'), 'success');
            redirect('delivery_note_form.php?id=' . $dnId);
        } catch (Throwable $ex) {
            db()->rollBack();
            $errors[] = 'Could not save the delivery note: ' . e($ex->getMessage());
        }
    }

    $form = array_merge($form, [
        'company_id' => $companyId, 'dn_no' => $dnNo, 'dn_date' => $dnDate, 'project_id' => $projId,
        'from_project_id' => $fromP, 'supplier_id' => $supId, 'issued_to' => post('issued_to'),
        'vehicle_no' => post('vehicle_no'), 'driver_name' => post('driver_name'),
        'driver_contact' => post('driver_contact'), 'status' => $status, 'notes' => post('notes'),
    ]);
    $lines = $clean;
}

$page_title = $dn ? 'Delivery note ' . $dn['dn_no'] : 'New delivery note';
$active_nav = 'delivery_notes.php';
$itemRows   = items_for_select($formCo);
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1><?= $dn ? 'Delivery note ' . e($dn['dn_no']) : 'New delivery note' ?></h1>
    <p class="text-muted mb-0">Issue materials from the store to a project site and print the delivery note</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($dn): ?>
      <a class="btn btn-outline-primary" href="<?= e(url('delivery_note_print.php?id=' . (int)$dn['id'])) ?>" target="_blank"><i class="bi bi-printer"></i> Print / PDF</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(url('delivery_notes.php')) ?>"><i class="bi bi-arrow-left"></i> Register</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<form method="post" id="dn_form">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-9">
      <div class="form-section">
        <h6>Document header</h6>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label required">Company</label>
            <?php if ($scope): ?>
              <input class="form-control" value="<?= e(company_name($scope)) ?>" disabled>
              <input type="hidden" name="company_id" value="<?= (int)$scope ?>">
            <?php else: ?>
              <select name="company_id" class="form-select">
                <?php foreach (companies_for_user() as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $formCo === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <label class="form-label required">DN number</label>
            <input type="text" name="dn_no" class="form-control mono" value="<?= e($form['dn_no']) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label required">Date</label>
            <input type="date" name="dn_date" class="form-control" value="<?= e($form['dn_date']) ?>" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Deliver from (source store)</label>
            <select name="from_project_id" class="form-select">
              <?php foreach (locations_for_select($formCo) as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= (int)$form['from_project_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label required">Deliver to (project / site)</label>
            <select name="project_id" class="form-select" required>
              <option value="0">— select project —</option>
              <?php foreach (projects_for_select($formCo) as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (int)$form['project_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Issued to (site recipient)</label>
            <input type="text" name="issued_to" class="form-control" value="<?= e($form['issued_to']) ?>" placeholder="Site engineer / foreman name">
          </div>
          <div class="col-md-6">
            <label class="form-label">Supplier / source</label>
            <select name="supplier_id" class="form-select">
              <option value="0">— none —</option>
              <?php foreach (suppliers_for_select($formCo) as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= (int)$form['supplier_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' — ' . $s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Vehicle no.</label>
            <input type="text" name="vehicle_no" class="form-control mono" value="<?= e($form['vehicle_no']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Driver name</label>
            <input type="text" name="driver_name" class="form-control" value="<?= e($form['driver_name']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Driver contact</label>
            <input type="text" name="driver_contact" class="form-control" value="<?= e($form['driver_contact']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>Draft — does not move stock</option>
              <option value="issued" <?= $form['status'] === 'issued' ? 'selected' : '' ?>>Issued — moves stock to site</option>
              <option value="received" <?= $form['status'] === 'received' ? 'selected' : '' ?>>Received — confirmed by site</option>
              <?php if (can('approve_delivery_note')): ?>
                <option value="cancelled" <?= $form['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              <?php endif; ?>
            </select>
            <?php if (!can('approve_delivery_note')): ?>
              <small class="text-muted">Store keepers can prepare drafts; a manager must issue them.</small>
            <?php endif; ?>
          </div>
          <div class="col-12">
            <label class="form-label">Notes / instructions to site</label>
            <textarea name="notes" class="form-control" rows="2"><?= e($form['notes']) ?></textarea>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h6>Items delivered</h6>
        <div class="item-lookup mb-3">
          <label class="form-label">Add item — type a code or name and pick from the list</label>
          <input type="text" id="dn_item_search" class="form-control" autocomplete="off" placeholder="Start typing, e.g. CEM or cement…">
          <div class="lookup-results" id="dn_lookup_results"></div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle" id="dn_lines">
            <thead>
              <tr><th style="width:44px">#</th><th>Item</th><th style="width:80px">UOM</th>
                  <th style="width:130px">Quantity</th><th style="width:220px">Remarks</th><th style="width:50px"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $i => $ln):
              $it = fetch_one('SELECT * FROM items WHERE id = ?', [(int)$ln['item_id']]);
              if (!$it) continue; ?>
              <tr>
                <td class="line-no"><?= $i + 1 ?></td>
                <td>
                  <span class="mono small"><?= e($it['item_code']) ?></span>
                  <div class="small text-muted"><?= e($it['item_name']) ?></div>
                  <input type="hidden" name="item_id[]" value="<?= (int)$ln['item_id'] ?>">
                  <input type="hidden" name="uom[]" value="<?= e($it['uom']) ?>">
                </td>
                <td class="text-nowrap"><?= e($it['uom']) ?></td>
                <td><input type="number" step="0.001" min="0.001" name="qty[]" class="form-control form-control-sm qty-in" value="<?= e($ln['quantity']) ?>" required></td>
                <td><input type="text" name="remark[]" class="form-control form-control-sm" value="<?= e($ln['remarks'] ?? '') ?>" placeholder="Remarks"></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-x-lg"></i></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="totals-box mb-3">
          <div class="row-t"><span>Lines</span><strong id="dn_total_lines"><?= count($lines) ?></strong></div>
          <div class="row-t total"><span>Total quantity</span><strong id="dn_total_qty">0.000</strong></div>
        </div>

        <p class="small text-muted">Search above and click an item to append it as a line. Set the status to
          <strong>Issued</strong> to move the stock from the source store to the site store.</p>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-brand"><i class="bi bi-save"></i> <?= $dn ? 'Save delivery note' : 'Create delivery note' ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('delivery_notes.php')) ?>">Cancel</a>
        <?php if ($dn): ?>
          <button type="button" class="btn btn-outline-primary" onclick="window.open('<?= e(url('delivery_note_print.php?id=' . (int)$dn['id'])) ?>','_blank')">
            <i class="bi bi-printer"></i> Print
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-3">
      <div class="card app-card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-info-circle"></i> How it works</h5></div>
        <div class="card-body small text-muted">
          <ul class="ps-3 mb-0">
            <li><strong>Draft</strong> keeps the note on file without touching stock.</li>
            <li><strong>Issued / Received</strong> posts a paired stock movement (out of the source store, into the project store).</li>
            <li>Editing or deleting a posted note reverses its movements automatically.</li>
            <li>The printed note shows your uploaded company letterhead when one is set on the company record.</li>
          </ul>
        </div>
      </div>
      <?php if ($dn): ?>
      <div class="card app-card mt-3">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history"></i> Document</h5></div>
        <div class="card-body small">
          <p class="mb-1"><strong>Created:</strong> <?= e(fmt_dt($dn['created_at'])) ?></p>
          <p class="mb-1"><strong>Prepared by:</strong> <?= e((string)fetch_val('SELECT full_name FROM users WHERE id = ?', [(int)$dn['prepared_by']]) ?: '—') ?></p>
          <p class="mb-0"><strong>Status:</strong> <?= badge_for_status($dn['status']) ?></p>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
