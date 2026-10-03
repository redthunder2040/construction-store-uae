<?php
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope = scope_company_id();
$k     = kpi_summary($scope);
$co    = $scope ? fetch_one('SELECT * FROM companies WHERE id = ?', [$scope]) : null;

$param = $scope ? 'company=' . $scope : '';

$reports = [
    ['movements.php',    'Stock movement register',      'Every receipt, issue and adjustment with filters by date, type, location and item.', 'arrow-left-right', $param],
    ['ledger.php',       'Item stock card (ledger)',      'Full transaction history of a single item with running balance — ideal for stock counts.', 'journal-text', ''],
    ['inventory.php',    'Stock by location & valuation', 'On-hand quantities per store / project with valuation at unit cost.', 'geo-alt', $param],
    ['low_stock.php',    'Reorder / low-stock report',    'Items at or below reorder level, plus items with zero balance.', 'exclamation-triangle', $param],
    ['transfers.php',    'Transfer register',             'Inter-store and inter-project transfers, by transfer reference.', 'shuffle', $param],
    ['dn_register.php',  'Delivery note register',        'All delivery notes issued to sites with quantities and values.', 'truck', $param],
    ['items_list.php',   'Item master listing',           'The complete catalogue with UOM, reorder level, location and value.', 'boxes', $param],
    ['valuation.php',    'Stock valuation summary',       'Company-wide stock value grouped by company, project and category.', 'cash-stack', $param],
    ['audit.php',        'Audit trail',                   'Sign-ins and every add / edit / delete / export performed in the system.', 'shield-check', $param],
];
?>
<?php
$page_title = 'Reports & Printing';
$active_nav = 'reports/index.php';
$company = $co;
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Reports &amp; Printing</h1>
    <p class="text-muted mb-0"><?= e($scope ? company_name($scope) : 'All companies') ?>
      &middot; choose a report, filter it, then print, save as PDF or export to Excel</p>
  </div>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-outline-primary" href="<?= e(url('reports/movements.php?' . $param)) ?>" target="_blank"><i class="bi bi-printer"></i> Daily movement sheet</a>
  </div>
</div>

<div class="alert alert-info no-print d-flex gap-2">
  <i class="bi bi-lightbulb"></i>
  <div>Every report page is print-optimised: use <strong>Print</strong> for a direct print or choose
    <strong>“Save as PDF”</strong> in the browser print dialog. Excel and CSV buttons download a spreadsheet-ready file.
    If a company letterhead image is uploaded on the company record, it appears at the top of printed reports and delivery notes.</div>
</div>

<div class="row g-3">
  <?php foreach ($reports as $r): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card app-card h-100 text-decoration-none report-tile" href="<?= e(url('reports/' . $r[0] . ($r[4] ? '?' . $r[4] : ''))) ?>">
        <div class="card-body d-flex gap-3">
          <div class="stat-icon"><i class="bi bi-<?= e($r[3]) ?>"></i></div>
          <div>
            <h6 class="mb-1 text-dark"><?= e($r[1]) ?></h6>
            <p class="small text-muted mb-0"><?= e($r[2]) ?></p>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-image"></i> Printing with a company letterhead</h5></div>
      <div class="card-body small">
        <p class="mb-2">To brand printed documents:</p>
        <ol class="ps-3 mb-2">
          <li>Go to <a href="<?= e(url('companies.php')) ?>">Masters → Companies</a> and open (or create) a company.</li>
          <li>In the <strong>Letterhead</strong> panel upload a PNG/JPG of your letterhead (recommended 1200 × 400 px, scan or design with the logo at the top).</li>
          <li>Print any report or delivery note — the image is placed at the top of the page automatically.</li>
        </ol>
        <p class="mb-0 text-muted">If no letterhead is uploaded, a clean text header (company name, address, phone, email, TRN) is printed instead.</p>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-bar-chart"></i> Current position</h5></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><td>Items in catalogue</td><td class="text-end fw-semibold"><?= number_format($k['items']) ?></td></tr>
            <tr><td>Stock value</td><td class="text-end fw-semibold"><?= e(APP_CURRENCY) ?> <?= e(fmt_money($k['stock_value'])) ?></td></tr>
            <tr><td>Movements recorded</td><td class="text-end fw-semibold"><?= number_format($k['movements']) ?></td></tr>
            <tr><td>Movements in the last 30 days</td><td class="text-end fw-semibold"><?= number_format($k['movements_30']) ?></td></tr>
            <tr><td>Delivery notes</td><td class="text-end fw-semibold"><?= number_format($k['delivery_notes']) ?></td></tr>
            <tr><td>Items at / below reorder level</td><td class="text-end fw-semibold text-warning"><?= number_format($k['low_stock']) ?></td></tr>
            <tr><td>Items with zero stock</td><td class="text-end fw-semibold text-danger"><?= number_format($k['zero_stock']) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
