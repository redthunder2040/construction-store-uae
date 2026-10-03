<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('view_audit');

$f_user   = get('user');
$f_action = get('action');
$f_entity = get('entity');
$f_term   = get('term');

$where = ['1=1'];
$args  = [];
if ($f_user   !== '') { $where[] = 'a.username = ?'; $args[] = $f_user; }
if ($f_action !== '') { $where[] = 'a.action = ?'; $args[] = strtoupper($f_action); }
if ($f_entity !== '') { $where[] = 'a.entity = ?'; $args[] = $f_entity; }
if ($f_term !== '') {
    $where[] = '(a.details LIKE ? OR a.entity LIKE ? OR a.username LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$base = "FROM audit_logs a LEFT JOIN companies c ON c.id = a.company_id $wsql";
$pg = paginate((int)fetch_val('SELECT COUNT(*)' . $base, $args), 50, (int)get_num('page', 1));
$rows = fetch_all("SELECT a.*, c.code AS company_code " . $base . " ORDER BY a.created_at DESC, a.id DESC
                   LIMIT {$pg['offset']}, 50", $args);

$stats = fetch_all('SELECT action, COUNT(*) AS n FROM audit_logs GROUP BY action ORDER BY n DESC');

if (get('export')) {
    require_can('export_data');
    $all = fetch_all("SELECT a.*, c.code AS company_code " . $base . ' ORDER BY a.created_at DESC', $args);
    $out = [];
    foreach ($all as $r) {
        $out[] = ['created_at' => $r['created_at'], 'username' => $r['username'], 'action' => $r['action'],
                  'entity' => $r['entity'], 'entity_id' => $r['entity_id'], 'company_code' => $r['company_code'],
                  'details' => $r['details'], 'ip_address' => $r['ip_address']];
    }
    $headers = ['Timestamp', 'User', 'Action', 'Entity', 'Record ID', 'Company', 'Details', 'IP address'];
    export_xls('audit_logs', 'Audit Trail', $headers, $out, null,
        ['Filters' => ($f_user ? 'user=' . $f_user . ' ' : '') . ($f_action ? 'action=' . $f_action : ''),
         'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)]);
}

$page_title = 'Audit Logs';
$active_nav = 'audit_logs.php';
$qbase = ['user' => $f_user, 'action' => $f_action, 'entity' => $f_entity, 'term' => $f_term];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Audit Trail</h1>
    <p class="text-muted mb-0"><?= number_format($pg['total']) ?> logged event(s) — sign-ins plus every add, edit, delete and export</p>
  </div>
  <?php if (can('export_data')): ?>
    <a class="btn btn-outline-secondary no-print" href="?<?= e(http_build_query($qbase + ['export' => 'xls'])) ?>">
      <i class="bi bi-file-earmark-excel"></i> Export full log</a>
  <?php endif; ?>
</div>

<div class="row g-3 mb-3">
  <?php foreach (array_slice($stats, 0, 6) as $s): ?>
    <div class="col-6 col-md-2">
      <div class="stat-card">
        <div class="stat-icon"><i class="bi bi-activity"></i></div>
        <div class="stat-body"><span class="stat-label"><?= e($s['action']) ?></span>
          <strong class="stat-value"><?= number_format($s['n']) ?></strong></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<form class="filter-bar row g-2 align-items-end no-print" method="get">
  <div class="col-md-2">
    <label class="form-label">User</label>
    <select name="user" class="form-select form-select-sm">
      <option value="">All users</option>
      <?php foreach (fetch_all('SELECT DISTINCT username FROM audit_logs ORDER BY username') as $u): ?>
        <option value="<?= e($u['username']) ?>" <?= $f_user === $u['username'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Action</label>
    <select name="action" class="form-select form-select-sm">
      <option value="">All actions</option>
      <?php foreach (fetch_all('SELECT DISTINCT action FROM audit_logs ORDER BY action') as $a): ?>
        <option value="<?= e($a['action']) ?>" <?= strtoupper($f_action) === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Entity</label>
    <select name="entity" class="form-select form-select-sm">
      <option value="">All entities</option>
      <?php foreach (fetch_all('SELECT DISTINCT entity FROM audit_logs ORDER BY entity') as $en): ?>
        <option value="<?= e($en['entity']) ?>" <?= $f_entity === $en['entity'] ? 'selected' : '' ?>><?= e($en['entity']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Search details</label>
    <input type="text" name="term" class="form-control form-control-sm" value="<?= e($f_term) ?>">
  </div>
  <div class="col-md-3 d-flex gap-2">
    <button class="btn btn-brand btn-sm flex-fill"><i class="bi bi-funnel"></i> Filter</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('audit_logs.php')) ?>">Reset</a>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('reports/audit.php?' . http_build_query($qbase))) ?>" target="_blank"><i class="bi bi-printer"></i></a>
  </div>
</form>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Entity</th><th class="text-end">Record</th>
        <th>Company</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><small><?= e(fmt_dt($r['created_at'])) ?></small></td>
          <td class="mono"><?= e($r['username']) ?></td>
          <td><span class="badge bg-<?= in_array($r['action'], ['DELETE'], true) ? 'danger' : (in_array($r['action'], ['INSERT'], true) ? 'success' : (in_array($r['action'], ['EXPORT', 'PRINT'], true) ? 'info text-dark' : 'secondary')) ?>"><?= e($r['action']) ?></span></td>
          <td><?= e($r['entity']) ?></td>
          <td class="text-end"><?= e($r['entity_id']) ?></td>
          <td><small><?= e($r['company_code']) ?></small></td>
          <td><small><?= e(mb_strimwidth((string)$r['details'], 0, 90, '…')) ?></small></td>
          <td><small class="mono"><?= e($r['ip_address']) ?></small></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">No audit records match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body d-flex justify-content-between no-print">
    <small class="text-muted">Showing <?= count($rows) ?> of <?= number_format($pg['total']) ?> events</small>
    <?= pager_links($pg, $qbase) ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
