<?php
require_once __DIR__ . '/helpers.php';
require_login();

/* --------------------------------------------------- delete movement */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    require_can('delete_movement');
    $id = (int)post('id');
    $mv = fetch_one('SELECT * FROM movements WHERE id = ?', [$id]);
    if ($mv) {
        guard_company((int)$mv['company_id']);
        db()->beginTransaction();
        try {
            reverse_movement_effect($mv);
            q('DELETE FROM movements WHERE id = ?', [$id]);
            audit('delete', 'movements', $id, $mv['movement_type'] . ' ' . fmt_qty($mv['quantity']) . ' item#' . $mv['item_id']);
            db()->commit();
            flash('Movement #' . $id . ' was reversed and deleted.', 'success');
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Could not delete movement: ' . e($ex->getMessage()), 'danger');
        }
    }
    redirect('movements.php?' . http_build_query($_GET));
}

$scope = scope_company_id();
$f_co    = $scope ?: (int)get('company');
$f_proj  = get('project');
$f_type  = get('type');
$f_item  = (int)get('item');
$f_from  = get('from');
$f_to    = get('to');
$f_term  = get('term');
$f_ref   = get('ref');

$where = ['1=1'];
$args  = [];
if ($f_co)   { $where[] = 'm.company_id = ?'; $args[] = $f_co; }
if ($f_type !== '') { $where[] = 'm.movement_type = ?'; $args[] = $f_type; }
if ($f_item) { $where[] = 'm.item_id = ?'; $args[] = $f_item; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'm.movement_date >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'm.movement_date <= ?'; $args[] = $f_to; }
if ($f_term !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ? OR m.reference LIKE ? OR m.notes LIKE ?)';
    array_push($args, '%' . $f_term . '%', '%' . $f_term . '%', '%' . $f_term . '%', '%' . $f_term . '%');
}
if ($f_ref !== '') { $where[] = '(m.transfer_ref = ? OR m.reference = ?)'; $args[] = $f_ref; $args[] = $f_ref; }
if ($f_proj !== '' && $f_proj !== null) {
    $where[] = '(m.project_id = ? OR m.from_project_id = ? OR m.to_project_id = ?)';
    array_push($args, (int)$f_proj, (int)$f_proj, (int)$f_proj);
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$base = "FROM movements m
         JOIN items i ON i.id = m.item_id
         JOIN companies co ON co.id = m.company_id
         LEFT JOIN projects p ON p.id = m.project_id
         LEFT JOIN suppliers s ON s.id = m.supplier_id
         LEFT JOIN users u ON u.id = m.created_by
         LEFT JOIN delivery_notes d ON d.id = m.dn_id
         $wsql";

$select = "SELECT m.*, i.item_code, i.item_name, co.code AS company_code, p.code AS project_code,
                  s.name AS supplier_name, u.username, d.dn_no, (m.quantity * m.unit_cost) AS line_value ";

/* ------------------------------------------------------------ export */
$mode = get('export');
if ($mode) {
    require_can('export_data');
    $rows = fetch_all($select . $base . ' ORDER BY m.movement_date DESC, m.id DESC');
    foreach ($rows as &$r) {
        $r['location'] = $r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
            : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id']));
        $r['signed']   = (in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true) ? '+' : '-') . fmt_qty($r['quantity']);
        $r['quantity'] = fmt_qty($r['quantity']);
        $r['unit_cost']= fmt_money($r['unit_cost']);
        $r['line_value'] = fmt_money($r['line_value']);
    }
    unset($r);
    $headers = ['Company', 'Date', 'DN No.', 'Item code', 'Item name', 'Type', 'Qty', 'Signed qty', 'UOM',
                'Unit cost', 'Line value', 'Location', 'From', 'To', 'Supplier', 'Reference', 'Transfer ref', 'Notes', 'User'];
    $keys = ['company_code', 'movement_date', 'dn_no', 'item_code', 'item_name', 'movement_type', 'quantity',
             'signed', 'uom', 'unit_cost', 'line_value', 'location', 'from_name', 'to_name', 'supplier_name',
             'reference', 'transfer_ref', 'notes', 'username'];
    foreach ($rows as &$r) {
        $r['from_name'] = (int)$r['from_project_id'] ? project_name((int)$r['from_project_id']) : '';
        $r['to_name']   = (int)$r['to_project_id'] ? project_name((int)$r['to_project_id']) : '';
    }
    unset($r);
    $meta = ['Report' => 'Stock movement register',
             'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Period' => ($f_from ?: 'start') . ' to ' . ($f_to ?: 'today'),
             'Type' => $f_type ?: 'All types',
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($rows)];
    if ($mode === 'csv') { export_csv('movement_register', $headers, $rows, $keys); }
    export_xls('movement_register', 'Stock Movement Register', $headers, $rows, $keys, $meta);
}

$pg = paginate((int)fetch_val('SELECT COUNT(*)' . $base, $args), 50, (int)get_num('page', 1));
$rows = fetch_all($select . $base . " ORDER BY m.movement_date DESC, m.id DESC LIMIT {$pg['offset']}, 50", $args);

$totIn  = (float)fetch_val("SELECT COALESCE(SUM(m.quantity),0)" . $base . " AND m.movement_type IN ('IN','TRANSFER_IN')", $args);
$totOut = (float)fetch_val("SELECT COALESCE(SUM(m.quantity),0)" . $base . " AND m.movement_type IN ('OUT','TRANSFER_OUT')", $args);
$totVal = (float)fetch_val("SELECT COALESCE(SUM(m.quantity*m.unit_cost),0)" . $base, $args);

