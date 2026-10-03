<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_transfer');

$scope  = scope_company_id();
$errors = [];

/* ------------------------------------------------------------- new transfer */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create') {
    csrf_check();
    $companyId = $scope ?: (int)post_num('company_id');
    $itemId    = (int)post_num('item_id');
    $fromP     = (int)post_num('from_project_id');
    $toP       = (int)post_num('to_project_id');
    $qty       = (float)post_num('quantity');
    $date      = post('movement_date');
    $notes     = post('notes');

    $item = $itemId ? fetch_one('SELECT * FROM items WHERE id = ?', [$itemId]) : null;
    if (!$item)                       { $errors[] = 'Please select a valid item.'; }
    if ($qty <= 0)                    { $errors[] = 'Quantity must be greater than zero.'; }
    if ($fromP === $toP)              { $errors[] = 'Source and destination locations must be different.'; }
    if (!is_dob($date))               { $errors[] = 'Please provide a valid date.'; }
    if ($item && $companyId && (int)$item['company_id'] !== $companyId) { $errors[] = 'Item/company mismatch.'; }

    if (!$errors) {
        try {
            $ref = create_transfer($itemId, $fromP, $toP, $qty, $date, $notes !== '' ? $notes : null);
            flash('Transfer <span class="mono">' . e($ref) . '</span> posted: ' . fmt_qty($qty) . ' ' . e($item['uom'])
                . ' from <strong>' . e(project_name($fromP)) . '</strong> to <strong>' . e(project_name($toP)) . '</strong>.', 'success');
            redirect('transfers.php?ref=' . urlencode($ref));
        } catch (Throwable $ex) {
            $errors[] = 'Transfer failed: ' . e($ex->getMessage());
        }
    }
}

/* ----------------------------------------------------------- delete transfer */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    require_can('delete_movement');
    $ref = post('ref');
    $legs = fetch_all('SELECT * FROM movements WHERE transfer_ref = ? ORDER BY id DESC', [$ref]);
    if ($legs) {
        guard_company((int)$legs[0]['company_id']);
        db()->beginTransaction();
        try {
            foreach ($legs as $leg) { reverse_movement_effect($leg); }   // reverse in DESC order
            q('DELETE FROM movements WHERE transfer_ref = ?', [$ref]);
            audit('delete', 'transfers', null, 'Transfer ' . $ref . ' reversed (' . count($legs) . ' legs)');
            db()->commit();
            flash('Transfer <span class="mono">' . e($ref) . '</span> was reversed and removed.', 'success');
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Could not reverse the transfer: ' . e($ex->getMessage()), 'danger');
        }
    }
    redirect('transfers.php');
}

/* ---------------------------------------------------------------- listing */
$f_co   = $scope ?: (int)get('company');
$f_term = get('term');
$f_from = get('from');
$f_to   = get('to');

