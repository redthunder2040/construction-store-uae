<?php
/** Item master listing report. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope  = scope_company_id();
$f_co   = $scope ?: (int)get('company');
$f_cat  = (int)get('category');
$f_term = get('term');
$f_act  = get('active');

$where = ['1=1'];
$args  = [];
if ($f_co)  { $where[] = 'i.company_id = ?'; $args[] = $f_co; }
if ($f_cat) { $where[] = 'i.category_id = ?'; $args[] = $f_cat; }
if ($f_act !== '' && $f_act !== null) { $where[] = 'i.active = ?'; $args[] = (int)$f_act; }
if ($f_term !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ? OR i.rack_no LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$rows = fetch_all(
    "SELECT i.*, co.code AS company_code, co.name AS company_name, c.name AS category,
            p.code AS project_code, p.name AS project_name, s.name AS supplier_name,
            (i.quantity * i.unit_cost) AS stock_value
     FROM items i
     JOIN companies co ON co.id = i.company_id
     LEFT JOIN categories c ON c.id = i.category_id
     LEFT JOIN projects p ON p.id = i.project_id
     LEFT JOIN suppliers s ON s.id = i.supplier_id
     $wsql ORDER BY co.code, i.item_code", $args);

$totQty = 0.0; $totVal = 0.0;
foreach ($rows as $r) { $totQty += (float)$r['quantity']; $totVal += (float)$r['stock_value']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'company_name' => $r['company_name'],
                  'item_code' => $r['item_code'], 'item_name' => $r['item_name'], 'category' => $r['category'],
                  'uom' => $r['uom'], 'quantity' => fmt_qty($r['quantity']),
                  'reorder_level' => fmt_qty($r['reorder_level']), 'unit_cost' => fmt_money($r['unit_cost']),
                  'stock_value' => fmt_money($r['stock_value']),
                  'location' => $r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store',
                  'rack' => $r['rack_no'], 'supplier' => $r['supplier_name'],
                  'status' => (int)$r['active'] === 1 ? 'Active' : 'Inactive'];
    }
    $headers = ['Company', 'Company name', 'Item code', 'Item name', 'Category', 'UOM', 'Qty on hand',
                'Reorder level', 'Unit cost', 'Stock value', 'Location', 'Rack', 'Supplier', 'Status'];
    $meta = ['Report' => 'Item master listing', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Items' => count($out), 'Total quantity' => fmt_qty($totQty),
             'Total stock value' => APP_CURRENCY . ' ' . fmt_money($totVal),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username']];
    if (get('export') === 'csv') { export_csv('item_master_listing', $headers, $out); }
    export_xls('item_master_listing', 'Item Master Listing', $headers, $out, null, $meta);
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Item master listing';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-3"><label class="form-label">Category</label>
      <select name="category" class="form-select form-select-sm">
        <option value="0">All categories</option>
        <?php foreach (fetch_all('SELECT * FROM categories ORDER BY name') as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f_cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label">Status</label>
      <select name="active" class="form-select form-select-sm">
        <option value="">All</option>
        <option value="1" <?= (string)$f_act === '1' ? 'selected' : '' ?>>Active only</option>
        <option value="0" <?= (string)$f_act === '0' ? 'selected' : '' ?>>Inactive only</option>
      </select></div>
    <div class="col-md-4"><label class="form-label">Search</label>
      <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>"></div>
    <div class="col-md-2"><button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button></div>
  </form>

  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Item Master Listing', [
      'Company' => $f_co ? company_name($f_co) : 'All companies',
      'Category' => $f_cat ? (string)fetch_val('SELECT name FROM categories WHERE id = ?', [$f_cat]) : 'All',
      'Items' => count($rows), 'Total quantity' => fmt_qty($totQty),
      'Total stock value' => APP_CURRENCY . ' ' . fmt_money($totVal),
      'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
  ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr><th>Company</th><th>Code</th><th>Description</th><th>Category</th><th>UOM</th>
          <th class="text-end">Qty on hand</th><th class="text-end">Reorder</th><th class="text-end">Unit cost</th>
          <th class="text-end">Value</th><th>Location</th><th>Rack</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><small><?= e($r['company_code']) ?></small></td>
        <td class="mono"><?= e($r['item_code']) ?></td>
        <td><?= e($r['item_name']) ?></td>
        <td><small><?= e($r['category']) ?></small></td>
        <td><?= e($r['uom']) ?></td>
        <td class="text-end fw-semibold"><?= e(fmt_qty($r['quantity'])) ?></td>
        <td class="text-end text-muted"><?= e(fmt_qty($r['reorder_level'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['unit_cost'])) ?></td>
        <td class="text-end"><?= e(fmt_money($r['stock_value'])) ?></td>
        <td><small><?= e($r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store') ?></small></td>
        <td><small class="mono"><?= e($r['rack_no']) ?></small></td>
        <td><small><?= (int)$r['active'] === 1 ? 'Active' : 'Inactive' ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-4">No items match these filters.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold table-light"><td colspan="5" class="text-end">Totals (<?= count($rows) ?> items)</td>
        <td class="text-end"><?= e(fmt_qty($totQty)) ?></td><td colspan="2"></td>
        <td class="text-end"><?= e(fmt_money($totVal)) ?></td><td colspan="3"></td></tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Keeper</div><div class="sig-box">Store Manager</div><div class="sig-box">Approved by</div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
