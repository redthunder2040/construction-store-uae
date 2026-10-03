<?php
/** AJAX stock lookup for a single item at one location. */
require_once __DIR__ . '/helpers.php';
require_login();
header('Content-Type: application/json');

$itemId = (int)get('item_id');
$projId = (int)get('project_id');

$item = fetch_one('SELECT * FROM items WHERE id = ?', [$itemId]);
if (!$item) { echo json_encode(['error' => 'Item not found.']); exit; }
if (scope_company_id() !== null && (int)$item['company_id'] !== scope_company_id()) {
    echo json_encode(['error' => 'Item belongs to another company.']); exit;
}

$loc = fetch_one('SELECT quantity FROM stock_locations WHERE item_id = ? AND project_id = ?', [$itemId, $projId]);

echo json_encode([
    'powered_by'    => RT_ATTRIBUTION_TEXT,
    'item_code'     => $item['item_code'],
    'uom'           => $item['uom'],
    'location'      => $projId ? project_name($projId) : 'Main Store / Central Warehouse',
    'available'     => fmt_qty($loc ? $loc['quantity'] : 0),
    'total'         => fmt_qty($item['quantity']),
    'reorder_level' => fmt_qty($item['reorder_level']),
    'unit_cost'     => fmt_money($item['unit_cost']),
]);
