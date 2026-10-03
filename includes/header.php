<?php
require_once __DIR__ . '/../helpers.php';

$page_title = $page_title ?? 'Dashboard';
$active_nav = $active_nav ?? '';
$ui_company = $ui_company ?? null;      // company record when the page is company-scoped

$USER          = require_login();
$flash_msgs    = flash();
$nav_companies = companies_for_user();
$scope_id      = scope_company_id();
$scope_label   = $scope_id ? company_name($scope_id) : 'All Companies';

$main_nav = [
    'index.php'          => ['Dashboard', 'speedometer2'],
    'items.php'          => ['Items', 'boxes'],
    'movements.php'      => ['Movements', 'arrow-left-right'],
    'transfers.php'      => ['Transfers', 'shuffle'],
    'delivery_notes.php' => ['Delivery Notes', 'truck'],
    'reports/index.php'  => ['Reports', 'bar-chart-line'],
];
$master_nav = [
    'companies.php' => ['Companies', 'building'],
    'projects.php'  => ['Projects', 'diagram-3'],
    'suppliers.php' => ['Suppliers', 'person-vcard'],
    'settings.php'  => ['Categories & UOM', 'tags'],
    'users.php'     => ['Users', 'people'],
    'audit_logs.php'=> ['Audit Logs', 'shield-check'],
];
$master_allowed = [
    'companies.php' => 'manage_companies',
    'users.php'     => 'manage_users',
    'audit_logs.php'=> 'view_audit',
    'projects.php'  => 'manage_projects',
    'suppliers.php' => 'manage_suppliers',
    'settings.php'  => 'manage_items',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg app-navbar sticky-top no-print">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('index.php')) ?>">
      <span class="brand-mark"><i class="bi bi-box-seam"></i></span>
      <span class="brand-text">
        <strong><?= e(APP_NAME) ?></strong>
        <small>Stock &amp; Movement Control</small>
      </span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($main_nav as $href => $meta): ?>
          <?php if ($href === 'reports/index.php' && !can('view_reports')) continue; ?>
          <li class="nav-item">
            <a class="nav-link <?= $active_nav === $href ? 'active' : '' ?>" href="<?= e(url($href)) ?>">
              <i class="bi bi-<?= e($meta[1]) ?>"></i> <?= e($meta[0]) ?>
            </a>
          </li>
        <?php endforeach; ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($active_nav, array_keys($master_nav), true) ? 'active' : '' ?>"
             href="#" data-bs-toggle="dropdown"><i class="bi bi-database-gear"></i> Masters</a>
          <ul class="dropdown-menu">
            <?php foreach ($master_nav as $href => $meta): ?>
              <?php if (!can($master_allowed[$href] ?? 'manage_items')) continue; ?>
              <li><a class="dropdown-item" href="<?= e(url($href)) ?>"><i class="bi bi-<?= e($meta[1]) ?>"></i> <?= e($meta[0]) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3">
        <?php if (is_admin() && count($nav_companies) > 1): ?>
          <form class="d-flex align-items-center gap-2 mb-0" method="get" action="<?= e(url('company_switch.php')) ?>">
            <i class="bi bi-building text-white-50"></i>
            <select name="cid" class="form-select form-select-sm company-switch" onchange="this.form.submit()">
              <option value="all" <?= $scope_id === null ? 'selected' : '' ?>>All Companies</option>
              <?php foreach ($nav_companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $scope_id === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= e($c['code'] . ' — ' . $c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="return" value="<?= e(basename($_SERVER['PHP_SELF']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">
          </form>
        <?php else: ?>
          <span class="scope-chip"><i class="bi bi-building"></i> <?= e($scope_label) ?></span>
        <?php endif; ?>

        <div class="dropdown">
          <a class="user-chip dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <span class="avatar"><?= e(strtoupper(substr($USER['full_name'], 0, 1))) ?></span>
            <span class="d-none d-md-inline">
              <strong><?= e($USER['full_name']) ?></strong>
              <small><?= e(['admin' => 'Administrator', 'store_manager' => 'Store Manager', 'store_keeper' => 'Store Keeper'][$USER['role']] ?? $USER['role']) ?></small>
            </span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header"><?= e($USER['username']) ?></h6></li>
            <li><span class="dropdown-item-text small text-muted"><?= e($scope_label) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person-gear"></i> My account</a></li>
            <li><a class="dropdown-item" href="<?= e(url('reports/index.php')) ?>"><i class="bi bi-printer"></i> Reports &amp; printing</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= e(url('logout.php')) ?>"><i class="bi bi-box-arrow-right"></i> Sign out</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>

<main class="container-fluid app-main">
<?php foreach ($flash_msgs as $fm): ?>
  <div class="alert alert-<?= e($fm['type']) ?> alert-dismissible fade show no-print" role="alert">
    <?= $fm['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endforeach; ?>
