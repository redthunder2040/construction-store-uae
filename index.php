<?php
require_once __DIR__ . '/helpers.php';

$USER       = require_login();
$scope      = scope_company_id();
$page_title = 'Dashboard';
$active_nav = 'index.php';
$company    = $scope ? fetch_one('SELECT * FROM companies WHERE id = ?', [$scope]) : null;

$k = kpi_summary($scope);

$w = $scope ? ' AND company_id = ' . (int)$scope : '';

$recent = fetch_all(
    "SELECT m.*, i.item_code, i.item_name, u.username
     FROM movements m
     JOIN items i ON i.id = m.item_id
     LEFT JOIN users u ON u.id = m.created_by
     WHERE 1=1 $w
     ORDER BY m.movement_date DESC, m.id DESC LIMIT 10"
);

$low = fetch_all(
    "SELECT i.*, c.name AS category, p.code AS project_code
     FROM items i
     LEFT JOIN categories c ON c.id = i.category_id
     LEFT JOIN projects p ON p.id = i.project_id
     WHERE 1=1 " . ($scope ? ' AND i.company_id = ' . (int)$scope : '') . "
       AND i.quantity <= i.reorder_level
     ORDER BY (i.reorder_level - i.quantity) DESC LIMIT 10"
);

$by_project = fetch_all(
    "SELECT COALESCE(p.code, 'MAIN') AS code, COALESCE(p.name, 'Main Store') AS name,
            COUNT(DISTINCT s.item_id) AS items, COALESCE(SUM(s.quantity),0) AS qty
     FROM stock_locations s
     LEFT JOIN projects p ON p.id = s.project_id
     WHERE s.quantity > 0 " . ($scope ? ' AND s.company_id = ' . (int)$scope : '') . "
     GROUP BY s.project_id ORDER BY qty DESC LIMIT 8"
);

$chart = fetch_all(
    "SELECT DATE_FORMAT(movement_date, '%Y-%m') AS ym,
            SUM(CASE WHEN movement_type IN ('IN','TRANSFER_IN') THEN quantity ELSE 0 END) AS qin,
            SUM(CASE WHEN movement_type IN ('OUT','TRANSFER_OUT') THEN quantity ELSE 0 END) AS qout
     FROM movements
     WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $w
     GROUP BY ym ORDER BY ym"
);
$chart_max = 0.0;
foreach ($chart as $c) { $chart_max = max($chart_max, (float)$c['qin'], (float)$c['qout']); }
if ($chart_max <= 0) $chart_max = 1;

$top_categories = fetch_all(
    "SELECT c.name, COUNT(i.id) AS items, COALESCE(SUM(i.quantity * i.unit_cost),0) AS value
     FROM categories c JOIN items i ON i.category_id = c.id
     WHERE 1=1 " . ($scope ? ' AND i.company_id = ' . (int)$scope : '') . "
     GROUP BY c.id ORDER BY value DESC LIMIT 6"
);

$dn_recent = fetch_all(
    "SELECT d.*, p.code AS project_code, c.name AS company_name
     FROM delivery_notes d
     LEFT JOIN projects p ON p.id = d.project_id
     JOIN companies c ON c.id = d.company_id
     WHERE 1=1 " . ($scope ? ' AND d.company_id = ' . (int)$scope : '') . "
     ORDER BY d.dn_date DESC, d.id DESC LIMIT 6"
);

