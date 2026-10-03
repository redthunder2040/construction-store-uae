<?php
require_once __DIR__ . '/helpers.php';
require_login();
require_can('view_reports');

$id = (int)get_num('id');
$dn = fetch_one('SELECT * FROM delivery_notes WHERE id = ?', [$id]);
if (!$dn) { flash('Delivery note not found.', 'danger'); redirect('delivery_notes.php'); }
guard_company((int)$dn['company_id']);

$company = fetch_one('SELECT * FROM companies WHERE id = ?', [(int)$dn['company_id']]);
$project = fetch_one('SELECT * FROM projects WHERE id = ?', [(int)$dn['project_id']]);
$lines   = fetch_all('SELECT di.*, i.item_code, i.item_name, i.unit_cost, i.uom AS item_uom
                      FROM delivery_note_items di JOIN items i ON i.id = di.item_id
                      WHERE di.dn_id = ? ORDER BY di.id', [$id]);
$preparer = (string)fetch_val('SELECT full_name FROM users WHERE id = ?', [(int)$dn['prepared_by']]);

$totQty = 0.0;
$totVal = 0.0;
foreach ($lines as $l) { $totQty += (float)$l['quantity']; $totVal += (float)$l['quantity'] * (float)$l['unit_cost']; }

/* --------------------------------------------------------------- Excel/CSV */
if (get('export')) {
    require_can('export_data');
    $headers = ['#', 'Item code', 'Description', 'UOM', 'Quantity', 'Unit cost', 'Amount', 'Remarks'];
    $rows = [];
    $i = 0;
    foreach ($lines as $l) {
        $i++;
        $rows[] = ['no' => $i, 'code' => $l['item_code'], 'name' => $l['item_name'], 'uom' => $l['item_uom'],
                   'qty' => fmt_qty($l['quantity']), 'cost' => fmt_money($l['unit_cost']),
                   'amount' => fmt_money((float)$l['quantity'] * (float)$l['unit_cost']), 'remarks' => $l['remarks']];
    }
    $meta = ['Document' => 'Delivery Note ' . $dn['dn_no'], 'Company' => $company['name'],
             'Date' => fmt_date($dn['dn_date']), 'Deliver to' => $project ? $project['code'] . ' — ' . $project['name'] : '',
             'Issued to' => $dn['issued_to'], 'Total quantity' => fmt_qty($totQty),
             'Total value' => APP_CURRENCY . ' ' . fmt_money($totVal)];
    if (get('export') === 'csv') { export_csv('DN_' . $dn['dn_no'], $headers, $rows); }
    export_xls('DN_' . $dn['dn_no'], 'Delivery Note ' . $dn['dn_no'], $headers, $rows, null, $meta);
}

$page_title = 'Delivery note ' . $dn['dn_no'];
include __DIR__ . '/includes/header.php';

$meta = [
    'DN No.'   => $dn['dn_no'],
    'Date'     => fmt_date($dn['dn_date']),
    'Status'   => ucfirst($dn['status']),
    'Company'  => $company['code'] . ' — ' . $company['name'],
    'Deliver from' => (int)$dn['from_project_id'] ? project_name((int)$dn['from_project_id']) : 'Main Store / Central Warehouse',
    'Deliver to'   => $project ? $project['code'] . ' — ' . $project['name'] : '—',
    'Client'       => $project['client_name'] ?? '',
    'Site location'=> $project['location'] ?? '',
    'Issued to'    => $dn['issued_to'],
    'Vehicle no.'  => $dn['vehicle_no'],
    'Driver'       => trim($dn['driver_name'] . ' ' . $dn['driver_contact']),
    'Prepared by'  => $preparer,
];
?>

<div class="page-head no-print">
  <div>
    <h1>Delivery Note <?= e($dn['dn_no']) ?></h1>
    <p class="text-muted mb-0"><?= e(badge_for_status($dn['status'])) ?> &middot;
      <?= count($lines) ?> line(s) &middot; <?= e(fmt_qty($totQty)) ?> units &middot;
      <?= e(APP_CURRENCY) ?> <?= e(fmt_money($totVal)) ?></p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-brand" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>
    <?php if (can('export_data')): ?>
      <a class="btn btn-outline-secondary" href="?id=<?= (int)$dn['id'] ?>&export=xls"><i class="bi bi-file-earmark-excel"></i> Excel</a>
      <a class="btn btn-outline-secondary" href="?id=<?= (int)$dn['id'] ?>&export=csv"><i class="bi bi-filetype-csv"></i> CSV</a>
    <?php endif; ?>
    <?php if (can('create_delivery_note')): ?>
      <a class="btn btn-outline-primary" href="<?= e(url('delivery_note_form.php?id=' . (int)$dn['id'])) ?>"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(url('delivery_notes.php')) ?>"><i class="bi bi-arrow-left"></i> Register</a>
  </div>
</div>

<div class="print-doc">
  <?= print_header($company, 'Delivery Note', $meta) ?>

  <table class="table table-bordered table-sm doc-table mt-3">
    <thead>
      <tr>
        <th style="width:44px">#</th>
        <th style="width:110px">Item code</th>
        <th>Description</th>
        <th style="width:60px">UOM</th>
        <th class="text-end" style="width:90px">Quantity</th>
        <th class="text-end" style="width:90px">Unit cost</th>
        <th class="text-end" style="width:100px">Amount</th>
        <th style="width:150px">Remarks</th>
      </tr>
    </thead>
    <tbody>
    <?php $n = 0; foreach ($lines as $l): $n++; ?>
      <tr>
        <td><?= $n ?></td>
        <td class="mono"><?= e($l['item_code']) ?></td>
        <td><?= e($l['item_name']) ?></td>
        <td><?= e($l['item_uom']) ?></td>
        <td class="text-end"><?= e(fmt_qty($l['quantity'])) ?></td>
        <td class="text-end"><?= e(fmt_money($l['unit_cost'])) ?></td>
        <td class="text-end"><?= e(fmt_money((float)$l['quantity'] * (float)$l['unit_cost'])) ?></td>
        <td><small><?= e($l['remarks']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($i = $n; $i < max($n, 8); $i++): ?>
      <tr class="empty-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td colspan="4" class="text-end">Total</td>
        <td class="text-end"><?= e(fmt_qty($totQty)) ?></td>
        <td></td>
        <td class="text-end"><?= e(fmt_money($totVal)) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>

  <?php if ($dn['notes']): ?>
    <p class="small mb-3"><strong>Notes:</strong> <?= e($dn['notes']) ?></p>
  <?php endif; ?>

  <div class="sig-row">
    <div class="sig-box">Prepared by (Store Keeper)<br><small><?= e($preparer) ?></small></div>
    <div class="sig-box">Checked by (Store Manager)</div>
    <div class="sig-box">Received by (Site Representative)<br><small><?= e($dn['issued_to']) ?></small></div>
    <div class="sig-box">Date &amp; Stamp</div>
  </div>

  <div class="doc-foot">
    This delivery note is the official record of materials issued to site. Please retain one signed copy at the store
    and one at the site office. Lorry / vehicle: <?= e($dn['vehicle_no']) ?>
    <?= $dn['driver_name'] ? ' | Driver: ' . e($dn['driver_name']) : '' ?><?= $dn['driver_contact'] ? ' (' . e($dn['driver_contact']) . ')' : '' ?>.
    Printed on <?= e(date('d-M-Y H:i')) ?> by <?= e(current_user()['username']) ?>.
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
