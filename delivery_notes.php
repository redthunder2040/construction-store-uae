<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/includes/dn_engine.php';
require_login();

/* ------------------------------------------------------------------ delete */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    require_can('delete_delivery_note');
    $id = (int)post('id');
    $dn = fetch_one('SELECT * FROM delivery_notes WHERE id = ?', [$id]);
    if ($dn) {
        guard_company((int)$dn['company_id']);
        db()->beginTransaction();
        try {
            $legs = clear_dn_movements($id);
            q('DELETE FROM delivery_notes WHERE id = ?', [$id]);
            audit('delete', 'delivery_notes', $id, $dn['dn_no'] . ' deleted, ' . $legs . ' movement legs reversed');
            db()->commit();
            flash('Delivery note <strong>' . e($dn['dn_no']) . '</strong> was deleted'
                . ($legs ? ' and its ' . $legs . ' stock movements reversed.' : '.'), 'success');
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Could not delete the delivery note: ' . e($ex->getMessage()), 'danger');
        }
    }
    redirect('delivery_notes.php');
}

/* --------------------------------------------------------- status workflow */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'status') {
    csrf_check();
    require_can('approve_delivery_note');
    $id = (int)post('id');
    $new = post('status');
    $dn = fetch_one('SELECT * FROM delivery_notes WHERE id = ?', [$id]);
    if ($dn && in_array($new, ['draft', 'issued', 'received', 'cancelled'], true)) {
        guard_company((int)$dn['company_id']);
        db()->beginTransaction();
        try {
            $lines = fetch_all('SELECT * FROM delivery_note_items WHERE dn_id = ?', [$id]);
            if (!$lines && in_array($new, ['issued', 'received'], true)) {
                throw new RuntimeException('Cannot post a delivery note with no lines.');
            }
            clear_dn_movements($id);
            q('UPDATE delivery_notes SET status = ? WHERE id = ?', [$new, $id]);
            $dn['status'] = $new;
            $posted = post_dn_movements($dn, $lines);
            audit('update', 'delivery_notes', $id, 'Status → ' . $new . ' (' . $posted . ' movement legs)');
            db()->commit();
            flash('Delivery note <strong>' . e($dn['dn_no']) . '</strong> is now ' . e(ucfirst($new)) . '.', 'success');
        } catch (Throwable $ex) {
            db()->rollBack();
            flash('Status change failed: ' . e($ex->getMessage()), 'danger');
        }
    }
    redirect('delivery_notes.php');
}

/* ----------------------------------------------------------------- listing */
$scope   = scope_company_id();
$f_co    = $scope ?: (int)get('company');
$f_proj  = (int)get('project');
$f_stat  = get('status');
$f_from  = get('from');
$f_to    = get('to');
$f_term  = get('term');