include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Dashboard</h1>
    <p class="text-muted mb-0">
      <?= e($scope ? company_name($scope) : 'Consolidated view across all companies') ?>
      &middot; <?= e(date('d-M-Y')) ?>
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2 no-print">
    <?php if (can('record_movement')): ?>
      <a class="btn btn-brand" href="<?= e(url('movement_form.php')) ?>"><i class="bi bi-plus-circle"></i> Record movement</a>
    <?php endif; ?>
    <?php if (can('manage_transfer')): ?>
      <a class="btn btn-outline-primary" href="<?= e(url('transfers.php')) ?>"><i class="bi bi-shuffle"></i> Transfer stock</a>
    <?php endif; ?>
    <?php if (can('create_delivery_note')): ?>
      <a class="btn btn-outline-primary" href="<?= e(url('delivery_note_form.php')) ?>"><i class="bi bi-truck"></i> New delivery note</a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['Items in catalogue', number_format($k['items']), $k['items_active'] . ' active', 'boxes', 'blue'],
      ['Stock value', APP_CURRENCY . ' ' . fmt_money($k['stock_value']), 'At current unit cost', 'cash-stack', 'green'],
      ['Movements (30 days)', number_format($k['movements_30']), number_format($k['movements']) . ' total records', 'arrow-left-right', 'purple'],
      ['Delivery notes', number_format($k['delivery_notes']), $k['dn_open'] . ' open / pending', 'truck', 'amber'],
      ['Projects', number_format($k['projects']), number_format($k['suppliers']) . ' suppliers', 'diagram-3', 'teal'],
      ['Below reorder level', number_format($k['low_stock']), $k['zero_stock'] . ' at zero stock', 'exclamation-triangle', 'red'],
  ];
  foreach ($cards as $c): ?>
    <div class="col-6 col-lg-4 col-xl-2">
      <div class="stat-card stat-<?= e($c[4]) ?>">
        <div class="stat-icon"><i class="bi bi-<?= e($c[3]) ?>"></i></div>
        <div class="stat-body">
          <span class="stat-label"><?= e($c[0]) ?></span>
          <strong class="stat-value"><?= e($c[1]) ?></strong>
          <small><?= e($c[2]) ?></small>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card app-card h-100">
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-bar-chart-line"></i> Stock flow — last 6 months</h5>
        <span class="badge bg-light text-dark">Receipts vs issues</span>
      </div>
      <div class="card-body">
        <div class="chart-legend mb-2">
          <span><i class="dot bg-success"></i> Receipts (IN / Transfer In)</span>
          <span><i class="dot bg-danger"></i> Issues (OUT / Transfer Out)</span>
        </div>
        <div class="bar-chart">
          <?php foreach ($chart as $c): ?>
            <div class="bar-group">
              <div class="bars">
                <div class="bar bar-in" style="height:<?= max(2, round((float)$c['qin'] / $chart_max * 100)) ?>%"
                     title="Receipts: <?= e(fmt_qty($c['qin'])) ?>"></div>
                <div class="bar bar-out" style="height:<?= max(2, round((float)$c['qout'] / $chart_max * 100)) ?>%"
                     title="Issues: <?= e(fmt_qty($c['qout'])) ?>"></div>
              </div>
              <span class="bar-label"><?= e(date('M', strtotime($c['ym'] . '-01'))) ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (!$chart): ?><p class="text-muted mb-0">No movements recorded in the last 6 months.</p><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card app-card h-100">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-pie-chart"></i> Stock value by category</h5></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle">
          <tbody>
          <?php $tot = array_sum(array_column($top_categories, 'value')) ?: 1; ?>
          <?php foreach ($top_categories as $t): ?>
            <tr>
              <td>
                <div class="d-flex justify-content-between"><span><?= e($t['name']) ?></span>
                  <strong><?= e(fmt_money($t['value'])) ?></strong></div>
                <div class="progress thin"><div class="progress-bar bg-brand" style="width:<?= round((float)$t['value'] / $tot * 100) ?>%"></div></div>
                <small class="text-muted"><?= (int)$t['items'] ?> items</small>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-xl-7">
    <div class="card app-card">
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Latest movements</h5>
        <a class="btn btn-sm btn-outline-secondary no-print" href="<?= e(url('movements.php')) ?>">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="text-end">Qty</th><th>Location</th><th>By</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="text-nowrap"><?= e(fmt_date($r['movement_date'])) ?></td>
              <td><span class="mono"><?= e($r['item_code']) ?></span><br><small class="text-muted"><?= e($r['item_name']) ?></small></td>
              <td><?= badge_for_movement($r['movement_type']) ?></td>
              <td class="text-end"><?= e(fmt_qty($r['quantity'])) ?> <small class="text-muted"><?= e($r['uom']) ?></small></td>
              <td><small><?= e($r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
                    : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id']))) ?></small></td>
              <td><small class="text-muted"><?= e($r['username'] ?? 'system') ?></small></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-4">No movements yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card app-card mb-3">
      <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-danger"></i> Reorder alerts</h5>
        <a class="btn btn-sm btn-outline-secondary no-print" href="<?= e(url('reports/low_stock.php')) ?>">Report</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Code</th><th>Item</th><th class="text-end">On hand</th><th class="text-end">Reorder</th></tr></thead>
          <tbody>
          <?php foreach ($low as $l): ?>
            <tr>
              <td class="mono small"><?= e($l['item_code']) ?></td>
              <td><small><?= e($l['item_name']) ?></small></td>
              <td class="text-end <?= (float)$l['quantity'] <= 0 ? 'text-danger fw-bold' : 'text-warning fw-semibold' ?>"><?= e(fmt_qty($l['quantity'])) ?></td>
              <td class="text-end text-muted"><?= e(fmt_qty($l['reorder_level'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$low): ?><tr><td colspan="4" class="text-center text-muted py-4">All items are above their reorder level.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-geo-alt"></i> Stock held by location</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Store / Project</th><th class="text-end">Items</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
          <?php foreach ($by_project as $b): ?>
            <tr>
              <td><strong class="mono small"><?= e($b['code']) ?></strong> <small class="text-muted"><?= e($b['name']) ?></small></td>
              <td class="text-end"><?= (int)$b['items'] ?></td>
              <td class="text-end"><?= e(fmt_qty($b['qty'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card app-card mt-3">
  <div class="card-header">
    <h5 class="mb-0"><i class="bi bi-truck"></i> Recent delivery notes</h5>
    <a class="btn btn-sm btn-outline-secondary no-print" href="<?= e(url('delivery_notes.php')) ?>">Delivery note register</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>DN No.</th><th>Date</th><th>Deliver to</th><th>Status</th><th>Vehicle</th><th class="text-end no-print">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($dn_recent as $d): ?>
        <tr>
          <td class="mono"><?= e($d['dn_no']) ?></td>
          <td><?= e(fmt_date($d['dn_date'])) ?></td>
          <td><small><?= e($d['project_code']) ?></small> <small class="text-muted"><?= e(project_name((int)$d['project_id'])) ?></small></td>
          <td><?= badge_for_status($d['status']) ?></td>
          <td><small><?= e($d['vehicle_no']) ?></small></td>
          <td class="text-end no-print">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('delivery_note_print.php?id=' . (int)$d['id'])) ?>" target="_blank"><i class="bi bi-printer"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
