<?php
require_once __DIR__ . '/helpers.php';
require_login();

/* ---------------------------------------------------------------- delete */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    require_can('delete_items');
    $id = (int)post('id');
    $it = fetch_one('SELECT * FROM items WHERE id = ?', [$id]);
    if ($it) {
        guard_company((int)$it['company_id']);
        $used = (int)fetch_val('SELECT COUNT(*) FROM movements WHERE item_id = ?', [$id]);
        $dns  = (int)fetch_val('SELECT COUNT(*) FROM delivery_note_items WHERE item_id = ?', [$id]);
        if ($used || $dns) {
            q('UPDATE items SET active = 0, updated_at = NOW() WHERE id = ?', [$id]);
            audit('update', 'items', $id, 'Deactivated — has ' . $used . ' movements / ' . $dns . ' DN lines');
            flash('Item <strong>' . e($it['item_code']) . '</strong> has transaction history ('
                . $used . ' movements, ' . $dns . ' delivery-note lines) so it was <strong>deactivated</strong> instead of deleted.', 'warning');
        } else {
            q('DELETE FROM items WHERE id = ?', [$id]);
            audit('delete', 'items', $id, $it['item_code'] . ' / ' . $it['item_name']);
            flash('Item <strong>' . e($it['item_code']) . '</strong> was deleted.', 'success');
        }
    }
    redirect('items.php');
}

$scope   = scope_company_id();
$f_co    = $scope ?: (int)get('company');
$f_proj  = (int)get('project');
$f_cat   = (int)get('category');
$f_stock = get('stock');
$f_term  = get('term');
$sort    = get('sort', 'code');

