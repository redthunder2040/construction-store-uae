<?php
/** Delivery-note register. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope  = scope_company_id();
$f_co   = $scope ?: (int)get('company');
$f_proj = (int)get('project');
$f_stat = get('status');
$f_from = get('from', date('Y-m-01'));
$f_to   = get('to', date('Y-m-d'));
$f_term = get('term');

$where = ['1=1'];
$args  = [];
if ($f_co)   { $where[] = 'd.company_id = ?'; $args[] = $f_co; }
if ($f_proj) { $where[] = 'd.project_id = ?'; $args[] = $f_proj; }
if ($f_stat !== '') { $where[] = 'd.status = ?'; $args[] = $f_stat; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'd.dn_date >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'd.dn_date <= ?'; $args[] = $f_to; }
if ($f_term !== '') {
    $where[] = '(d.dn_no LIKE ? OR d.issued_to LIKE ? OR d.vehicle_no LIKE ? OR d.driver_name LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$base = "FROM delivery_notes d
         LEFT JOIN projects p ON p.id = d.project_id
         LEFT JOIN projects fp ON fp.id = d.from_project_id
         JOIN companies co ON co.id = d.company_id
         LEFT JOIN delivery_note_items di ON di.dn_id = d.id
         LEFT JOIN items i ON i.id = di.item_id
         LEFT JOIN users u ON u.id = d.prepared_by
         $wsql";

$rows = fetch_all(
    "SELECT d.*, p.code AS project_code, p.name AS project_name, p.client_name,
            fp.code AS from_code, co.code AS company_code, u.full_name AS preparer,
            COUNT(DISTINCT di.id) AS line_count,
            COALESCE(SUM(di.quantity),0) AS total_qty,
            COALESCE(SUM(di.quantity * i.unit_cost),0) AS total_value " . $base .
    ' GROUP BY d.id ORDER BY d.dn_date, d.id', $args);

$totQty = 0.0; $totVal = 0.0;
foreach ($rows as $r) { $totQty += (float)$r['total_qty']; $totVal += (float)$r['total_value']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['dn_no' => $r['dn_no'], 'dn_date' => $r['dn_date'], 'company_code' => $r['company_code'],
                  'from_label' => (int)$r['from_project_id'] ? project_name((int)$r['from_project_id']) : 'Main Store',
                  'dest_label' => $r['project_code'] . ' — ' . $r['project_name'],
                  'client' => $r['client_name'], 'issued_to' => $r['issued_to'],
                  'line_count' => $r['line_count'], 'total_qty' => fmt_qty($r['total_qty']),
                  'total_value' => fmt_money($r['total_value']), 'vehicle_no' => $r['vehicle_no'],
                  'driver' => $r['driver_name'], 'status' => ucfirst($r['status']), 'preparer' => $r['preparer']];
    }
    $headers = ['DN No.', 'Date', 'Company', 'From store', 'Deliver to', 'Client', 'Issued to', 'Lines',
                'Total qty', 'Value', 'Vehicle', 'Driver', 'Status', 'Prepared by'];
    $meta = ['Report' => 'Delivery note register', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Period' => fmt_date($f_from) . ' — ' . fmt_date($f_to), 'Status' => $f_stat ?: 'All',
             'Documents' => count($out), 'Total quantity' => fmt_qty($totQty),
             'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username']];
    if (get('export') === 'csv') { export_csv('delivery_note_register', $headers, $out); }
    export_xls('delivery_note_register', 'Delivery Note Register', $headers, $out, null, $meta);
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Delivery note register';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-2"><label class="form-label">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>"></div>
    <div class="col-md-3"><label class="form-label">Project</label>
      <select name="project" class="form-select form-select-sm">
        <option value="0">All projects</option>
        <?php foreach (projects_for_select($f_co) as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $f_proj === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach (['draft', 'issued', 'received', 'cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $f_stat === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button></div>
  </form>

  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Delivery Note Register', [
      'Company' => $f_co ? company_name($f_co) : 'All companies',
      'Period'  => fmt_date($f_from) . ' — ' . fmt_date($f_to),
      'Status filter' => $f_stat ?: 'All',
      'Documents' => count($rows), 'Total quantity' => fmt_qty($totQty),
      'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal),
      'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
  ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr><th>DN No.</th><th style="width:78px">Date</th><th>From → To</th><th>Client</th><th>Issued to</th>
          <th class="text-end" style="width:54px">Lines</th><th class="text-end">Qty</th><th class="text-end">Value</th>
          <th>Vehicle</th><th>Status</th><th>Prepared by</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= e($r['dn_no']) ?></td>
        <td class="text-nowrap"><?= e(date('d-m-Y', strtotime($r['dn_date']))) ?></td>
        <td><small><?= e((int)$r['from_project_id'] ? project_name((int)$r['from_project_id']) : 'Main Store') ?>
            → <?= e($r['project_code'] . ' — ' . $r['project_name']) ?></small></td>
        <td><small><?= e($r['client_name']) ?></small></td>
        <td><small><?= e($r['issued_to']) ?></small></td>
        <td class="text-end"><?= (int)$r['line_count'] ?></td>
        <td class="text-end"><?= e(fmt_qty($r['total_qty'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['total_value'])) ?></td>
        <td><small><?= e($r['vehicle_no']) ?></small></td>
        <td><?= e(ucfirst($r['status'])) ?></td>
        <td><small><?= e($r['preparer']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4">No delivery notes for this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold table-light"><td colspan="6" class="text-end">Totals</td>
        <td class="text-end"><?= e(fmt_qty($totQty)) ?></td>
        <td class="text-end"><?= e(fmt_money($totVal)) ?></td><td colspan="3"></td></tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Keeper</div><div class="sig-box">Store Manager</div><div class="sig-box">Project Manager</div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
