<?php
/**
 * Wrong-URL page. Registered through .htaccess as the ErrorDocument for 404,
 * so a mistyped address inside the installation shows what went wrong instead
 * of Apache's plain "Not Found — The requested URL was not found on this server".
 */
require_once __DIR__ . '/helpers.php';

$requested = (string)($_SERVER['REQUEST_URI'] ?? '');
$base      = APP_BASE_URL === '' ? '/' : APP_BASE_URL . '/';

if (!headers_sent()) { http_response_code(404); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page not found &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="check-body">
<div class="container py-5" style="max-width:760px">
  <div class="app-card">
    <div class="card-body p-4">
      <h1 class="h4 mb-3" style="color:var(--brand)">
        <i class="bi bi-signpost-split"></i> That page does not exist
      </h1>
      <p class="mb-2">The address <code><?= e($requested) ?></code> was not found inside this installation.</p>
      <p class="text-muted small mb-4">The application is reachable at
        <a href="<?= e($base) ?>"><?= e($base) ?></a>. Pick a destination below.</p>

      <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-brand btn-sm" href="<?= e(url('index.php')) ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('items.php')) ?>"><i class="bi bi-boxes"></i> Items</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('movements.php')) ?>"><i class="bi bi-arrow-left-right"></i> Movements</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('delivery_notes.php')) ?>"><i class="bi bi-truck"></i> Delivery notes</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('reports/index.php')) ?>"><i class="bi bi-bar-chart-line"></i> Reports</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('check.php')) ?>"><i class="bi bi-tools"></i> System check</a>
      </div>

      <h6 class="mb-2">Common causes</h6>
      <ul class="small text-muted mb-0">
        <li>The folder name in the URL does not match the folder you extracted
          (it must contain <code>index.php</code>, <code>config.php</code> and <code>install.sql</code>).</li>
        <li>The ZIP was extracted into a folder that already existed, giving
          <code>htdocs/construction-store/construction-store/index.php</code> — move the inner folder up one level.</li>
        <li>The URL was typed as <code>http://localhost/</code> while the application sits in
          <code>htdocs/construction-store/</code>.</li>
        <li>Apache was started from a different <code>htdocs</code> folder than the one holding the application.</li>
      </ul>
    </div>
    <div class="card-body border-top py-2 rt-attribution-bar">
      <?= rt_attribution_html('print') ?>
    </div>
  </div>
</div>
<?= rt_attribution_html('fixed') ?>
</body>
</html>
