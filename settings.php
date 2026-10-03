<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('manage_items');

$errors  = [];
$tab     = get('tab', 'categories');

/* ----------------------------------------------------------------- actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('action');

    if ($act === 'cat_add') {
        $nm = post('name');
        if ($nm === '') { $errors[] = 'Category name is required.'; }
        elseif (fetch_val('SELECT id FROM categories WHERE name = ?', [$nm])) { $errors[] = 'That category already exists.'; }
        else {
            q('INSERT INTO categories (name, description) VALUES (?,?)', [$nm, post('description')]);
            audit('insert', 'categories', (int)db()->lastInsertId(), $nm);
            flash('Category <strong>' . e($nm) . '</strong> added.', 'success');
        }
    } elseif ($act === 'cat_update') {
        $id = (int)post('id');
        q('UPDATE categories SET name = ?, description = ? WHERE id = ?', [post('name'), post('description'), $id]);
        audit('update', 'categories', $id, post('name'));
        flash('Category updated.', 'success');
    } elseif ($act === 'cat_delete') {
        $id = (int)post('id');
        $used = (int)fetch_val('SELECT COUNT(*) FROM items WHERE category_id = ?', [$id]);
        if ($used) { flash('That category is used by ' . $used . ' item(s) and cannot be deleted.', 'warning'); }
        else {
            q('DELETE FROM categories WHERE id = ?', [$id]);
            audit('delete', 'categories', $id);
            flash('Category deleted.', 'success');
        }
    } elseif ($act === 'uom_add') {
        $code = strtoupper(post('code'));
        $nm   = post('name');
        if ($code === '' || $nm === '') { $errors[] = 'UOM code and name are both required.'; }
        elseif (fetch_val('SELECT id FROM uoms WHERE code = ?', [$code])) { $errors[] = 'That UOM code already exists.'; }
        else {
            q('INSERT INTO uoms (code, name) VALUES (?,?)', [$code, $nm]);
            audit('insert', 'uoms', (int)db()->lastInsertId(), $code);
            flash('Unit of measure <strong>' . e($code) . '</strong> added.', 'success');
        }
    } elseif ($act === 'uom_update') {
        $id = (int)post('id');
        $old = (string)fetch_val('SELECT code FROM uoms WHERE id = ?', [$id]);
        $new = strtoupper(post('code'));
        q('UPDATE uoms SET code = ?, name = ? WHERE id = ?', [$new, post('name'), $id]);
        if ($old !== '' && $old !== $new) {
            q('UPDATE items SET uom = ? WHERE uom = ?', [$new, $old]);
        }
        audit('update', 'uoms', $id, $old . ' → ' . $new);
        flash('Unit of measure updated.', 'success');
    } elseif ($act === 'uom_delete') {
        $id  = (int)post('id');
        $cod = (string)fetch_val('SELECT code FROM uoms WHERE id = ?', [$id]);
        $used = (int)fetch_val('SELECT COUNT(*) FROM items WHERE uom = ?', [$cod]);
        if ($used) { flash('UOM <strong>' . e($cod) . '</strong> is used by ' . $used . ' item(s) and cannot be deleted.', 'warning'); }
        else {
            q('DELETE FROM uoms WHERE id = ?', [$id]);
            audit('delete', 'uoms', $id, $cod);
            flash('Unit of measure deleted.', 'success');
        }
    }
    if (!$errors) { redirect('settings.php?tab=' . ($act[0] === 'u' ? 'uoms' : 'categories')); }
}

$cats = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS items
                   FROM categories c ORDER BY c.name');
$uoms = fetch_all('SELECT u.*, (SELECT COUNT(*) FROM items i WHERE i.uom = u.code) AS items
                   FROM uoms u ORDER BY u.code');

$page_title = 'Categories & UOM';
$active_nav = 'settings.php';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Categories &amp; Units of Measure</h1>
    <p class="text-muted mb-0"><?= count($cats) ?> categories &middot; <?= count($uoms) ?> units of measure used across all companies</p>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle"></i> <?= $err ?></div>
<?php endforeach; ?>

<ul class="nav nav-pills mb-3 no-print">
  <li class="nav-item"><a class="nav-link <?= $tab === 'categories' ? 'active' : '' ?>" href="?tab=categories"><i class="bi bi-tags"></i> Categories</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'uoms' ? 'active' : '' ?>" href="?tab=uoms"><i class="bi bi-rulers"></i> Units of measure</a></li>
</ul>

<?php if ($tab === 'categories'): ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add category</h5></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="cat_add">
          <div class="mb-3"><label class="form-label required">Name</label>
            <input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Description</label>
            <input type="text" name="description" class="form-control"></div>
          <button class="btn btn-brand w-100"><i class="bi bi-save"></i> Add category</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card app-card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>#</th><th>Category</th><th>Description</th><th class="text-end">Items</th><th class="text-end no-print">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($cats as $i => $c): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <form method="post" class="d-flex gap-2">
                  <?= csrf_field() ?><input type="hidden" name="action" value="cat_update"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <input type="text" name="name" class="form-control form-control-sm" value="<?= e($c['name']) ?>">
              </td>
              <td><input type="text" name="description" class="form-control form-control-sm" value="<?= e($c['description']) ?>"></td>
              <td class="text-end"><?= (int)$c['items'] ?></td>
              <td class="text-end text-nowrap no-print">
                  <button class="btn btn-sm btn-outline-primary" title="Save"><i class="bi bi-check2"></i></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Delete category <?= e($c['name']) ?>?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="cat_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card app-card">
      <div class="card-header"><h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add unit of measure</h5></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="uom_add">
          <div class="mb-3"><label class="form-label required">Code</label>
            <input type="text" name="code" class="form-control mono" placeholder="e.g. BAG" required maxlength="20"></div>
          <div class="mb-3"><label class="form-label required">Description</label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Bag (50 kg)" required></div>
          <button class="btn btn-brand w-100"><i class="bi bi-save"></i> Add UOM</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card app-card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th style="width:60px">#</th><th style="width:130px">Code</th><th>Description</th>
            <th class="text-end">Items</th><th class="text-end no-print">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($uoms as $i => $u): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td>
                <form method="post" class="d-flex gap-2">
                  <?= csrf_field() ?><input type="hidden" name="action" value="uom_update"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="text" name="code" class="form-control form-control-sm mono" value="<?= e($u['code']) ?>">
              </td>
              <td><input type="text" name="name" class="form-control form-control-sm" value="<?= e($u['name']) ?>"></td>
              <td class="text-end"><?= (int)$u['items'] ?></td>
              <td class="text-end text-nowrap no-print">
                  <button class="btn btn-sm btn-outline-primary" title="Save"><i class="bi bi-check2"></i></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Delete UOM <?= e($u['code']) ?>?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="uom_delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="alert alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i>
      Renaming a UOM code also updates every item that uses it, so historical movements stay consistent.</div>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
