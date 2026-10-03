<?php
/** Stock valuation summary — by company, project and category. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope = scope_company_id();
$f_co  = $scope ?: (int)get('company');
$w     = $f_co ? ' AND i.company_id = ' . (int)$f_co : '';

$byCompany = fetch_all(
    "SELECT co.code, co.name, COUNT(i.id) AS items, COALESCE(SUM(i.quantity),0) AS qty,
            COALESCE(SUM(i.quantity * i.unit_cost),0) AS value,
            SUM(CASE WHEN i.quantity <= i.reorder_level THEN 1 ELSE 0 END) AS low_stock
     FROM companies co LEFT JOIN items i ON i.company_id = co.id
     " . ($f_co ? ' WHERE co.id = ' . (int)$f_co : '') . "
     GROUP BY co.id ORDER BY value DESC");

$byProject = fetch_all(
    "SELECT COALESCE(p.code,'MAIN') AS code, COALESCE(p.name,'Main Store / Central Warehouse') AS name,
            co.code AS company_code, COUNT(DISTINCT s.item_id) AS items,
            COALESCE(SUM(s.quantity),0) AS qty,
            COALESCE(SUM(s.quantity * i.unit_cost),0) AS value
     FROM stock_locations s JOIN items i ON i.id = s.item_id JOIN companies co ON co.id = s.company_id
     LEFT JOIN projects p ON p.id = s.project_id
     WHERE s.quantity > 0 $w
     GROUP BY s.project_id, s.company_id ORDER BY value DESC");

$byCategory = fetch_all(
    "SELECT c.name, COUNT(i.id) AS items, COALESCE(SUM(i.quantity),0) AS qty,
            COALESCE(SUM(i.quantity * i.unit_cost),0) AS value
     FROM categories c LEFT JOIN items i ON i.category_id = c.id" . ($f_co ? ' AND i.company_id = ' . (int)$f_co : '') . "
     GROUP BY c.id HAVING items > 0 ORDER BY value DESC");

$rows = fetch_all(
    "SELECT i.item_code, i.item_name, i.uom, i.quantity, i.unit_cost, i.reorder_level,
            (i.quantity * i.unit_cost) AS total_value, c.name AS category, co.code AS company_code,
            p.code AS project_code, p.name AS project_name
     FROM items i JOIN companies co ON co.id = i.company_id
     LEFT JOIN categories c ON c.id = i.category_id
     LEFT JOIN projects p ON p.id = i.project_id
     WHERE 1=1 $w ORDER BY total_value DESC", []);

$totQty = 0.0; $totVal = 0.0; $totLow = 0;
foreach ($byCompany as $b) { $totQty += (float)$b['qty']; $totVal += (float)$b['value']; $totLow += (int)$b['low_stock']; }
$catTotal = 0.0;
foreach ($byCategory as $b) { $catTotal += (float)$b['value']; }
$projTotal = 0.0;
foreach ($byProject as $b) { $projTotal += (float)$b['value']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'item_code' => $r['item_code'], 'item_name' => $r['item_name'],
                  'category' => $r['category'], 'uom' => $r['uom'], 'quantity' => fmt_qty($r['quantity']),
                  'unit_cost' => fmt_money($r['unit_cost']), 'total_value' => fmt_money($r['total_value']),
                  'location' => $r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store',
                  'reorder' => fmt_qty($r['reorder_level']),
                  'status' => (float)$r['quantity'] <= (float)$r['reorder_level'] ? 'BELOW REORDER' : 'OK'];
    }
    $headers = ['Company', 'Item code', 'Item name', 'Category', 'UOM', 'Quantity', 'Unit cost', 'Value',
                'Location', 'Reorder level', 'Status'];
    $meta = ['Report' => 'Stock valuation summary', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Total quantity' => fmt_qty($totQty), 'Total stock value' => APP_CURRENCY . ' ' . fmt_money($totVal),
             'Items below reorder level' => $totLow,
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)];
    if (get('export') === 'csv') { export_csv('stock_valuation', $headers, $out); }
    export_xls('stock_valuation', 'Stock Valuation Summary', $headers, $out, null, $meta);
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Stock valuation';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Stock Valuation Summary', [
      'Company' => $f_co ? company_name($f_co) : 'All companies',
      'Valuation basis' => 'Quantity on hand × current unit cost',
      'Total quantity' => fmt_qty($totQty),
      'Total stock value' => APP_CURRENCY . ' ' . fmt_money($totVal),
      'Items below reorder level' => $totLow,
      'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
  ]);
  ?>

  <h6 class="mt-4">By company</h6>
  <table class="table table-bordered table-sm doc-table">
    <thead><tr><th>Code</th><th>Company</th><th class="text-end">Items</th><th class="text-end">Quantity</th>
      <th class="text-end">Low stock items</th><th class="text-end">Stock value</th></tr></thead>
    <tbody>
    <?php foreach ($byCompany as $b): ?>
      <tr><td class="mono"><?= e($b['code']) ?></td><td><?= e($b['name']) ?></td>
        <td class="text-end"><?= (int)$b['items'] ?></td><td class="text-end"><?= e(fmt_qty($b['qty'])) ?></td>
        <td class="text-end"><?= (int)$b['low_stock'] ?></td><td class="text-end"><?= e(fmt_money($b['value'])) ?></td></tr>
    <?php endforeach; ?>
    <tr class="fw-bold table-light"><td colspan="2">Total</td><td colspan="3"></td>
      <td class="text-end"><?= e(fmt_money($totVal)) ?></td></tr>
    </tbody>
  </table>

  <h6 class="mt-4">By store / project</h6>
  <table class="table table-bordered table-sm doc-table">
    <thead><tr><th>Company</th><th>Store / project</th><th class="text-end">Items</th>
      <th class="text-end">Quantity</th><th class="text-end">Value</th><th class="text-end">% of total</th></tr></thead>
    <tbody>
    <?php foreach ($byProject as $b): ?>
      <tr><td><small><?= e($b['company_code']) ?></small></td>
        <td><span class="mono"><?= e($b['code']) ?></span> — <?= e($b['name']) ?></td>
        <td class="text-end"><?= (int)$b['items'] ?></td><td class="text-end"><?= e(fmt_qty($b['qty'])) ?></td>
        <td class="text-end"><?= e(fmt_money($b['value'])) ?></td>
        <td class="text-end"><?= $projTotal > 0 ? number_format((float)$b['value'] / $projTotal * 100, 1) : '0.0' ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h6 class="mt-4">By category</h6>
  <table class="table table-bordered table-sm doc-table">
    <thead><tr><th>Category</th><th class="text-end">Items</th><th class="text-end">Quantity</th>
      <th class="text-end">Value</th><th class="text-end">% of total</th></tr></thead>
    <tbody>
    <?php foreach ($byCategory as $b): ?>
      <tr><td><?= e($b['name']) ?></td><td class="text-end"><?= (int)$b['items'] ?></td>
        <td class="text-end"><?= e(fmt_qty($b['qty'])) ?></td>
        <td class="text-end"><?= e(fmt_money($b['value'])) ?></td>
        <td class="text-end"><?= $catTotal > 0 ? number_format((float)$b['value'] / $catTotal * 100, 1) : '0.0' ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h6 class="mt-4">Item detail (top 200 by value)</h6>
  <table class="table table-bordered table-sm doc-table">
    <thead><tr><th>Code</th><th>Description</th><th>Category</th><th>UOM</th><th>Location</th>
      <th class="text-end">Qty</th><th class="text-end">Unit cost</th><th class="text-end">Value</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($rows, 0, 200) as $r): ?>
      <tr><td class="mono"><?= e($r['item_code']) ?></td><td><?= e($r['item_name']) ?></td>
        <td><small><?= e($r['category']) ?></small></td><td><?= e($r['uom']) ?></td>
        <td><small><?= e($r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store') ?></small></td>
        <td class="text-end"><?= e(fmt_qty($r['quantity'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['unit_cost'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['total_value'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Manager</div><div class="sig-box">Finance Manager</div><div class="sig-box">Auditor</div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