$where = ['1=1'];
$args  = [];
if ($f_co)   { $where[] = 'd.company_id = ?'; $args[] = $f_co; }
if ($f_proj) { $where[] = 'd.project_id = ?'; $args[] = $f_proj; }
if ($f_stat !== '') { $where[] = 'd.status = ?'; $args[] = $f_stat; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'd.dn_date >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'd.dn_date <= ?'; $args[] = $f_to; }
if ($f_term !== '') {
    $where[] = '(d.dn_no LIKE ? OR d.issued_to LIKE ? OR d.vehicle_no LIKE ? OR i.item_code LIKE ? OR i.item_name LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$base = "FROM delivery_notes d
         LEFT JOIN projects p ON p.id = d.project_id
         LEFT JOIN projects fp ON fp.id = d.from_project_id
         JOIN companies co ON co.id = d.company_id
         LEFT JOIN suppliers s ON s.id = d.supplier_id
         LEFT JOIN delivery_note_items di ON di.dn_id = d.id
         LEFT JOIN items i ON i.id = di.item_id
         $wsql";

$select = "SELECT d.*, p.code AS project_code, p.name AS project_name,
                  fp.code AS from_code, co.code AS company_code, s.name AS supplier_name,
                  COUNT(DISTINCT di.id) AS line_count,
                  COALESCE(SUM(di.quantity),0) AS total_qty,
                  COALESCE(SUM(di.quantity * i.unit_cost),0) AS total_value ";

/* ----------------------------------------------------------------- export */
if (get('export')) {
    require_can('export_data');
    $rows = fetch_all($select . $base . ' GROUP BY d.id ORDER BY d.dn_date DESC, d.id DESC');
    $headers = ['DN No.', 'Date', 'Company', 'From store', 'Deliver to', 'Client', 'Supplier', 'Issued to',
                'Vehicle', 'Driver', 'Lines', 'Total qty', 'Value', 'Status', 'Prepared by'];
    $keys = ['dn_no', 'dn_date', 'company_code', 'from_label', 'dest_label', 'client_name', 'supplier_name',
             'issued_to', 'vehicle_no', 'driver_name', 'line_count', 'total_qty_f', 'total_value_f', 'status', 'preparer'];
    foreach ($rows as &$r) {
        $r['from_label']    = (int)$r['from_project_id'] ? project_name((int)$r['from_project_id']) : 'Main Store';
        $r['dest_label']    = project_name((int)$r['project_id']);
        $r['client_name']   = (string)fetch_val('SELECT client_name FROM projects WHERE id = ?', [(int)$r['project_id']]);
        $r['total_qty_f']   = fmt_qty($r['total_qty']);
        $r['total_value_f'] = fmt_money($r['total_value']);
        $r['preparer']      = (string)fetch_val('SELECT full_name FROM users WHERE id = ?', [(int)$r['prepared_by']]);
    }
    unset($r);
    $meta = ['Report' => 'Delivery note register', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Period' => ($f_from ?: 'start') . ' to ' . ($f_to ?: 'today'), 'Status' => $f_stat ?: 'all',
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($rows)];
    if (get('export') === 'csv') { export_csv('delivery_notes', $headers, $rows, $keys); }
    export_xls('delivery_notes', 'Delivery Note Register', $headers, $rows, $keys, $meta);
}

$pg = paginate((int)fetch_val('SELECT COUNT(DISTINCT d.id)' . $base, $args), 25, (int)get_num('page', 1));
$rows = fetch_all($select . $base . " GROUP BY d.id ORDER BY d.dn_date DESC, d.id DESC LIMIT {$pg['offset']}, 25", $args);

$totQty   = (float)fetch_val("SELECT COALESCE(SUM(x.qty),0) FROM (SELECT SUM(di.quantity) AS qty " . $base . ' GROUP BY d.id) x', $args);
$totValue = (float)fetch_val("SELECT COALESCE(SUM(x.val),0) FROM (SELECT SUM(di.quantity*i.unit_cost) AS val " . $base . ' GROUP BY d.id) x', $args);

$qbase = ['company' => $f_co, 'project' => $f_proj, 'status' => $f_stat, 'from' => $f_from, 'to' => $f_to, 'term' => $f_term];
$page_title = 'Delivery Notes';
$active_nav = 'delivery_notes.php';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Delivery Notes</h1>
    <p class="text-muted mb-0"><?= number_format($pg['total']) ?> document(s) &middot;
      <?= e(fmt_qty($totQty)) ?> units delivered &middot; value <?= e(APP_CURRENCY) ?> <?= e(fmt_money($totValue)) ?></p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (can('export_data')): ?>
      <?= export_buttons(['<i class="bi bi-filetype-csv"></i> CSV' => '?' . e(http_build_query($qbase + ['export' => 'csv'])),
                          '<i class="bi bi-file-earmark-excel"></i> Excel' => '?' . e(http_build_query($qbase + ['export' => 'xls']))]) ?>
    <?php endif; ?>
    <a class="btn btn-outline-primary" href="<?= e(url('reports/dn_register.php?' . http_build_query($qbase))) ?>" target="_blank"><i class="bi bi-printer"></i> Printable register</a>
    <?php if (can('create_delivery_note')): ?>
      <a class="btn btn-brand" href="<?= e(url('delivery_note_form.php')) ?>"><i class="bi bi-plus-lg"></i> New delivery note</a>
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
    <label class="form-label">Destination project</label>
    <select name="project" class="form-select form-select-sm">
      <option value="0">All projects</option>
      <?php foreach (projects_for_select($f_co ?: $scope) as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $f_proj === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">All statuses</option>
      <?php foreach (['draft' => 'Draft', 'issued' => 'Issued', 'received' => 'Received', 'cancelled' => 'Cancelled'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $f_stat === $v ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">From</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">To</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Search</label>
    <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>" placeholder="DN no, vehicle, item">
  </div>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-brand btn-sm"><i class="bi bi-funnel"></i> Apply filters</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('delivery_notes.php')) ?>">Reset</a>
  </div>
</form>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr><th>DN No.</th><th>Date</th><th>Company</th><th>From → To</th><th>Issued to</th>
            <th class="text-end">Lines</th><th class="text-end">Qty</th><th class="text-end">Value</th>
            <th>Vehicle</th><th>Status</th><th class="text-end no-print">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono fw-semibold"><?= e($r['dn_no']) ?></td>
          <td class="text-nowrap"><?= e(fmt_date($r['dn_date'])) ?></td>
          <td><span class="badge bg-light text-dark"><?= e($r['company_code']) ?></span></td>
          <td>
            <div class="small"><i class="bi bi-box-arrow-up-right text-muted"></i>
              <?= e((int)$r['from_project_id'] ? project_name((int)$r['from_project_id']) : 'Main Store') ?></div>
            <div class="small"><i class="bi bi-geo-alt text-muted"></i>
              <?= e($r['project_code'] . ' — ' . $r['project_name']) ?></div>
          </td>
          <td><small><?= e($r['issued_to']) ?></small><?php if ($r['driver_name']): ?><div class="small text-muted">Driver: <?= e($r['driver_name']) ?></div><?php endif; ?></td>
          <td class="text-end"><?= (int)$r['line_count'] ?></td>
          <td class="text-end"><?= e(fmt_qty($r['total_qty'])) ?></td>
          <td class="text-end"><?= e(fmt_money($r['total_value'])) ?></td>
          <td><small class="mono"><?= e($r['vehicle_no']) ?></small></td>
          <td><?= badge_for_status($r['status']) ?></td>
          <td class="text-end no-print text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('delivery_note_print.php?id=' . (int)$r['id'])) ?>" target="_blank" title="Print / PDF"><i class="bi bi-printer"></i></a>
            <?php if (can('create_delivery_note')): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('delivery_note_form.php?id=' . (int)$r['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (can('approve_delivery_note') && in_array($r['status'], ['draft'], true)): ?>
              <form method="post" class="d-inline" data-confirm="Issue <?= e($r['dn_no']) ?>? Stock will be moved to the site store.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="issued">
                <button class="btn btn-sm btn-success" title="Issue / post stock"><i class="bi bi-check2-circle"></i></button>
              </form>
            <?php endif; ?>
            <?php if (can('approve_delivery_note') && in_array($r['status'], ['issued'], true)): ?>
              <form method="post" class="d-inline" data-confirm="Mark <?= e($r['dn_no']) ?> as received by the site?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="status" value="received">
                <button class="btn btn-sm btn-outline-success" title="Confirm receipt"><i class="bi bi-truck"></i></button>
              </form>
            <?php endif; ?>
            <?php if (can('delete_delivery_note')): ?>
              <form method="post" class="d-inline" data-confirm="Delete <?= e($r['dn_no']) ?>? Posted stock movements will be reversed.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="text-center text-muted py-5">No delivery notes match these filters.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-between no-print">
    <small class="text-muted">Showing <?= count($rows) ?> of <?= number_format($pg['total']) ?> documents</small>
    <?= pager_links($pg, $qbase) ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
