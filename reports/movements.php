<?php
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope  = scope_company_id();
$f_co   = $scope ?: (int)get('company');
$f_proj = get('project');
$f_type = get('type');
$f_item = (int)get('item');
$f_from = get('from', date('Y-m-01'));
$f_to   = get('to', date('Y-m-d'));
$f_term = get('term');
$f_ref  = get('ref');

$where = ['1=1'];
$args  = [];
if ($f_co) { $where[] = 'm.company_id = ?'; $args[] = $f_co; }
if ($f_type !== '') { $where[] = 'm.movement_type = ?'; $args[] = $f_type; }
if ($f_item) { $where[] = 'm.item_id = ?'; $args[] = $f_item; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'm.movement_date >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'm.movement_date <= ?'; $args[] = $f_to; }
if ($f_term !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ? OR m.reference LIKE ? OR m.notes LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%", "%$f_term%");
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
         LEFT JOIN categories c ON c.id = i.category_id
         LEFT JOIN projects p ON p.id = m.project_id
         LEFT JOIN suppliers s ON s.id = m.supplier_id
         LEFT JOIN users u ON u.id = m.created_by
         LEFT JOIN delivery_notes d ON d.id = m.dn_id
         $wsql";

$rows = fetch_all("SELECT m.*, i.item_code, i.item_name, c.name AS category, co.code AS company_code,
                          co.name AS company_name, s.name AS supplier_name, u.username, d.dn_no,
                          (m.quantity * m.unit_cost) AS line_value " . $base .
                   ' ORDER BY m.movement_date, m.id', $args);

$totIn = 0.0; $totOut = 0.0; $totVal = 0.0;
foreach ($rows as $r) {
    if (in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true)) { $totIn += (float)$r['quantity']; }
    else { $totOut += (float)$r['quantity']; }
    $totVal += (float)$r['line_value'];
}

/* --------------------------------------------------------------- exports */
if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'movement_date' => $r['movement_date'],
                  'dn_no' => $r['dn_no'], 'item_code' => $r['item_code'], 'item_name' => $r['item_name'],
                  'category' => $r['category'], 'movement_type' => $r['movement_type'],
                  'quantity' => fmt_qty($r['quantity']), 'uom' => $r['uom'],
                  'unit_cost' => fmt_money($r['unit_cost']), 'line_value' => fmt_money($r['line_value']),
                  'location' => $r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
                        : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id'])),
                  'reference' => $r['reference'], 'transfer_ref' => $r['transfer_ref'],
                  'supplier_name' => $r['supplier_name'], 'notes' => $r['notes'], 'username' => $r['username']];
    }
    $headers = ['Company', 'Date', 'DN No.', 'Item code', 'Item name', 'Category', 'Type', 'Qty', 'UOM',
                'Unit cost', 'Value', 'Location', 'Reference', 'Transfer ref', 'Supplier', 'Notes', 'User'];
    $meta = ['Report' => 'Stock movement register', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Period' => $f_from . ' to ' . $f_to, 'Type' => $f_type ?: 'All',
             'Total in' => fmt_qty($totIn), 'Total out' => fmt_qty($totOut),
             'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)];
    if (get('export') === 'csv') { export_csv('movement_register', $headers, $out); }
    export_xls('movement_register', 'Stock Movement Register', $headers, $out, null, $meta);
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Movement register';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Stock Movement Register', [
      'Period'        => fmt_date($f_from) . ' — ' . fmt_date($f_to),
      'Company'       => $f_co ? company_name($f_co) : 'All companies',
      'Location'      => ($f_proj === '' || $f_proj === null) ? 'All locations' : project_name((int)$f_proj),
      'Movement type' => $f_type ?: 'All types',
      'Filters'       => $f_term !== '' ? 'Search: ' . $f_term : ($f_ref !== '' ? 'Reference: ' . $f_ref : 'None'),
      'Generated'     => date('d-M-Y H:i') . ' by ' . current_user()['username'],
      'Records'       => count($rows),
  ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr>
        <th style="width:78px">Date</th><th>Item</th><th style="width:80px">Type</th>
        <th class="text-end" style="width:78px">Qty</th><th style="width:46px">UOM</th>
        <th>Location</th><th>Reference / DN</th><th>Supplier</th>
        <th class="text-end" style="width:88px">Value</th><th style="width:78px">User</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><?= e(date('d-m-Y', strtotime($r['movement_date']))) ?></td>
        <td><span class="mono"><?= e($r['item_code']) ?></span> — <?= e($r['item_name']) ?>
          <div class="small text-muted"><?= e($r['category']) ?></div></td>
        <td><?= e(str_replace('_', ' ', $r['movement_type'])) ?></td>
        <td class="text-end"><?= e(fmt_qty($r['quantity'])) ?></td>
        <td><?= e($r['uom']) ?></td>
        <td><small><?= e($r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
              : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id']))) ?></small></td>
        <td><small><?= e($r['reference']) ?><?= $r['dn_no'] ? ' (' . e($r['dn_no']) . ')' : '' ?></small></td>
        <td><small><?= e($r['supplier_name']) ?></small></td>
        <td class="text-end"><?= e(fmt_money($r['line_value'])) ?></td>
        <td><small><?= e($r['username']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No movements for this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td colspan="3" class="text-end">Total receipts / transfers in</td>
        <td class="text-end"><?= e(fmt_qty($totIn)) ?></td>
        <td colspan="5" class="text-end">Total value <?= e(APP_CURRENCY) ?> <?= e(fmt_money($totVal)) ?></td>
        <td></td>
      </tr>
      <tr class="fw-bold">
        <td colspan="3" class="text-end">Total issues / transfers out</td>
        <td class="text-end"><?= e(fmt_qty($totOut)) ?></td>
        <td colspan="6"></td>
      </tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Prepared by (Store Keeper)</div>
    <div class="sig-box">Checked by (Store Manager)</div>
    <div class="sig-box">Approved by (Project Manager)</div>
  </div>
  <div class="doc-foot">Report generated by <?= e(APP_NAME) ?> on <?= e(date('d-M-Y H:i')) ?>.</div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
