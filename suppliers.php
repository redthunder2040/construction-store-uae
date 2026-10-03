<?php
require_once __DIR__ . '/helpers.php';
require_login();

$scope   = scope_company_id();
$canEdit = can('manage_suppliers');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    require_can('manage_suppliers');
    $id = (int)post('id');
    $s  = fetch_one('SELECT * FROM suppliers WHERE id = ?', [$id]);
    if ($s) {
        guard_company((int)$s['company_id']);
        $used = (int)fetch_val('SELECT COUNT(*) FROM movements WHERE supplier_id = ?', [$id]);
        if ($used) {
            q('UPDATE suppliers SET active = 0 WHERE id = ?', [$id]);
            flash('Supplier <strong>' . e($s['name']) . '</strong> is used by ' . $used
                . ' movement(s) — it was deactivated instead of deleted.', 'warning');
        } else {
            q('DELETE FROM suppliers WHERE id = ?', [$id]);
            audit('delete', 'suppliers', $id, $s['name']);
            flash('Supplier <strong>' . e($s['name']) . '</strong> deleted.', 'success');
        }
    }
    redirect('suppliers.php');
}

$f_co = $scope ?: (int)get('company');
$w    = $f_co ? ' AND s.company_id = ' . (int)$f_co : '';

$rows = fetch_all(
    "SELECT s.*, co.code AS company_code,
            (SELECT COUNT(*) FROM movements m WHERE m.supplier_id = s.id) AS mv_count,
            (SELECT COUNT(*) FROM items i WHERE i.supplier_id = s.id) AS item_count
     FROM suppliers s JOIN companies co ON co.id = s.company_id
     WHERE 1=1 $w ORDER BY co.code, s.name");

if (get('export')) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['company_code' => $r['company_code'], 'code' => $r['code'], 'name' => $r['name'],
                  'contact_person' => $r['contact_person'], 'phone' => $r['phone'], 'email' => $r['email'],
                  'address' => $r['address'], 'trn' => $r['trn'], 'items' => $r['item_count'],
                  'movements' => $r['mv_count'], 'status' => (int)$r['active'] === 1 ? 'Active' : 'Inactive'];
    }
    $headers = ['Company', 'Code', 'Supplier', 'Contact person', 'Phone', 'E-mail', 'Address', 'TRN',
                'Preferred items', 'Movements', 'Status'];
    export_xls('suppliers', 'Suppliers', $headers, $out, null,
        ['Company' => $f_co ? company_name($f_co) : 'All companies',
         'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)]);
}

$page_title = 'Suppliers';
$active_nav = 'suppliers.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Suppliers</h1>
    <p class="text-muted mb-0"><?= count($rows) ?> supplier(s) across the selected scope</p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (can('export_data')): ?>
      <a class="btn btn-outline-secondary" href="?export=xls&company=<?= (int)$f_co ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <a class="btn btn-brand" href="<?= e(url('supplier_form.php')) ?>"><i class="bi bi-plus-lg"></i> New supplier</a>
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
      <thead><tr><th>Company</th><th>Code</th><th>Supplier</th><th>Contact</th><th>Phone / e-mail</th>
        <th>Address</th><th class="text-end">Preferred items</th><th class="text-end">Movements</th><th>Status</th>
        <th class="text-end no-print">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge bg-light text-dark"><?= e($r['company_code']) ?></span></td>
          <td class="mono"><?= e($r['code']) ?></td>
          <td><strong><?= e($r['name']) ?></strong><?php if ($r['trn']): ?><div class="small text-muted">TRN <?= e($r['trn']) ?></div><?php endif; ?></td>
          <td><small><?= e($r['contact_person']) ?></small></td>
          <td><small><?= e($r['phone']) ?><br><?= e($r['email']) ?></small></td>
          <td><small><?= e($r['address']) ?></small></td>
          <td class="text-end"><?= (int)$r['item_count'] ?></td>
          <td class="text-end"><?= (int)$r['mv_count'] ?></td>
          <td><?= (int)$r['active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-end no-print text-nowrap">
            <?php if ($canEdit): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('supplier_form.php?id=' . (int)$r['id'])) ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" data-confirm="Delete supplier <?= e($r['name']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-5">No suppliers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
