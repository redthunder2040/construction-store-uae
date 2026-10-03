<?php
/** On-hand stock per location with valuation. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope  = scope_company_id();
$f_co   = $scope ?: (int)get('company');
$f_proj = get('project');
$f_cat  = (int)get('category');
$f_stock = get('stock');
$f_term = get('term');

$where = ['s.quantity > 0'];
$args  = [];
if ($f_co)   { $where[] = 's.company_id = ?'; $args[] = $f_co; }
if ($f_proj !== '' && $f_proj !== null && $f_proj !== 'all') { $where[] = 's.project_id = ?'; $args[] = (int)$f_proj; }
if ($f_cat)  { $where[] = 'i.category_id = ?'; $args[] = $f_cat; }
if ($f_stock === 'low')  { $where[] = 's.quantity <= i.reorder_level'; }
if ($f_term !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ? OR i.rack_no LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$base = "FROM stock_locations s
         JOIN items i ON i.id = s.item_id
         JOIN companies co ON co.id = s.company_id
         LEFT JOIN categories c ON c.id = i.category_id
         LEFT JOIN projects p ON p.id = s.project_id
         LEFT JOIN suppliers sup ON sup.id = i.supplier_id
         $wsql";

$select = "SELECT i.item_code, i.item_name, i.uom, i.unit_cost, i.reorder_level, i.rack_no,
                  c.name AS category, co.code AS company_code, co.name AS company_name,
                  p.code AS project_code, p.name AS project_name, sup.name AS supplier_name,
                  s.quantity AS loc_qty, i.quantity AS total_qty,
                  (s.quantity * i.unit_cost) AS loc_value, s.project_id ";

$rows = fetch_all($select . $base . ' ORDER BY co.code, p.code, i.item_code', $args);

$totQty = 0.0; $totVal = 0.0; $totAll = 0.0;
foreach ($rows as $r) { $totQty += (float)$r['loc_qty']; $totVal += (float)$r['loc_value']; $totAll += (float)$r['total_qty']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'company_name' => $r['company_name'],
                  'location' => $r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store',
                  'item_code' => $r['item_code'], 'item_name' => $r['item_name'], 'category' => $r['category'],
                  'uom' => $r['uom'], 'loc_qty' => fmt_qty($r['loc_qty']), 'total_qty' => fmt_qty($r['total_qty']),
                  'reorder' => fmt_qty($r['reorder_level']), 'status' => (float)$r['loc_qty'] <= (float)$r['reorder_level'] ? 'BELOW REORDER' : 'OK',
                  'unit_cost' => fmt_money($r['unit_cost']), 'loc_value' => fmt_money($r['loc_value']),
                  'rack' => $r['rack_no'], 'supplier' => $r['supplier_name']];
    }
    $headers = ['Company', 'Company name', 'Location', 'Item code', 'Item name', 'Category', 'UOM',
                'Qty at location', 'Total on hand', 'Reorder level', 'Status', 'Unit cost', 'Value at location',
                'Rack', 'Supplier'];
    $meta = ['Report' => 'Stock by location & valuation', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Location' => ($f_proj === '' || $f_proj === null || $f_proj === 'all') ? 'All locations' : project_name((int)$f_proj),
             'Total quantity' => fmt_qty($totQty), 'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)];
    if (get('export') === 'csv') { export_csv('stock_by_location', $headers, $out); }
    export_xls('stock_by_location', 'Stock by Location & Valuation', $headers, $out, null, $meta);
}

$byLocation = [];
foreach ($rows as $r) {
    $key = $r['project_code'] ? ($r['project_code'] . ' — ' . $r['project_name']) : 'Main Store / Central Warehouse';
    if (!isset($byLocation[$key])) { $byLocation[$key] = ['qty' => 0.0, 'val' => 0.0, 'items' => 0]; }
    $byLocation[$key]['qty'] += (float)$r['loc_qty'];
    $byLocation[$key]['val'] += (float)$r['loc_value'];
    $byLocation[$key]['items']++;
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Stock by location';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-2">
      <label class="form-label">Location</label>
      <select name="project" class="form-select form-select-sm">
        <option value="all">All locations</option>
        <option value="0" <?= (string)$f_proj === '0' ? 'selected' : '' ?>>Main Store only</option>
        <?php foreach (projects_for_select($f_co) as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (string)$f_proj === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Category</label>
      <select name="category" class="form-select form-select-sm">
        <option value="0">All</option>
        <?php foreach (fetch_all('SELECT * FROM categories ORDER BY name') as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f_cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Stock status</label>
      <select name="stock" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="low" <?= $f_stock === 'low' ? 'selected' : '' ?>>Below reorder level</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Search</label>
      <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>">
    </div>
    <div class="col-md-2">
      <button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
    </div>
  </form>

  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Stock by Location & Valuation', [
      'Company'  => $f_co ? company_name($f_co) : 'All companies',
      'Location' => ($f_proj === '' || $f_proj === null || $f_proj === 'all') ? 'All locations' : project_name((int)$f_proj),
      'Category' => $f_cat ? (string)fetch_val('SELECT name FROM categories WHERE id = ?', [$f_cat]) : 'All',
      'Total quantity' => fmt_qty($totQty),
      'Total value'    => APP_CURRENCY . ' ' . fmt_money($totVal),
      'Generated'      => date('d-M-Y H:i') . ' by ' . current_user()['username'],
      'Lines'          => count($rows),
  ]);
  ?>

  <table class="table table-sm table-bordered mt-3 mb-3">
    <thead><tr><th>Location</th><th class="text-end">Item lines</th><th class="text-end">Quantity</th><th class="text-end">Value</th></tr></thead>
    <tbody>
    <?php foreach ($byLocation as $loc => $agg): ?>
      <tr><td><?= e($loc) ?></td><td class="text-end"><?= (int)$agg['items'] ?></td>
          <td class="text-end"><?= e(fmt_qty($agg['qty'])) ?></td>
          <td class="text-end"><?= e(fmt_money($agg['val'])) ?></td></tr>
    <?php endforeach; ?>
    <tr class="fw-bold table-light"><td>Total</td><td class="text-end"><?= count($rows) ?></td>
      <td class="text-end"><?= e(fmt_qty($totQty)) ?></td><td class="text-end"><?= e(fmt_money($totVal)) ?></td></tr>
    </tbody>
  </table>

  <table class="table table-bordered table-sm doc-table">
    <thead>
      <tr><th>Company</th><th>Location</th><th>Item code</th><th>Description</th><th>Category</th>
          <th>UOM</th><th class="text-end">Qty here</th><th class="text-end">Total on hand</th>
          <th class="text-end">Reorder</th><th class="text-end">Unit cost</th><th class="text-end">Value</th><th>Rack</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): $low = (float)$r['loc_qty'] <= (float)$r['reorder_level']; ?>
      <tr class="<?= $low ? 'table-warning' : '' ?>">
        <td><small><?= e($r['company_code']) ?></small></td>
        <td><small><?= e($r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store') ?></small></td>
        <td class="mono"><?= e($r['item_code']) ?></td>
        <td><?= e($r['item_name']) ?></td>
        <td><small><?= e($r['category']) ?></small></td>
        <td><?= e($r['uom']) ?></td>
        <td class="text-end fw-semibold"><?= e(fmt_qty($r['loc_qty'])) ?></td>
        <td class="text-end text-muted"><?= e(fmt_qty($r['total_qty'])) ?></td>
        <td class="text-end text-muted"><?= e(fmt_qty($r['reorder_level'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['unit_cost'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['loc_value'])) ?></td>
        <td><small class="mono"><?= e($r['rack_no']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-4">No stock matches these filters.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold table-light"><td colspan="6" class="text-end">Totals</td>
        <td class="text-end"><?= e(fmt_qty($totQty)) ?></td><td class="text-end"><?= e(fmt_qty($totAll)) ?></td>
        <td colspan="2"></td><td class="text-end"><?= e(fmt_money($totVal)) ?></td><td></td></tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Keeper</div><div class="sig-box">Store Manager</div><div class="sig-box">Finance / Audit</div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
