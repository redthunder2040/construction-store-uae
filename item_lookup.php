<?php
/** AJAX item search used by the item pickers. */
require_once __DIR__ . '/helpers.php';
require_login();
header('Content-Type: application/json');

$term    = get('term');
$scope   = scope_company_id();
$params  = [];
$sql = 'SELECT i.id, i.item_code, i.item_name, i.uom, i.quantity, i.unit_cost, i.project_id, i.reorder_level,
               c.name AS category
        FROM items i LEFT JOIN categories c ON c.id = i.category_id
        WHERE i.active = 1';
if ($scope) { $sql .= ' AND i.company_id = ?'; $params[] = $scope; }
if ($term !== '') {
    $sql .= ' AND (i.item_code LIKE ? OR i.item_name LIKE ?)';
    $params[] = '%' . $term . '%';
    $params[] = '%' . $term . '%';
}
$sql .= ' ORDER BY i.item_code LIMIT 25';

$out = [];
foreach (fetch_all($sql, $params) as $r) {
    $r['quantity']   = fmt_qty($r['quantity']);
    $r['unit_cost']  = fmt_money($r['unit_cost']);
    $r['item_name']  = $r['item_name'];
    $out[] = $r;
}
echo json_encode(['items' => $out, 'powered_by' => RT_ATTRIBUTION_TEXT]);
