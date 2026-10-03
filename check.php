<?php
/**
 * Environment / diagnostics page — no sign-in required, read-only.
 * Open this first whenever a page does not load: it tells you the URL the app
 * thinks it lives at, whether the database answers, whether the mandatory
 * attribution is intact, and how to fix the common XAMPP mistakes.
 */
require_once __DIR__ . '/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System check &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="check-body">
<div class="container py-4" style="max-width:900px">
  <h1 class="h4 mb-1" style="color:var(--brand)">System check</h1>
  <p class="text-muted small">Read-only diagnostics for <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>. Delete this file before
    handing the system over to end users.</p>

  <?php
  /* ---------------------------------------------------------------- paths */
  $appDir   = str_replace('\\', '/', __DIR__);
  $nested   = is_file(dirname(__DIR__) . '/helpers.php') || is_file(dirname(__DIR__) . '/configuration.php');
  $guessUrl = (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : 'http://localhost')
            . APP_BASE_URL . '/';
  $desired  = $appDir . '/index.php';

  /* ------------------------------------------------------------- database */
  $dbOk = true; $dbMsg = ''; $counts = [];
  try {
    $counts = [
        'companies' => (int)fetch_val('SELECT COUNT(*) FROM companies'),
        'users'     => (int)fetch_val('SELECT COUNT(*) FROM users'),
        'items'     => (int)fetch_val('SELECT COUNT(*) FROM items'),
        'movements' => (int)fetch_val('SELECT COUNT(*) FROM movements'),
        'locations' => (int)fetch_val('SELECT COUNT(*) FROM stock_locations'),
        'dn'        => (int)fetch_val('SELECT COUNT(*) FROM delivery_notes'),
    ];
  } catch (Throwable $ex) {
    $dbOk = false; $dbMsg = $ex->getMessage();
  }

  /* ---------------------------------------------------------- attribution */
  $attrFile = __DIR__ . '/includes/powered_by.php';
  $attrOk   = is_file($attrFile)
      && RT_ATTRIBUTION_TEXT === 'Powered by Red Thunder E.G'
      && defined('RT_POWERED_BY_SHA256')
      && strlen((string)RT_POWERED_BY_SHA256) === 64
      && strtoupper(hash_file('sha256', $attrFile)) === strtoupper((string)RT_POWERED_BY_SHA256);

  /* --------------------------------------------------------------- pages */
  $pages = ['index.php', 'login.php', 'items.php', 'movements.php', 'transfers.php',
            'delivery_notes.php', 'reports/index.php', 'companies.php', 'projects.php',
            'suppliers.php', 'users.php', 'settings.php', 'audit_logs.php',
            'assets/css/style.css', 'assets/js/app.js', 'install.sql'];
  $missing = [];
  foreach ($pages as $p) { if (!is_file(__DIR__ . '/' . $p)) { $missing[] = $p; } }

  $rows = [
      ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=')],
      ['PDO MySQL driver', in_array('mysql', PDO::getAvailableDrivers(), true) ? 'available' : 'MISSING', in_array('mysql', PDO::getAvailableDrivers(), true)],
      ['mbstring extension', extension_loaded('mbstring') ? 'loaded' : 'MISSING (reports may truncate text)', extension_loaded('mbstring')],
      ['Application folder', $appDir, true],
      ['Detected base URL', APP_BASE_URL === '' ? '(document root)' : APP_BASE_URL, true],
      ['Open the app at', $guessUrl, true],
      ['DocumentRoot', (string)($_SERVER['DOCUMENT_ROOT'] ?? 'n/a'), true],
      ['index.php of this app', $desired . (is_file($desired) ? '  (found)' : '  (NOT FOUND)'), is_file($desired)],
      ['Duplicate folder nesting', $nested ? 'YES — this copy sits inside another copy' : 'no', !$nested],
      ['Database connection', $dbOk ? 'connected to ' . DB_NAME . ' as ' . DB_USER : 'FAILED: ' . $dbMsg, $dbOk],
      ['Mandatory attribution', $attrOk ? 'intact — ' . RT_ATTRIBUTION_TEXT : 'BROKEN — the app halts at boot', $attrOk],
      ['Sample data', $dbOk ? $counts['items'] . ' items, ' . $counts['movements'] . ' movements, ' . $counts['dn'] . ' delivery notes' : 'n/a', !$dbOk || $counts['items'] > 0],
      ['Letterhead folder writable', is_writable(__DIR__ . '/assets/letterheads') ? 'yes' : 'no — chmod 775 assets/letterheads', is_writable(__DIR__ . '/assets/letterheads')],
      ['Application files', $missing ? 'MISSING: ' . implode(', ', $missing) : 'all present (' . count($pages) . ' checked)', !$missing],
  ];
  ?>

  <table class="table table-sm table-bordered bg-white align-middle">
    <thead><tr><th style="width:34%">Check</th><th>Result</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r[0]) ?></td>
        <td class="<?= $r[2] ? '' : 'table-danger fw-semibold' ?>">
          <?= $r[2] ? '<span class="text-success">&#10004;</span> ' : '<span class="text-danger">&#10008;</span> ' ?>
          <span class="mono small"><?= e($r[1]) ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$dbOk): ?>
  <div class="alert alert-danger">
    <h6 class="mb-2">The database is not reachable</h6>
    <ol class="mb-0 small">
      <li>Start <strong>MySQL</strong> in the XAMPP Control Panel.</li>
      <li>Create the database <code>construction_store</code> in
        <a href="http://localhost/phpmyadmin" target="_blank">phpMyAdmin</a>
        (collation <code>utf8mb4_unicode_ci</code>).</li>
      <li>Select it &rarr; <strong>Import</strong> &rarr; choose
        <code><?= e($appDir) ?>/install.sql</code> &rarr; <strong>Import</strong>.</li>
      <li>If your MySQL user differs, edit <code>DB_USER</code> / <code>DB_PASS</code> in
        <code>config.php</code>.</li>
    </ol>
  </div>
  <?php elseif ($counts['items'] === 0): ?>
  <div class="alert alert-warning">
    <strong>The database is connected but empty.</strong> Import
    <code><?= e($appDir) ?>/install.sql</code> through phpMyAdmin to load the schema and the sample data.
  </div>
  <?php endif; ?>

  <?php if ($nested): ?>
  <div class="alert alert-warning">
    <strong>Duplicate folder detected.</strong> This application lives at
    <code><?= e($appDir) ?></code>, but another copy of it exists one level above
    (<code><?= e(str_replace('\\', '/', dirname(__DIR__))) ?></code>). That usually means the ZIP was extracted into a
    folder that already contained the application. Keep <em>one</em> copy only — the folder you point your browser at
    should be the one containing <code>index.php</code>, <code>config.php</code> and <code>install.sql</code>.
  </div>
  <?php endif; ?>

  <div class="alert alert-info small">
    <strong>Getting "Not Found — The requested URL was not found on this server"?</strong>
    That is Apache's own 404 page, which means the browser was pointed at a path where no file exists. Two causes
    cover almost every case:
    <ol class="mb-0 mt-2">
      <li><strong>Wrong URL.</strong> Use <a href="<?= e($guessUrl) ?>"><?= e($guessUrl) ?></a> — the folder name must
        match exactly (lower-case, hyphen, no spaces). Typing <code>http://localhost/</code> alone shows the 404 page
        when nothing is installed in the web root.</li>
      <li><strong>Double-nested folder.</strong> If the ZIP was extracted into an existing
        <code>construction-store</code> folder you get
        <code>htdocs/construction-store/construction-store/index.php</code>, and
        <code>http://localhost/construction-store/</code> finds no file. Move the inner folder up one level.</li>
    </ol>
  </div>

  <p class="small text-muted">
    Once every line above is green, open <a href="<?= e(url('login.php')) ?>"><?= e(url('login.php')) ?></a> and sign in
    with <code>admin</code> / <code>Admin@123</code>.
  </p>

  <?= rt_attribution_html('card') ?>
</div>
<?= rt_attribution_html('fixed') ?>
</body>
</html>
