<?php
/** Item stock card / ledger — every transaction of one item with a running balance. */
require_once __DIR__ . '/../helpers.php';
require_login();
require_can('view_reports');

$scope  = scope_company_id();
$itemId = (int)get('item');
$f_from = get('from');
$f_to   = get('to', date('Y-m-d'));

$item = $itemId ? fetch_one('SELECT i.*, c.name AS category, co.code AS company_code, co.name AS company_name,
                                    p.code AS project_code, p.name AS project_name
                             FROM items i
                             LEFT JOIN categories c ON c.id = i.category_id
                             JOIN companies co ON co.id = i.company_id
                             LEFT JOIN projects p ON p.id = i.project_id
                             WHERE i.id = ?', [$itemId]) : null;
if ($item && $scope) { guard_company((int)$item['company_id']); }

$items = items_for_select($scope ?: null);

$rows = [];
$opening = 0.0;
$closing = 0.0;
if ($item) {
    $args = [$itemId];
    $sql = 'SELECT m.*, u.username, s.name AS supplier_name, d.dn_no, p.code AS project_code
            FROM movements m
            LEFT JOIN users u ON u.id = m.created_by
            LEFT JOIN suppliers s ON s.id = m.supplier_id
            LEFT JOIN delivery_notes d ON d.id = m.dn_id
            LEFT JOIN projects p ON p.id = m.project_id
            WHERE m.item_id = ?';
    if ($f_to !== '' && is_dob($f_to)) { $sql .= ' AND m.movement_date <= ?'; $args[] = $f_to; }
    $sql .= ' ORDER BY m.movement_date ASC, m.id ASC';
    $all = fetch_all($sql, $args);

    $balance = 0.0;
    foreach ($all as $r) {
        $in  = in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true);
        $bal = $in ? $balance + (float)$r['quantity'] : $balance - (float)$r['quantity'];
        if ($f_from !== '' && is_dob($f_from) && $r['movement_date'] < $f_from) {
            $opening = $bal;
        } else {
            $r['running'] = $bal;
            $rows[] = $r;
        }
        $balance = $bal;
    }
    $closing = $balance;
}

/* ------------------------------------------------------------------ export */
if (get('export') && $item) {
    require_can('export_data');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['movement_date' => $r['movement_date'], 'movement_type' => $r['movement_type'],
                  'reference' => $r['reference'], 'dn_no' => $r['dn_no'],
                  'qty_in' => in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true) ? fmt_qty($r['quantity']) : '',
                  'qty_out' => !in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true) ? fmt_qty($r['quantity']) : '',
                  'uom' => $r['uom'], 'balance' => fmt_qty($r['running']),
                  'unit_cost' => fmt_money($r['unit_cost']),
                  'value' => fmt_money((float)$r['quantity'] * (float)$r['unit_cost']),
                  'location' => $r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
                        : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id'])),
                  'supplier' => $r['supplier_name'], 'user' => $r['username'], 'notes' => $r['notes']];
    }
    $headers = ['Date', 'Type', 'Reference', 'DN No.', 'Qty in', 'Qty out', 'UOM', 'Balance', 'Unit cost',
                'Value', 'Location', 'Supplier', 'User', 'Notes'];
    $meta = ['Report' => 'Item stock card (ledger)', 'Item' => $item['item_code'] . ' — ' . $item['item_name'],
             'Company' => $item['company_name'], 'UOM' => $item['uom'],
             'Opening balance' => fmt_qty($opening), 'Closing balance' => fmt_qty($closing),
             'Period' => ($f_from ?: 'start') . ' to ' . ($f_to ?: 'today'),
             'Generated' => date('d-M-Y H:i') . ' by ' . current_user()['username'], 'Rows' => count($out)];
    if (get('export') === 'csv') { export_csv('stock_card_' . $item['item_code'], $headers, $out); }
    export_xls('stock_card_' . $item['item_code'], 'Stock Card — ' . $item['item_code'], $headers, $out, null, $meta);
}