$where = ["m.movement_type IN ('TRANSFER_OUT','TRANSFER_IN')", 'm.transfer_ref IS NOT NULL'];
$args  = [];
if ($f_co) { $where[] = 'm.company_id = ?'; $args[] = $f_co; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'm.movement_date >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'm.movement_date <= ?'; $args[] = $f_to; }
if ($f_term !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ? OR m.transfer_ref LIKE ? OR m.reference LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$pg = paginate((int)fetch_val("SELECT COUNT(DISTINCT m.transfer_ref) FROM movements m LEFT JOIN items i ON i.id = m.item_id $wsql", $args),
    25, (int)get_num('page', 1));

$transfers = fetch_all(
    "SELECT m.transfer_ref,
            MIN(m.movement_date) AS tdate,
            MAX(m.company_id) AS company_id,
            MAX(i.item_code) AS item_code, MAX(i.item_name) AS item_name, MAX(i.uom) AS uom,
            MAX(m.from_project_id) AS from_p, MAX(m.to_project_id) AS to_p,
            SUM(CASE WHEN m.movement_type='TRANSFER_OUT' THEN m.quantity ELSE 0 END) AS qty,
            SUM(CASE WHEN m.movement_type='TRANSFER_OUT' THEN m.quantity*m.unit_cost ELSE 0 END) AS value,
            MAX(m.dn_id) AS dn_id, MAX(d.dn_no) AS dn_no, MAX(u.username) AS username
     FROM movements m
     LEFT JOIN items i ON i.id = m.item_id
     LEFT JOIN delivery_notes d ON d.id = m.dn_id
     LEFT JOIN users u ON u.id = m.created_by
     $wsql
     GROUP BY m.transfer_ref
     ORDER BY tdate DESC, m.transfer_ref DESC
     LIMIT {$pg['offset']}, 25", $args);

$totQty   = (float)fetch_val("SELECT COALESCE(SUM(m.quantity),0) FROM movements m LEFT JOIN items i ON i.id = m.item_id $wsql AND m.movement_type='TRANSFER_OUT'", $args);
$totValue = (float)fetch_val("SELECT COALESCE(SUM(m.quantity*m.unit_cost),0) FROM movements m LEFT JOIN items i ON i.id = m.item_id $wsql AND m.movement_type='TRANSFER_OUT'", $args);

$page_title = 'Stock Transfers';
$active_nav = 'transfers.php';
$qbase = ['company' => $f_co, 'term' => $f_term, 'from' => $f_from, 'to' => $f_to];
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Stock Transfers</h1>
    <p class="text-muted mb-0"><?= number_format($pg['total']) ?> transfer reference(s) &middot;
      moved <?= e(fmt_qty($totQty)) ?> &middot; value <?= e(APP_CURRENCY) ?> <?= e(fmt_money($totValue)) ?></p>
  </div>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-outline-primary" href="<?= e(url('reports/transfers.php?' . http_build_query($qbase))) ?>" target="_blank"><i class="bi bi-printer"></i> Printable register</a>
    <?php if (can('export_data')): ?>
      <a class="btn btn-outline-secondary" href="?<?= e(http_build_query($qbase + ['export' => 'xls'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<?php
/* export handler (after the header markup is prepared) */
if (get('export')) {
    require_can('export_data');
    $rows = fetch_all(
        "SELECT m.transfer_ref, m.movement_date, i.item_code, i.item_name, m.uom, m.quantity, m.unit_cost,
                m.from_project_id, m.to_project_id, m.reference, m.notes, co.code AS company_code, u.username
         FROM movements m LEFT JOIN items i ON i.id = m.item_id LEFT JOIN companies co ON co.id = m.company_id
         LEFT JOIN users u ON u.id = m.created_by
         $wsql ORDER BY m.movement_date DESC, m.id DESC", $args);
    foreach ($rows as &$r) {
        $r['from_name'] = (int)$r['from_project_id'] ? project_name((int)$r['from_project_id']) : 'Main Store';
        $r['to_name']   = (int)$r['to_project_id'] ? project_name((int)$r['to_project_id']) : 'Main Store';
        $r['value']     = fmt_money((float)$r['quantity'] * (float)$r['unit_cost']);
        $r['quantity']  = fmt_qty($r['quantity']);
        $r['unit_cost'] = fmt_money($r['unit_cost']);
    }
    unset($r);
    $headers = ['Ref', 'Date', 'Company', 'Item code', 'Item name', 'Qty', 'UOM', 'Unit cost', 'Value',
                'From', 'To', 'Reference', 'Notes', 'User'];
    $keys = ['transfer_ref', 'movement_date', 'company_code', 'item_code', 'item_name', 'quantity', 'uom',
             'unit_cost', 'value', 'from_name', 'to_name', 'reference', 'notes', 'username'];
    export_xls('stock_transfers', 'Stock Transfer Register', $headers, $rows, $keys,
        ['Period' => ($f_from ?: 'start') . ' to ' . ($f_to ?: 'today'),
         'Company' => $f_co ? company_name($f_co) : 'All companies',
         'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($rows)]);
}
?>

<div class="row g-3">
  <?php if (can('manage_transfer')): ?>
  <div class="col-xl-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> New transfer</h5></div>
      <div class="card-body">
        <form method="post" id="transfer-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">

          <div class="mb-3">
            <label class="form-label required">Company</label>
            <?php if ($scope): ?>
              <input class="form-control form-control-sm" value="<?= e(company_name($scope)) ?>" disabled>
              <input type="hidden" name="company_id" value="<?= (int)$scope ?>">
            <?php else: ?>
              <select name="company_id" class="form-select form-select-sm">
                <?php foreach (companies_for_user() as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' — ' . $c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>

          <div class="mb-3 item-lookup">
            <label class="form-label required">Item</label>
            <input type="text" id="item_search" class="form-control form-control-sm" autocomplete="off"
                   placeholder="Search by code or name…" data-endpoint="<?= e(url('item_lookup.php')) ?>">
            <input type="hidden" name="item_id" id="item_id">
            <div class="lookup-results" id="lookup_results"></div>
            <div id="item_info" class="small mt-2 d-none"></div>
          </div>

          <?php $lc = $scope ?: (int)fetch_val('SELECT id FROM companies ORDER BY id LIMIT 1'); ?>
          <div class="mb-3">
            <label class="form-label required">Transfer from</label>
            <select name="from_project_id" id="from_project_id" class="form-select form-select-sm" required>
              <?php foreach (locations_for_select($lc) as $l): ?>
                <option value="<?= (int)$l['id'] ?>"><?= e($l['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label required">Transfer to</label>
            <select name="to_project_id" class="form-select form-select-sm" required>
              <?php foreach (locations_for_select($lc) as $l): ?>
                <option value="<?= (int)$l['id'] ?>"><?= e($l['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label required">Quantity</label>
              <input type="number" step="0.001" min="0.001" name="quantity" id="quantity" class="form-control form-control-sm" required>
            </div>
            <div class="col-6">
              <label class="form-label required">Date</label>
              <input type="date" name="movement_date" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Reason for the transfer"></textarea>
          </div>

          <div id="availability" class="alert alert-light border small">Select an item to see available stock.</div>

          <button class="btn btn-brand w-100"><i class="bi bi-shuffle"></i> Post transfer</button>
          <p class="small text-muted mt-2 mb-0">Posts a paired TRANSFER_OUT + TRANSFER_IN so both stores stay balanced.</p>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= can('manage_transfer') ? 'col-xl-8' : 'col-12' ?>">
    <form class="filter-bar row g-2 align-items-end no-print" method="get">
      <div class="col-md-3">
        <label class="form-label">Search</label>
        <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>" placeholder="Ref, item code or name">
      </div>
      <div class="col-md-2">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-brand btn-sm flex-fill"><i class="bi bi-funnel"></i> Filter</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('transfers.php')) ?>">Reset</a>
      </div>
    </form>

    <div class="card app-card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr><th>Transfer ref</th><th>Date</th><th>Item</th><th class="text-end">Qty</th>
                <th>From</th><th>To</th><th>Source doc</th><th class="text-end">Value</th>
                <?php if (can('delete_movement')): ?><th class="text-end no-print"></th><?php endif; ?></tr>
          </thead>
          <tbody>
          <?php foreach ($transfers as $t): ?>
            <tr>
              <td class="mono small">
                <?= e($t['transfer_ref']) ?>
                <div class="small text-muted"><?= e($t['username']) ?></div>
              </td>
              <td class="text-nowrap"><?= e(fmt_date($t['tdate'])) ?></td>
              <td><span class="mono small"><?= e($t['item_code']) ?></span><div class="small text-muted"><?= e($t['item_name']) ?></div></td>
              <td class="text-end fw-semibold"><?= e(fmt_qty($t['qty'])) ?> <small class="text-muted"><?= e($t['uom']) ?></small></td>
              <td><small><?= e(project_name((int)$t['from_p'])) ?></small></td>
              <td><small><?= e(project_name((int)$t['to_p'])) ?></small></td>
              <td><?php if ($t['dn_no']): ?><a class="small mono" href="<?= e(url('delivery_note_print.php?id=' . (int)$t['dn_id'])) ?>" target="_blank"><?= e($t['dn_no']) ?></a><?php else: ?><small class="text-muted">—</small><?php endif; ?></td>
              <td class="text-end"><?= e(fmt_money($t['value'])) ?></td>
              <?php if (can('delete_movement')): ?>
              <td class="text-end no-print">
                <form method="post" data-confirm="Reverse both legs of transfer <?= e($t['transfer_ref']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="ref" value="<?= e($t['transfer_ref']) ?>">
                  <button class="btn btn-sm btn-outline-danger" <?= $t['dn_no'] ? 'disabled title="Created from a delivery note — cancel that document instead"' : '' ?>><i class="bi bi-arrow-counterclockwise"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$transfers): ?>
            <tr><td colspan="9" class="text-center text-muted py-5">No transfers found for these filters.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body d-flex justify-content-between no-print">
        <small class="text-muted">Showing <?= count($transfers) ?> of <?= number_format($pg['total']) ?> transfers</small>
        <?= pager_links($pg, $qbase) ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
