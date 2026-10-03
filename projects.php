<?php
require_once __DIR__ . '/helpers.php';
require_login();

$scope = scope_company_id();
$canEdit = can('manage_projects');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    require_can('manage_projects');
    $id = (int)post('id');
    $p  = fetch_one('SELECT * FROM projects WHERE id = ?', [$id]);
    if ($p) {
        guard_company((int)$p['company_id']);
        $used = (int)fetch_val('SELECT COUNT(*) FROM movements WHERE project_id = ? OR from_project_id = ? OR to_project_id = ?', [$id, $id, $id]);
        $dns  = (int)fetch_val('SELECT COUNT(*) FROM delivery_notes WHERE project_id = ?', [$id]);
        if ($used || $dns) {
            q("UPDATE projects SET status = 'on_hold' WHERE id = ?", [$id]);
            flash('Project <strong>' . e($p['name']) . '</strong> has ' . $used . ' movement(s) and ' . $dns
                . ' delivery note(s): it was set to <strong>On hold</strong> instead of deleted.', 'warning');
        } else {
            q('DELETE FROM projects WHERE id = ?', [$id]);
            audit('delete', 'projects', $id, $p['name']);
            flash('Project <strong>' . e($p['name']) . '</strong> deleted.', 'success');
        }
    }
    redirect('projects.php');
}

$f_co = $scope ?: (int)get('company');
$w    = $f_co ? ' AND p.company_id = ' . (int)$f_co : '';

$rows = fetch_all(
    "SELECT p.*, co.code AS company_code, co.name AS company_name,
            (SELECT COUNT(DISTINCT s.item_id) FROM stock_locations s WHERE s.project_id = p.id AND s.quantity > 0) AS item_count,
            (SELECT COALESCE(SUM(s.quantity),0) FROM stock_locations s WHERE s.project_id = p.id) AS stock_qty,
            (SELECT COUNT(*) FROM delivery_notes d WHERE d.project_id = p.id) AS dn_count
     FROM projects p JOIN companies co ON co.id = p.company_id
     WHERE 1=1 $w ORDER BY co.code, p.code");

$totalQty = 0.0;
foreach ($rows as $r) { $totalQty += (float)$r['stock_qty']; }

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'code' => $r['code'], 'name' => $r['name'],
                  'client' => $r['client_name'], 'location' => $r['location'], 'status' => str_replace('_', ' ', $r['status']),
                  'item_count' => $r['item_count'], 'stock_qty' => fmt_qty($r['stock_qty']), 'dn_count' => $r['dn_count']];
    }
    $headers = ['Company', 'Project code', 'Project name', 'Client', 'Location', 'Status', 'Items on site',
                'Quantity on site', 'Delivery notes'];
    export_xls('projects', 'Projects & Sites', $headers, $out, null,
        ['Company' => $f_co ? company_name($f_co) : 'All companies',
         'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)]);
}

$page_title = 'Projects & Sites';
$active_nav = 'projects.php';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Projects &amp; Site Stores</h1>
    <p class="text-muted mb-0"><?= count($rows) ?> project(s) &middot; <?= e(fmt_qty($totalQty)) ?> units currently held on site</p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (can('export_data')): ?>
      <a class="btn btn-outline-secondary" href="?export=xls&company=<?= (int)$f_co ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <?php endif; ?>
    <a class="btn btn-outline-primary" href="<?= e(url('reports/inventory.php?project=all')) ?>" target="_blank"><i class="bi bi-geo-alt"></i> Stock by location</a>
    <?php if ($canEdit): ?>
      <a class="btn btn-brand" href="<?= e(url('project_form.php')) ?>"><i class="bi bi-plus-lg"></i> New project</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$scope): ?>
<form class="filter-bar row g-2 align-items-end no-print" method="get">
  <div class="col-md-3">
    <label class="form-label">Company</label>
    <select name="company" class="form-select form-select-sm" data-autosubmit>
      <option value="0">All companies</option>
      <?php foreach (companies_for_user() as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $f_co === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>
<?php endif; ?>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Company</th><th>Code</th><th>Project</th><th>Client</th><th>Location</th>
        <th class="text-end">Items on site</th><th class="text-end">Qty on site</th><th class="text-end">Delivery notes</th>
        <th>Status</th><th class="text-end no-print">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge bg-light text-dark"><?= e($r['company_code']) ?></span></td>
          <td class="mono"><?= e($r['code']) ?></td>
          <td><strong><?= e($r['name']) ?></strong></td>
          <td><small><?= e($r['client_name']) ?></small></td>
          <td><small><?= e($r['location']) ?></small></td>
          <td class="text-end"><?= (int)$r['item_count'] ?></td>
          <td class="text-end fw-semibold"><?= e(fmt_qty($r['stock_qty'])) ?></td>
          <td class="text-end"><?= (int)$r['dn_count'] ?></td>
          <td><?= badge_for_status($r['status']) ?></td>
          <td class="text-end no-print text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('reports/inventory.php?project=' . (int)$r['id'])) ?>" target="_blank" title="Stock at this site"><i class="bi bi-box-seam"></i></a>
            <?php if ($canEdit): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('project_form.php?id=' . (int)$r['id'])) ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" data-confirm="Delete project <?= e($r['code']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-5">No projects yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