$page_title = $item ? 'Stock card ' . $item['item_code'] : 'Item stock card';
include __DIR__ . '/../includes/dn_head.php';
?>
<div class="print-doc">
  <form class="row g-2 align-items-end no-print mb-3" method="get">
    <div class="col-md-4">
      <label class="form-label">Item</label>
      <select name="item" class="form-select form-select-sm" required>
        <option value="">— select an item —</option>
        <?php foreach ($items as $i): ?>
          <option value="<?= (int)$i['id'] ?>" <?= $itemId === (int)$i['id'] ? 'selected' : '' ?>>
            <?= e($i['item_code'] . ' — ' . $i['item_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
    </div>
    <div class="col-md-2">
      <button class="btn btn-brand btn-sm w-100"><i class="bi bi-funnel"></i> Show ledger</button>
    </div>
  </form>

  <?php if (!$item): ?>
    <div class="alert alert-info">Select an item above to display its stock card.</div>
  <?php else:
    $head = ['id' => 0, 'name' => $item['company_name'], 'address' => 'Stock ledger — ' . $item['company_name'],
             'phone' => '', 'email' => '', 'trn' => ''];
    echo print_header($head, 'Item Stock Card', [
        'Item code'   => $item['item_code'],
        'Description' => $item['item_name'],
        'Category'    => (string)$item['category'],
        'UOM'         => $item['uom'],
        'Store / project' => $item['project_name'] ? $item['project_code'] . ' — ' . $item['project_name'] : 'Main Store',
        'Period'      => ($f_from ?: 'start') . ' to ' . ($f_to ?: 'today'),
        'Reorder level' => fmt_qty($item['reorder_level']),
        'Unit cost'   => APP_CURRENCY . ' ' . fmt_money($item['unit_cost']),
        'On hand now' => fmt_qty($item['quantity']) . ' ' . $item['uom'],
        'Closing (period)' => fmt_qty($closing),
        'Stock value' => APP_CURRENCY . ' ' . fmt_money((float)$item['quantity'] * (float)$item['unit_cost']),
    ]);
  ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr>
        <th style="width:78px">Date</th><th style="width:84px">Type</th><th>Reference / document</th>
        <th class="text-end" style="width:78px">Qty in</th><th class="text-end" style="width:78px">Qty out</th>
        <th style="width:44px">UOM</th><th class="text-end" style="width:88px">Balance</th>
        <th style="width:110px">Location</th><th>Supplier / notes</th><th style="width:70px">User</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($f_from): ?>
      <tr class="table-light">
        <td colspan="6" class="text-end fw-semibold">Opening balance at <?= e(fmt_date($f_from)) ?></td>
        <td class="text-end fw-semibold"><?= e(fmt_qty($opening)) ?></td>
        <td colspan="3"></td>
      </tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $in = in_array($r['movement_type'], ['IN', 'TRANSFER_IN'], true); ?>
        <tr>
          <td class="text-nowrap"><?= e(date('d-m-Y', strtotime($r['movement_date']))) ?></td>
          <td><?= e(str_replace('_', ' ', $r['movement_type'])) ?></td>
          <td><small><span class="mono"><?= e($r['reference']) ?></span><?= $r['dn_no'] ? ' (' . e($r['dn_no']) . ')' : '' ?></small></td>
          <td class="text-end"><?= $in ? e(fmt_qty($r['quantity'])) : '' ?></td>
          <td class="text-end"><?= !$in ? e(fmt_qty($r['quantity'])) : '' ?></td>
          <td><?= e($r['uom']) ?></td>
          <td class="text-end fw-semibold"><?= e(fmt_qty($r['running'])) ?></td>
          <td><small><?= e($r['movement_type'] === 'TRANSFER_OUT' ? project_name((int)$r['from_project_id'])
                : ($r['movement_type'] === 'TRANSFER_IN' ? project_name((int)$r['to_project_id']) : project_name((int)$r['project_id']))) ?></small></td>
          <td><small><?= e($r['supplier_name']) ?><?= $r['notes'] ? ' | ' . e($r['notes']) : '' ?></small></td>
          <td><small><?= e($r['username']) ?></small></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">No movements for this item in the selected period.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold table-light">
        <td colspan="6" class="text-end">Closing balance</td>
        <td class="text-end"><?= e(fmt_qty($closing)) ?></td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>

  <div class="sig-row">
    <div class="sig-box">Store Keeper</div>
    <div class="sig-box">Store Manager</div>
    <div class="sig-box">Auditor / Checked by</div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/dn_foot.php'; ?>
