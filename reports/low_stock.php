<?php
/** Reorder / low-stock report. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope = scope_company_id();
$f_co  = $scope ?: (int)get('company');
$f_cat = (int)get('category');
$mode  = get('mode', 'low');    // low | zero | ok | all

$where = ['1=1'];
$args  = [];
if ($f_co)  { $where[] = 'i.company_id = ?'; $args[] = $f_co; }
if ($f_cat) { $where[] = 'i.category_id = ?'; $args[] = $f_cat; }
if ($mode === 'low')  { $where[] = 'i.quantity <= i.reorder_level'; }
if ($mode === 'zero') { $where[] = 'i.quantity <= 0'; }
if ($mode === 'ok')   { $where[] = 'i.quantity > i.reorder_level'; }
$wsql = ' WHERE ' . implode(' AND ', $where) . ($mode === 'low' ? ' AND i.reorder_level > 0' : '');

$base = "FROM items i
         JOIN companies co ON co.id = i.company_id
         LEFT JOIN categories c ON c.id = i.category_id
         LEFT JOIN projects p ON p.id = i.project_id
         LEFT JOIN suppliers s ON s.id = i.supplier_id
         $wsql";

$select = "SELECT i.*, co.code AS company_code, co.name AS company_name, c.name AS category,
                  p.code AS project_code, p.name AS project_name, s.name AS supplier_name,
                  s.contact_person, s.phone AS supplier_phone,
                  (i.reorder_level - i.quantity) AS shortfall,
                  CEIL(i.reorder_level * 2 - i.quantity) AS suggested_order ";

$rows = fetch_all($select . $base . ' ORDER BY (i.quantity <= 0) DESC, shortfall DESC, i.item_code', $args);
$suggestedValue = 0.0;
foreach ($rows as $r) { $suggestedValue += (float)$r['suggested_order'] * (float)$r['unit_cost']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'item_code' => $r['item_code'], 'item_name' => $r['item_name'],
                  'category' => $r['category'], 'uom' => $r['uom'], 'quantity' => fmt_qty($r['quantity']),
                  'reorder_level' => fmt_qty($r['reorder_level']), 'shortfall' => fmt_qty(max(0, $r['shortfall'])),
                  'suggested_order' => fmt_qty(max(0, $r['suggested_order'])),
                  'unit_cost' => fmt_money($r['unit_cost']),
                  'order_value' => fmt_money(max(0, (float)$r['suggested_order']) * (float)$r['unit_cost']),
                  'location' => $r['project_name'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store',
                  'supplier' => $r['supplier_name'], 'supplier_phone' => $r['supplier_phone']];
    }
    $headers = ['Company', 'Item code', 'Item name', 'Category', 'UOM', 'On hand', 'Reorder level', 'Shortfall',
                'Suggested order qty', 'Unit cost', 'Suggested order value', 'Location', 'Supplier', 'Supplier phone'];
    $meta = ['Report' => 'Reorder / low stock report', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Filter' => ucfirst($mode), 'Items listed' => count($out),
             'Suggested purchase value' => APP_CURRENCY . ' ' . fmt_money($suggestedValue),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username']];
    if (get('export') === 'csv') { export_csv('low_stock', $headers, $out); }
    export_xls('low_stock', 'Reorder / Low Stock Report', $headers, $out, null, $meta);
}

$company = $f_co ? fetch_one('SELECT * FROM companies WHERE id = ?', [$f_co]) : null;
$page_title = 'Reorder report';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Filter</label>
      <select name="mode" class="form-select form-select-sm">
        <option value="low"  <?= $mode === 'low' ? 'selected' : '' ?>>At or below reorder level</option>
        <option value="zero" <?= $mode === 'zero' ? 'selected' : '' ?>>Zero / negative stock</option>
        <option value="ok"   <?= $mode === 'ok' ? 'selected' : '' ?>>Above reorder level</option>
        <option value="all"  <?= $mode === 'all' ? 'selected' : '' ?>>All items</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Category</label>
      <select name="category" class="form-select form-select-sm">
        <option value="0">All categories</option>
        <?php foreach (fetch_all('SELECT * FROM categories ORDER BY name') as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $f_cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button></div>
  </form>

  <?php
  $head = $company ?: ['id' => 0, 'name' => APP_NAME, 'address' => 'Consolidated multi-company report',
                       'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'Reorder / Low Stock Report', [
      'Company'   => $f_co ? company_name($f_co) : 'All companies',
      'Filter'    => ucfirst($mode),
      'Items listed' => count($rows),
      'Suggested purchase value' => APP_CURRENCY . ' ' . fmt_money($suggestedValue),
      'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
  ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr><th>Item code</th><th>Description</th><th>Category</th><th>UOM</th>
          <th class="text-end">On hand</th><th class="text-end">Reorder</th><th class="text-end">Shortfall</th>
          <th class="text-end">Suggested order</th><th class="text-end">Unit cost</th><th class="text-end">Order value</th>
          <th>Preferred supplier</th><th>Location</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= (float)$r['quantity'] <= 0 ? 'table-danger' : '' ?>">
        <td class="mono"><?= e($r['item_code']) ?></td>
        <td><?= e($r['item_name']) ?></td>
        <td><small><?= e($r['category']) ?></small></td>
        <td><?= e($r['uom']) ?></td>
        <td class="text-end fw-semibold"><?= e(fmt_qty($r['quantity'])) ?></td>
        <td class="text-end text-muted"><?= e(fmt_qty($r['reorder_level'])) ?></td>
        <td class="text-end"><?= e(fmt_qty(max(0, $r['shortfall']))) ?></td>
        <td class="text-end"><?= e(fmt_qty(max(0, $r['suggested_order']))) ?></td>
        <td class="text-end"><?= e(fmt_money($r['unit_cost'])) ?></td>
        <td class="text-end"><?= e(fmt_money(max(0, (float)$r['suggested_order']) * (float)$r['unit_cost'])) ?></td>
        <td><small><?= e($r['supplier_name']) ?><?= $r['supplier_phone'] ? ' (' . e($r['supplier_phone']) . ')' : '' ?></small></td>
        <td><small><?= e($r['project_code'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store') ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-4">No items match this filter.</td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold table-light"><td colspan="9" class="text-end">Total suggested purchase value</td>
        <td class="text-end"><?= e(fmt_money($suggestedValue)) ?></td><td colspan="2"></td></tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Keeper</div><div class="sig-box">Store Manager</div><div class="sig-box">Procurement / Approved by</div>
  </div>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
