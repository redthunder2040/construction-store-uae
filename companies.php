<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_companies');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    $id = (int)post('id');
    $co = fetch_one('SELECT * FROM companies WHERE id = ?', [$id]);
    if ($co) {
        $items = (int)fetch_val('SELECT COUNT(*) FROM items WHERE company_id = ?', [$id]);
        if ($items) {
            flash('Company <strong>' . e($co['name']) . '</strong> still has ' . $items
                . ' item(s). Deactivate it instead of deleting, or move the data first.', 'warning');
        } else {
            q('DELETE FROM companies WHERE id = ?', [$id]);
            audit('delete', 'companies', $id, $co['name']);
            flash('Company <strong>' . e($co['name']) . '</strong> deleted.', 'success');
        }
    }
    redirect('companies.php');
}

$rows = fetch_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM items i WHERE i.company_id = c.id) AS items,
            (SELECT COUNT(*) FROM projects p WHERE p.company_id = c.id) AS projects,
            (SELECT COUNT(*) FROM suppliers s WHERE s.company_id = c.id) AS suppliers,
            (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS users,
            (SELECT COALESCE(SUM(i.quantity*i.unit_cost),0) FROM items i WHERE i.company_id = c.id) AS stock_value
     FROM companies c ORDER BY c.name");

if (get('export')) {
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['code' => $r['code'], 'name' => $r['name'], 'address' => $r['address'], 'phone' => $r['phone'],
                  'email' => $r['email'], 'trn' => $r['trn'], 'items' => $r['items'], 'projects' => $r['projects'],
                  'suppliers' => $r['suppliers'], 'users' => $r['users'], 'stock_value' => fmt_money($r['stock_value']),
                  'letterhead' => $r['letterhead'] ? 'uploaded' : 'not set',
                  'status' => (int)$r['active'] === 1 ? 'Active' : 'Inactive'];
    }
    $headers = ['Code', 'Company', 'Address', 'Phone', 'Email', 'TRN', 'Items', 'Projects', 'Suppliers',
                'Users', 'Stock value', 'Letterhead', 'Status'];
    export_xls('companies', 'Companies', $headers, $out, null,
        ['Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)]);
}

$page_title = 'Companies';
$active_nav = 'companies.php';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Companies</h1>
    <p class="text-muted mb-0"><?= count($rows) ?> company record(s) — each keeps its own items, projects, suppliers and movements</p>
  </div>
  <div class="d-flex gap-2 no-print">
    <?php if (can('export_data')): ?>
      <a class="btn btn-outline-secondary" href="?export=xls"><i class="bi bi-file-earmark-excel"></i> Excel</a>
    <?php endif; ?>
    <a class="btn btn-brand" href="<?= e(url('company_form.php')) ?>"><i class="bi bi-plus-lg"></i> New company</a>
  </div>
</div>

<div class="card app-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Code</th><th>Company</th><th>Contact</th><th class="text-end">Items</th>
        <th class="text-end">Projects</th><th class="text-end">Suppliers</th><th class="text-end">Users</th>
        <th class="text-end">Stock value</th><th>Letterhead</th><th>Status</th><th class="text-end no-print">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge bg-light text-dark mono"><?= e($r['code']) ?></span></td>
          <td><strong><?= e($r['name']) ?></strong><div class="small text-muted"><?= e($r['address']) ?></div></td>
          <td><small><?= e($r['phone']) ?><br><?= e($r['email']) ?><?php if ($r['trn']): ?><br>TRN: <?= e($r['trn']) ?><?php endif; ?></small></td>
          <td class="text-end"><?= (int)$r['items'] ?></td>
          <td class="text-end"><?= (int)$r['projects'] ?></td>
          <td class="text-end"><?= (int)$r['suppliers'] ?></td>
          <td class="text-end"><?= (int)$r['users'] ?></td>
          <td class="text-end fw-semibold"><?= e(fmt_money($r['stock_value'])) ?></td>
          <td><?= $r['letterhead'] ? '<span class="badge bg-success">Uploaded</span>' : '<span class="badge bg-secondary">Not set</span>' ?></td>
          <td><?= (int)$r['active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-end no-print text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('company_form.php?id=' . (int)$r['id'])) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Delete company <?= e($r['name']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