$where = ['1=1'];
$args  = [];
if ($f_co)    { $where[] = 'i.company_id = ?';    $args[] = $f_co; }
if ($f_proj !== 0 && $f_proj !== '') { $where[] = 'i.project_id = ?'; $args[] = $f_proj; }
if ($f_cat)   { $where[] = 'i.category_id = ?';   $args[] = $f_cat; }
if ($f_stock === 'low')  { $where[] = 'i.quantity <= i.reorder_level'; }
if ($f_stock === 'zero') { $where[] = 'i.quantity <= 0'; }
if ($f_stock === 'ok')   { $where[] = 'i.quantity > i.reorder_level'; }
if ($f_term !== '') {
    $where[] = '(i.item_code LIKE ? OR i.item_name LIKE ? OR i.rack_no LIKE ?)';
    $args[] = '%' . $f_term . '%'; $args[] = '%' . $f_term . '%'; $args[] = '%' . $f_term . '%';
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$order = ['code' => 'i.item_code', 'name' => 'i.item_name', 'qty' => 'i.quantity DESC',
          'value' => '(i.quantity*i.unit_cost) DESC', 'recent' => 'i.updated_at DESC'][$sort] ?? 'i.item_code';

$base = "FROM items i
         LEFT JOIN categories c ON c.id = i.category_id
         LEFT JOIN projects p ON p.id = i.project_id
         LEFT JOIN companies co ON co.id = i.company_id
         LEFT JOIN suppliers s ON s.id = i.supplier_id
         $wsql";

$select = "SELECT i.*, c.name AS category, p.code AS project_code, p.name AS project_name,
                  co.code AS company_code, s.name AS supplier_name, (i.quantity * i.unit_cost) AS stock_value ";

/* ---------------------------------------------------------------- exports */
$mode = get('export');
if ($mode) {
    require_can('export_data');
    $rows = fetch_all($select . $base . " ORDER BY $order");
    $headers = ['Company', 'Item Code', 'Item Name', 'Category', 'UOM', 'Quantity on hand', 'Reorder level',
                'Unit cost', 'Stock value', 'Location', 'Rack', 'Supplier', 'Status'];
    $keys = ['company_code', 'item_code', 'item_name', 'category', 'uom', 'quantity', 'reorder_level',
             'unit_cost', 'stock_value', 'location', 'rack_no', 'supplier_name', 'status'];
    foreach ($rows as &$r) {
        $r['location']     = $r['project_name'] ? ($r['project_code'] . ' — ' . $r['project_name']) : 'Main Store';
        $r['status']       = ((int)$r['active'] === 1) ? 'Active' : 'Inactive';
        $r['quantity']     = fmt_qty($r['quantity']);
        $r['reorder_level']= fmt_qty($r['reorder_level']);
        $r['unit_cost']    = fmt_money($r['unit_cost']);
        $r['stock_value']  = fmt_money($r['stock_value']);
    }
    unset($r);
    $meta = ['Report' => 'Item master listing', 'Company' => $f_co ? company_name($f_co) : 'All companies',
             'Category' => $f_cat ? (string)fetch_val('SELECT name FROM categories WHERE id = ?', [$f_cat]) : 'All',
             'Filters' => ($f_term !== '' ? 'Search: ' . $f_term . ' | ' : '') . 'Stock: ' . ($f_stock ?: 'all'),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
             'Rows' => count($rows)];
    if ($mode === 'csv') { export_csv('item_master', $headers, $rows, $keys); }
    export_xls('item_master', 'Item Master Listing', $headers, $rows, $keys, $meta);
}

$pg = paginate((int)fetch_val('SELECT COUNT(*)' . $base, $args), (int)get_num('per', ROWS_PER_PAGE), (int)get_num('page', 1));
$rows = fetch_all($select . $base . " ORDER BY $order LIMIT {$pg['offset']}, " . (int)get_num('per', ROWS_PER_PAGE), $args);

$sumQty   = (float)fetch_val('SELECT COALESCE(SUM(i.quantity),0)' . $base, $args);
$sumValue = (float)fetch_val('SELECT COALESCE(SUM(i.quantity * i.unit_cost),0)' . $base, $args);

$page_title = 'Item Master';
$active_nav = 'items.php';
$qbase = ['company' => $f_co, 'project' => $f_proj, 'category' => $f_cat, 'stock' => $f_stock, 'term' => $f_term, 'sort' => $sort];
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Item Master</h1>
    <p class="text-muted mb-0"><?= number_format($pg['total']) ?> item(s) &middot; on hand <?= e(fmt_qty($sumQty)) ?>
      &middot; stock value <?= e(APP_CURRENCY) ?> <?= e(fmt_money($sumValue)) ?></p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (can('export_data')): ?>
      <?= export_buttons(['<i class="bi bi-filetype-csv"></i> CSV' => '?' . e(http_build_query($qbase + ['export' => 'csv'])),
                          '<i class="bi bi-file-earmark-excel"></i> Excel' => '?' . e(http_build_query($qbase + ['export' => 'xls'])),
                          '<i class="bi bi-printer"></i> Print' => 'javascript:window.print()']) ?>
    <?php endif; ?>
    <?php if (can('manage_items')): ?>
      <a class="btn btn-brand" href="<?= e(url('item_form.php')) ?>"><i class="bi bi-plus-lg"></i> New item</a>
    <?php endif; ?>
  </div>
</div>

<form class="filter-bar row g-2 align-items-end no-print" method="get">
  <div class="col-md-2">
    <label class="form-label">Company</label>
    <?php if ($scope): ?>
      <input class="form-control form-control-sm" value="<?= e(company_name($scope)) ?>" disabled>
    <?php else: ?>
      <select name="company" class="form-select form-select-sm" data-autosubmit>
        <option value="0">All companies</option>
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
      <option value="0" <?= get('project') === '0' ? 'selected' : '' ?>>Main Store</option>
      <?php foreach (projects_for_select($f_co ?: $scope) as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $f_proj === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Category</label>
    <select name="category" class="form-select form-select-sm">
      <option value="0">All categories</option>
      <?php foreach (fetch_all('SELECT * FROM categories ORDER BY name') as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $f_cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Stock status</label>
    <select name="stock" class="form-select form-select-sm">
      <option value="">All</option>
      <option value="ok"   <?= $f_stock === 'ok' ? 'selected' : '' ?>>Above reorder level</option>
      <option value="low"  <?= $f_stock === 'low' ? 'selected' : '' ?>>At / below reorder level</option>
      <option value="zero" <?= $f_stock === 'zero' ? 'selected' : '' ?>>Zero stock</option>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Search</label>
    <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>" placeholder="Code, name or rack">
  </div>
  <div class="col-md-2 d-flex gap-2">
    <button class="btn btn-brand btn-sm flex-fill"><i class="bi bi-funnel"></i> Filter</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('items.php')) ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
  </div>
</form>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Company</th><th>Code</th><th>Item</th><th>Category</th><th>UOM</th>
          <th class="text-end">Qty on hand</th><th class="text-end">Reorder</th>
          <th class="text-end">Unit cost</th><th class="text-end">Value</th>
          <th>Location</th><th>Rack</th><th>Status</th>
          <?php if (can('manage_items')): ?><th class="text-end no-print">Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): $lowS = (float)$r['quantity'] <= (float)$r['reorder_level']; ?>
        <tr class="<?= (int)$r['active'] === 0 ? 'table-secondary' : '' ?>">
          <td><span class="badge bg-light text-dark"><?= e($r['company_code']) ?></span></td>
          <td class="mono"><?= e($r['item_code']) ?></td>
          <td>
            <a href="<?= e(url('item_form.php?id=' . (int)$r['id'])) ?>" class="text-decoration-none fw-semibold"><?= e($r['item_name']) ?></a>
            <?php if ($r['supplier_name']): ?><div class="small text-muted"><i class="bi bi-truck"></i> <?= e($r['supplier_name']) ?></div><?php endif; ?>
          </td>
          <td><small class="text-muted"><?= e($r['category']) ?></small></td>
          <td><?= e($r['uom']) ?></td>
          <td class="text-end fw-semibold <?= (float)$r['quantity'] <= 0 ? 'text-danger' : ($lowS ? 'text-warning' : '') ?>"><?= e(fmt_qty($r['quantity'])) ?></td>
          <td class="text-end text-muted"><?= e(fmt_qty($r['reorder_level'])) ?></td>
          <td class="text-end"><?= e(fmt_money($r['unit_cost'])) ?></td>
          <td class="text-end"><?= e(fmt_money($r['stock_value'])) ?></td>
          <td><small><?= e($r['project_name'] ? $r['project_code'] . ' — ' . $r['project_name'] : 'Main Store') ?></small></td>
          <td class="mono small"><?= e($r['rack_no']) ?></td>
          <td><?= (int)$r['active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <?php if (can('manage_items')): ?>
          <td class="text-end no-print text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('item_form.php?id=' . (int)$r['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('reports/ledger.php?item=' . (int)$r['id'])) ?>" title="Stock card"><i class="bi bi-journal-text"></i></a>
            <?php if (can('delete_items')): ?>
            <form method="post" class="d-inline" data-confirm="Delete item <?= e($r['item_code']) ?>? Items with transaction history are deactivated instead.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="13" class="text-center text-muted py-5">No items match the selected filters.</td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot>
        <tr class="table-light">
          <th colspan="5" class="text-end">Page totals</th>
          <th class="text-end"><?= e(fmt_qty(array_sum(array_map(function ($r) { return (float)$r['quantity']; }, $rows)))) ?></th>
          <th colspan="2"></th>
          <th class="text-end"><?= e(fmt_money(array_sum(array_map(function ($r) { return (float)$r['stock_value']; }, $rows)))) ?></th>
          <th colspan="4"></th>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="card-body d-flex justify-content-between align-items-center no-print">
    <small class="text-muted">Showing <?= count($rows) ?> of <?= number_format($pg['total']) ?> items</small>
    <?= pager_links($pg, $qbase) ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