$qbase = ['company' => $f_co, 'project' => $f_proj, 'type' => $f_type, 'item' => $f_item,
          'from' => $f_from, 'to' => $f_to, 'term' => $f_term, 'ref' => $f_ref];

$page_title = 'Stock Movements';
$active_nav = 'movements.php';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Stock Movements</h1>
    <p class="text-muted mb-0"><?= number_format($pg['total']) ?> record(s) &middot;
      in <?= e(fmt_qty($totIn)) ?> &middot; out <?= e(fmt_qty($totOut)) ?> &middot;
      value <?= e(APP_CURRENCY) ?> <?= e(fmt_money($totVal)) ?></p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (can('export_data')): ?>
      <?= export_buttons(['<i class="bi bi-filetype-csv"></i> CSV' => '?' . e(http_build_query($qbase + ['export' => 'csv'])),
                          '<i class="bi bi-file-earmark-excel"></i> Excel' => '?' . e(http_build_query($qbase + ['export' => 'xls'])),
                          '<i class="bi bi-printer"></i> Print' => 'javascript:window.print()']) ?>
    <?php endif; ?>
    <?php if (can('record_movement')): ?>
      <a class="btn btn-brand" href="<?= e(url('movement_form.php')) ?>"><i class="bi bi-plus-lg"></i> Record movement</a>
    <?php endif; ?>
  </div>
</div>

<form class="filter-bar row g-2 align-items-end no-print" method="get">
  <div class="col-md-2">
    <label class="form-label">Company</label>
    <?php if ($scope): ?>
      <input class="form-control form-control-sm" value="<?= e(company_name($scope)) ?>" disabled>
    <?php else: ?>
      <select name="company" class="form-select form-select-sm">
        <option value="0">All</option>
        <?php foreach (companies_for_user() as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f_co === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>
  <div class="col-md-2">
    <label class="form-label">Location</label>
    <select name="project" class="form-select form-select-sm">
      <option value="">All locations</option>
      <option value="0" <?= $f_proj === '0' ? 'selected' : '' ?>>Main Store</option>
      <?php foreach (projects_for_select($f_co ?: $scope) as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (string)$f_proj === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Type</label>
    <select name="type" class="form-select form-select-sm">
      <option value="">All types</option>
      <?php foreach (['IN' => 'Receipt (IN)', 'OUT' => 'Issue (OUT)', 'TRANSFER_OUT' => 'Transfer out',
                      'TRANSFER_IN' => 'Transfer in', 'ADJUST' => 'Adjustment'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $f_type === $v ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">From date</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">To date</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Search / reference</label>
    <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>" placeholder="Item, ref or remark">
  </div>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-brand btn-sm"><i class="bi bi-funnel"></i> Apply filters</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('movements.php')) ?>">Reset</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('reports/movements.php?' . http_build_query($qbase))) ?>" target="_blank">
      <i class="bi bi-file-earmark-bar-graph"></i> Printable movement report</a>
  </div>
</form>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Date</th><th>Company</th><th>Item</th><th>Type</th><th class="text-end">Qty</th>
          <th>Location</th><th>Transfer</th><th>Reference</th><th>Supplier</th>
          <th class="text-end">Value</th><th>User</th>
          <?php if (can('delete_movement')): ?><th class="text-end no-print"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= e(fmt_date($r['movement_date'])) ?></td>
          <td><span class="badge bg-light text-dark"><?= e($r['company_code']) ?></span></td>
          <td>
            <span class="mono small"><?= e($r['item_code']) ?></span>
            <div class="small text-muted"><?= e($r['item_name']) ?></div>
          </td>
          <td><?= badge_for_movement($r['movement_type']) ?></td>
          <td class="text-end fw-semibold <?= in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true) ? 'text-success' : 'text-danger' ?>">
            <?= in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true) ? '+' : '−' ?><?= e(fmt_qty($r['quantity'])) ?>
            <small class="text-muted"><?= e($r['uom']) ?></small>
          </td>
          <td><small><?= e($r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
                : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id']))) ?></small></td>
          <td><?php if ($r['transfer_ref']): ?><span class="badge bg-info text-dark mono"><?= e($r['transfer_ref']) ?></span><?php endif; ?></td>
          <td>
            <small><?= e($r['reference']) ?></small>
            <?php if ($r['dn_no']): ?><div><a class="small" href="<?= e(url('delivery_note_print.php?id=' . (int)$r['dn_id'])) ?>" target="_blank"><?= e($r['dn_no']) ?></a></div><?php endif; ?>
            <?php if ($r['notes']): ?><div class="small text-muted"><?= e($r['notes']) ?></div><?php endif; ?>
          </td>
          <td><small class="text-muted"><?= e($r['supplier_name']) ?></small></td>
          <td class="text-end"><?= e(fmt_money($r['line_value'])) ?></td>
          <td><small class="text-muted"><?= e($r['username']) ?></small></td>
          <?php if (can('delete_movement')): ?>
          <td class="text-end no-print text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('movement_form.php?id=' . (int)$r['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Reverse and delete movement #<?= (int)$r['id'] ?>? Stock balances will be restored.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="12" class="text-center text-muted py-5">No movements match the selected filters.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-between align-items-center no-print">
    <small class="text-muted">Showing <?= count($rows) ?> of <?= number_format($pg['total']) ?> records</small>
    <?= pager_links($pg, $qbase) ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
