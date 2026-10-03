<?php
/** Transfer register — one row per transfer reference. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope  = scope_company_id();
$f_co   = $scope ?: (int)get('company');
$f_from = get('from', date('Y-m-01'));
$f_to   = get('to', date('Y-m-d'));
$f_term = get('term');
$f_proj = (int)get('project');

$where = ["m.movement_type IN ('TRANSFER_OUT','TRANSFER_IN')", 'm.transfer_ref IS NOT NULL'];
$args  = [];
if ($f_co) { $where[] = 'm.company_id = ?'; $args[] = $f_co; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'm.movement_date >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'm.movement_date <= ?'; $args[] = $f_to; }
if ($f_proj) { $where[] = '(m.from_project_id = ? OR m.to_project_id = ?)'; $args[] = $f_proj; $args[] = $f_proj; }
if ($f_term !== '') {
    $where[] = '(m.transfer_ref LIKE ? OR i.item_code LIKE ? OR i.item_name LIKE ? OR m.reference LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$rows = fetch_all(
    "SELECT m.transfer_ref, MIN(m.movement_date) AS tdate, MAX(m.company_id) AS company_id,
            MAX(i.item_code) AS item_code, MAX(i.item_name) AS item_name, MAX(i.uom) AS uom,
            MAX(m.from_project_id) AS from_p, MAX(m.to_project_id) AS to_p,
            SUM(CASE WHEN m.movement_type='TRANSFER_OUT' THEN m.quantity ELSE 0 END) AS qty,
            SUM(CASE WHEN m.movement_type='TRANSFER_OUT' THEN m.quantity*m.unit_cost ELSE 0 END) AS value,
            MAX(d.dn_no) AS dn_no, MAX(d.id) AS dn_id, MAX(u.username) AS username, MAX(m.notes) AS notes,
            MAX(co.code) AS company_code
     FROM movements m
     LEFT JOIN items i ON i.id = m.item_id
     LEFT JOIN delivery_notes d ON d.id = m.dn_id
     LEFT JOIN users u ON u.id = m.created_by
     JOIN companies co ON co.id = m.company_id
     $wsql
     GROUP BY m.transfer_ref ORDER BY tdate, m.transfer_ref", $args);

$totQty = 0.0; $totVal = 0.0;
foreach ($rows as $r) { $totQty += (float)$r['qty']; $totVal += (float)$r['value']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['transfer_ref' => $r['transfer_ref'], 'tdate' => $r['tdate'], 'company_code' => $r['company_code'],
                  'item_code' => $r['item_code'], 'item_name' => $r['item_name'],
                  'qty' => fmt_qty($r['qty']), 'uom' => $r['uom'],
                  'from_name' => (int)$r['from_p'] ? project_name((int)$r['from_p']) : 'Main Store',
                  'to_name' => (int)$r['to_p'] ? project_name((int)$r['to_p']) : 'Main Store',
                  'value' => fmt_money($r['value']), 'dn_no' => $r['dn_no'], 'user' => $r['username'], 'notes' => $r['notes']];
    }
    $headers = ['Transfer ref', 'Date', 'Company', 'Item code', 'Item name', 'Qty', 'UOM', 'From', 'To',
                'Value', 'Source DN', 'User', 'Notes'];
    $meta = ['Report' => 'Transfer register', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Period' => $f_from . ' to ' . $f_to, 'Transfers' => count($out),
             'Total quantity' => fmt_qty($totQty), 'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username']];
    if (get('export') === 'csv') { export_csv('transfer_register', $headers, $out); }
    export_xls('transfer_register', 'Stock Transfer Register', $headers, $out, null, $meta);
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Transfer register';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-2"><label class="form-label">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>"></div>
    <div class="col-md-3"><label class="form-label">Location involved</label>
      <select name="project" class="form-select form-select-sm">
        <option value="0">All locations</option>
        <?php foreach (projects_for_select($f_co) as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $f_proj === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Search</label>
      <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>"></div>
    <div class="col-md-2"><button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button></div>
  </form>

  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Stock Transfer Register', [
      'Company' => $f_co ? company_name($f_co) : 'All companies',
      'Period'  => fmt_date($f_from) . ' — ' . fmt_date($f_to),
      'Transfers' => count($rows), 'Total quantity' => fmt_qty($totQty),
      'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal),
      'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
  ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr><th>Transfer ref</th><th style="width:80px">Date</th><th>Item</th><th class="text-end">Qty</th><th>UOM</th>
          <th>From</th><th>To</th><th class="text-end">Value</th><th>Source doc</th><th>Recorded by</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= e($r['transfer_ref']) ?></td>
        <td class="text-nowrap"><?= e(date('d-m-Y', strtotime($r['tdate']))) ?></td>
        <td><span class="mono"><?= e($r['item_code']) ?></span> — <?= e($r['item_name']) ?></td>
        <td class="text-end"><?= e(fmt_qty($r['qty'])) ?></td>
        <td><?= e($r['uom']) ?></td>
        <td><small><?= e((int)$r['from_p'] ? project_name((int)$r['from_p']) : 'Main Store') ?></small></td>
        <td><small><?= e((int)$r['to_p'] ? project_name((int)$r['to_p']) : 'Main Store') ?></small></td>
        <td class="text-end"><?= e(fmt_money($r['value'])) ?></td>
        <td><small><?= e($r['dn_no'] ?: '—') ?></small></td>
        <td><small><?= e($r['username']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No transfers for this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold table-light"><td colspan="3" class="text-end">Totals</td>
        <td class="text-end"><?= e(fmt_qty($totQty)) ?></td><td colspan="3"></td>
        <td class="text-end"><?= e(fmt_money($totVal)) ?></td><td colspan="2"></td></tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Keeper</div><div class="sig-box">Store Manager</div><div class="sig-box">Project Manager</div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
