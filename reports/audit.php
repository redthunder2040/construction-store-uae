<?php
/** Audit trail report (administrators). */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_audit');

$f_user   = get('user');
$f_action = get('action');
$f_entity = get('entity');
$f_from   = get('from', date('Y-m-d', strtotime('-90 days')));
$f_to     = get('to', date('Y-m-d'));
$f_term   = get('term');

$where = ['1=1'];
$args  = [];
if ($f_user   !== '') { $where[] = 'a.username = ?'; $args[] = $f_user; }
if ($f_action !== '') { $where[] = 'a.action = ?'; $args[] = strtoupper($f_action); }
if ($f_entity !== '') { $where[] = 'a.entity = ?'; $args[] = $f_entity; }
if ($f_from !== '' && is_dob($f_from)) { $where[] = 'DATE(a.created_at) >= ?'; $args[] = $f_from; }
if ($f_to   !== '' && is_dob($f_to))   { $where[] = 'DATE(a.created_at) <= ?'; $args[] = $f_to; }
if ($f_term !== '') {
    $where[] = '(a.details LIKE ? OR a.ip_address LIKE ? OR a.entity LIKE ?)';
    array_push($args, "%$f_term%", "%$f_term%", "%$f_term%");
}
$wsql = ' WHERE ' . implode(' AND ', $where);

$rows = fetch_all("SELECT a.*, c.code AS company_code FROM audit_logs a
                   LEFT JOIN companies c ON c.id = a.company_id
                   $wsql ORDER BY a.created_at DESC, a.id DESC LIMIT 1000", $args);

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['created_at' => $r['created_at'], 'username' => $r['username'], 'action' => $r['action'],
                  'entity' => $r['entity'], 'entity_id' => $r['entity_id'], 'company_code' => $r['company_code'],
                  'details' => $r['details'], 'ip_address' => $r['ip_address']];
    }
    $headers = ['Timestamp', 'User', 'Action', 'Entity', 'Record ID', 'Company', 'Details', 'IP address'];
    $meta = ['Report' => 'Audit trail', 'Period' => $f_from . ' to ' . $f_to,
             'Records' => count($out), 'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username']];
    if (get('export') === 'csv') { export_csv('audit_trail', $headers, $out); }
    export_xls('audit_trail', 'System Audit Trail', $headers, $out, null, $meta);
}

$users   = fetch_all('SELECT DISTINCT username FROM audit_logs ORDER BY username');
$actions = fetch_all('SELECT DISTINCT action FROM audit_logs ORDER BY action');
$entities= fetch_all('SELECT DISTINCT entity FROM audit_logs ORDER BY entity');

$page_title = 'Audit trail';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-2"><label class="form-label">User</label>
      <select name="user" class="form-select form-select-sm">
        <option value="">All users</option>
        <?php foreach ($users as $u): ?><option value="<?= e($u['username']) ?>" <?= $f_user === $u['username'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label">Action</label>
      <select name="action" class="form-select form-select-sm">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>" <?= strtoupper($f_action) === $a['action'] ? 'selected' : '' ?>><?= e($a['action']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label">Entity</label>
      <select name="entity" class="form-select form-select-sm">
        <option value="">All entities</option>
        <?php foreach ($entities as $en): ?><option value="<?= e($en['entity']) ?>" <?= $f_entity === $en['entity'] ? 'selected' : '' ?>><?= e($en['entity']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>"></div>
    <div class="col-md-1"><button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i></button></div>
  </form>

  <?php
  $head = ['id' => 0, 'name' => APP_NAME, 'address' => 'System audit trail (all companies)',
           'phone' => '', 'email' => '', 'trn' => ''];
  echo print_header($head, 'System Audit Trail', [
      'Period' => fmt_date($f_from) . ' — ' . fmt_date($f_to),
      'User' => $f_user ?: 'All', 'Action' => $f_action ?: 'All', 'Entity' => $f_entity ?: 'All',
      'Records' => count($rows) . ' (max 1000 shown)',
      'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'],
  ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead><tr><th style="width:132px">Timestamp</th><th>User</th><th>Action</th><th>Entity</th>
      <th>Record</th><th>Company</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><small><?= e(fmt_dt($r['created_at'])) ?></small></td>
        <td><?= e($r['username']) ?></td>
        <td><span class="badge bg-light text-dark"><?= e($r['action']) ?></span></td>
        <td><?= e($r['entity']) ?></td>
        <td class="text-end"><?= e($r['entity_id']) ?></td>
        <td><small><?= e($r['company_code']) ?></small></td>
        <td><small><?= e($r['details']) ?></small></td>
        <td><small class="mono"><?= e($r['ip_address']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No audit records for this filter.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
