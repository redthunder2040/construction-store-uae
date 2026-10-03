<?php
/**
 * Shared helpers: database, RBAC, validation, stock engine, audit trail, export.
 * PHP 7.4+ / 8.x — no external dependencies.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/powered_by.php';   // mandatory attribution component

/* ------------------------------------------------------------------ database */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('<h3 style="font-family:sans-serif">Database connection failed</h3>'
                . '<p style="font-family:sans-serif">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p style="font-family:sans-serif">Check the credentials in <code>config.php</code> '
                . 'and make sure the <code>' . DB_NAME . '</code> database has been imported.</p>'
                . '<p style="font-family:sans-serif;font-size:.8rem;color:#6b7a8d;margin-top:16px">'
                . RT_ATTRIBUTION_TEXT . '</p>');
        }
    }
    return $pdo;
}

/** Run a prepared statement and return the PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function fetch_all(string $sql, array $params = []): array   { return q($sql, $params)->fetchAll(); }
function fetch_one(string $sql, array $params = [])          { $r = q($sql, $params)->fetch(); return $r ?: null; }
function fetch_val(string $sql, array $params = [])          { $v = q($sql, $params)->fetchColumn(); return $v === false ? null : $v; }
function exec_sql(string $sql, array $params = []): int      { return q($sql, $params)->rowCount(); }

/* ------------------------------------------------------------------- output */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function url(string $path = ''): string { return APP_BASE_URL . '/' . ltrim($path, '/'); }

function redirect(string $path): void { header('Location: ' . url($path)); exit; }

function flash(string $msg = null, string $type = 'success')
{
    if ($msg !== null) { $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function fmt_qty($v, int $dp = 3): string
{
    $v = (float)$v;
    $s = number_format($v, $dp, '.', ',');
    return rtrim(rtrim($s, '0'), '.') ?: '0';
}

function fmt_money($v, int $dp = 2): string { return number_format((float)$v, $dp, '.', ','); }

function fmt_date($d): string { return $d ? date('d-M-Y', strtotime($d)) : '—'; }

function fmt_dt($d): string { return $d ? date('d-M-Y H:i', strtotime($d)) : '—'; }

function badge_for_status(string $s): string
{
    $map = ['draft' => 'secondary', 'issued' => 'primary', 'received' => 'success',
            'cancelled' => 'danger', 'active' => 'success', 'completed' => 'dark', 'on_hold' => 'warning'];
    return '<span class="badge bg-' . ($map[$s] ?? 'secondary') . '">' . e(ucfirst(str_replace('_', ' ', $s))) . '</span>';
}

function badge_for_role(string $r): string
{
    $map = ['admin' => 'danger', 'store_manager' => 'primary', 'store_keeper' => 'success'];
    $lbl = ['admin' => 'Administrator', 'store_manager' => 'Store Manager', 'store_keeper' => 'Store Keeper'];
    return '<span class="badge bg-' . ($map[$r] ?? 'secondary') . '">' . e($lbl[$r] ?? $r) . '</span>';
}

function badge_for_movement(string $t): string
{
    $map = ['IN' => 'success', 'OUT' => 'danger', 'TRANSFER_IN' => 'info',
            'TRANSFER_OUT' => 'warning', 'ADJUST' => 'secondary'];
    return '<span class="badge bg-' . ($map[$t] ?? 'secondary') . '">' . e(str_replace('_', ' ', $t)) . '</span>';
}

/* --------------------------------------------------------------------- CSRF */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(24)); }
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
        if (!$ok) {
            http_response_code(419);
            exit('<p>Security token expired. Please go back and retry.</p>'
                . '<p style="font-size:.8rem;color:#6b7a8d">' . RT_ATTRIBUTION_TEXT . '</p>');
        }
    }
}

/* --------------------------------------------------------------------- auth */
function current_user(): ?array
{
    static $u = null;
    if ($u !== null) return $u;
    if (empty($_SESSION['user_id'])) return null;
    $u = fetch_one('SELECT u.*, c.name AS company_name, c.code AS company_code
                    FROM users u LEFT JOIN companies c ON c.id = u.company_id
                    WHERE u.id = ? AND u.active = 1', [(int)$_SESSION['user_id']]);
    return $u ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) { redirect('login.php'); }
    return $u;
}

/** Roles: admin | store_manager | store_keeper */
function user_role(): string { return current_user()['role'] ?? 'guest'; }

function is_admin(): bool { return user_role() === 'admin'; }

function can(string $ability): bool
{
    $role = user_role();
    $matrix = [
        // ability                => allowed roles
        'manage_companies'      => ['admin'],
        'manage_users'          => ['admin'],
        'manage_projects'       => ['admin', 'store_manager'],
        'manage_suppliers'      => ['admin', 'store_manager'],
        'manage_items'          => ['admin', 'store_manager'],
        'delete_items'          => ['admin', 'store_manager'],
        'record_movement'       => ['admin', 'store_manager', 'store_keeper'],
        'delete_movement'       => ['admin', 'store_manager'],
        'manage_transfer'       => ['admin', 'store_manager', 'store_keeper'],
        'create_delivery_note'  => ['admin', 'store_manager', 'store_keeper'],
        'approve_delivery_note' => ['admin', 'store_manager'],
        'delete_delivery_note'  => ['admin', 'store_manager'],
        'view_reports'          => ['admin', 'store_manager', 'store_keeper'],
        'export_data'           => ['admin', 'store_manager'],
        'view_audit'            => ['admin'],
        'switch_company'        => ['admin'],
    ];
    return in_array($role, $matrix[$ability] ?? [], true);
}

function require_can(string $ability): void
{
    require_login();
    if (!can($ability)) {
        http_response_code(403);
        include __DIR__ . '/includes/header.php';
        echo '<div class="alert alert-danger"><strong>Access denied.</strong> Your role ('
            . e(user_role()) . ') is not allowed to perform this action.</div>';
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

/**
 * Company scope: an admin may work on any company (session switch),
 * everybody else is locked to the company on their user record.
 * Returns null for an admin with "All companies" selected.
 */
function scope_company_id(): ?int
{
    $u = current_user();
    if (!$u) return null;
    if ($u['role'] === 'admin') {
        $sel = $_SESSION['active_company'] ?? 'all';
        return ($sel === 'all' || $sel === null) ? null : (int)$sel;
    }
    return $u['company_id'] !== null ? (int)$u['company_id'] : null;
}

/** Guard: can this user touch a record belonging to $companyId? */
function guard_company(?int $companyId): void
{
    $scope = scope_company_id();
    if ($scope !== null && (int)$companyId !== $scope) {
        http_response_code(403);
        exit('<p>Record belongs to another company — access denied.</p>'
            . '<p style="font-size:.8rem;color:#6b7a8d">' . RT_ATTRIBUTION_TEXT . '</p>');
    }
}

function companies_for_user(): array
{
    if (is_admin()) return fetch_all('SELECT * FROM companies ORDER BY name');
    return fetch_all('SELECT * FROM companies WHERE id = ? ORDER BY name', [scope_company_id()]);
}

function company_name(?int $id): string
{
    if (!$id) return 'Main Store';
    static $cache = [];
    if (!isset($cache[$id])) {
        $cache[$id] = (string)fetch_val('SELECT name FROM companies WHERE id = ?', [$id]);
    }
    return $cache[$id] ?: ('Company #' . $id);
}

function project_name(?int $id): string
{
    if (!$id) return 'Main Store';
    static $cache = [];
    if (!isset($cache[$id])) {
        $cache[$id] = (string)fetch_val('SELECT CONCAT(code, " — ", name) FROM projects WHERE id = ?', [$id]);
    }
    return $cache[$id] ?: ('Project #' . $id);
}

/* ------------------------------------------------------------------- audit */
function audit(string $action, string $entity, ?int $entityId = null, $details = null): void
{
    $u = current_user();
    try {
        q('INSERT INTO audit_logs (user_id, username, company_id, action, entity, entity_id, details, ip_address)
           VALUES (?,?,?,?,?,?,?,?)', [
            $u['id'] ?? null,
            $u['username'] ?? 'guest',
            $u['company_id'] ?? null,
            strtoupper($action),
            $entity,
            $entityId,
            is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* auditing must never break the request */ }
}

/* ------------------------------------------------------- stock movement engine */
const MOVEMENT_DIRECTION = [
    'IN'           => '+1',
    'OUT'          => '-1',
    'ADJUST'       => 'set',
    'TRANSFER_OUT' => '-1',
    'TRANSFER_IN'  => '+1',
];

/**
 * Apply a signed quantity change to one stock location (project_id = 0 → main store)
 * and keep items.quantity (the company-wide total) in sync.
 * Must be called inside a transaction.
 */
function apply_stock_change(int $itemId, int $companyId, int $projectId, float $delta): void
{
    // lock the item row
    $item = fetch_one('SELECT id, company_id, quantity FROM items WHERE id = ? FOR UPDATE', [$itemId]);
    if (!$item) throw new RuntimeException('Item #' . $itemId . ' not found.');
    if ((int)$item['company_id'] !== $companyId) throw new RuntimeException('Item/company mismatch.');

    $loc = fetch_one('SELECT id, quantity FROM stock_locations WHERE item_id = ? AND project_id = ? FOR UPDATE',
        [$itemId, $projectId]);
    $newLoc = ($loc ? (float)$loc['quantity'] : 0.0) + $delta;
    if ($newLoc < -0.0001) {
        throw new RuntimeException('Insufficient stock at ' . project_name($projectId)
            . '. Available: ' . fmt_qty(max(0, $loc ? $loc['quantity'] : 0)));
    }
    if ($newLoc < 0) $newLoc = 0;

    if ($loc) {
        q('UPDATE stock_locations SET quantity = ? WHERE id = ?', [round($newLoc, 3), $loc['id']]);
    } else {
        q('INSERT INTO stock_locations (item_id, company_id, project_id, quantity) VALUES (?,?,?,?)',
            [$itemId, $companyId, $projectId, round($newLoc, 3)]);
    }

    $newTotal = max(0, round((float)$item['quantity'] + $delta, 3));
    q('UPDATE items SET quantity = ?, updated_at = NOW() WHERE id = ?', [$newTotal, $itemId]);

    // keep items.project_id pointing at the location holding the most stock
    recalc_item_location($itemId);
}

/** Point items.project_id at the location that currently holds the largest quantity. */
function recalc_item_location(int $itemId): void
{
    $top = fetch_one('SELECT project_id FROM stock_locations WHERE item_id = ? AND quantity > 0.0001
                      ORDER BY quantity DESC LIMIT 1', [$itemId]);
    q('UPDATE items SET project_id = ? WHERE id = ?', [$top ? (int)$top['project_id'] : 0, $itemId]);
}

/** Record a movement row + apply its effect. Returns the new movement id. */
function record_movement(array $m): int
{
    $required = ['company_id', 'item_id', 'movement_type', 'quantity', 'movement_date'];
    foreach ($required as $k) {
        if (!isset($m[$k]) || $m[$k] === '') throw new InvalidArgumentException("Missing field: $k");
    }
    $qty = (float)$m['quantity'];
    if ($qty <= 0) throw new InvalidArgumentException('Quantity must be greater than zero.');

    $type = $m['movement_type'];
    if (!array_key_exists($type, MOVEMENT_DIRECTION)) throw new InvalidArgumentException('Unknown movement type.');

    q('INSERT INTO movements (company_id, item_id, movement_type, quantity, uom, unit_cost, project_id,
            from_project_id, to_project_id, supplier_id, dn_id, transfer_ref, reference, movement_date,
            notes, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        (int)$m['company_id'], (int)$m['item_id'], $type, $qty, $m['uom'] ?? null,
        (float)($m['unit_cost'] ?? 0), (int)($m['project_id'] ?? 0), (int)($m['from_project_id'] ?? 0),
        (int)($m['to_project_id'] ?? 0), !empty($m['supplier_id']) ? (int)$m['supplier_id'] : null,
        !empty($m['dn_id']) ? (int)$m['dn_id'] : null, $m['transfer_ref'] ?? null, $m['reference'] ?? null,
        $m['movement_date'], $m['notes'] ?? null, current_user()['id'] ?? null,
    ]);
    $mid = (int)db()->lastInsertId();

    $sign = MOVEMENT_DIRECTION[$type];
    if ($type === 'TRANSFER_IN') {
        apply_stock_change((int)$m['item_id'], (int)$m['company_id'], (int)($m['to_project_id'] ?? 0), $qty);
    } elseif ($type === 'TRANSFER_OUT') {
        apply_stock_change((int)$m['item_id'], (int)$m['company_id'], (int)($m['from_project_id'] ?? 0), -$qty);
    } elseif ($type === 'OUT') {
        apply_stock_change((int)$m['item_id'], (int)$m['company_id'], (int)($m['project_id'] ?? 0), -$qty);
    } else { // IN / ADJUST are inbound on the target location
        apply_stock_change((int)$m['item_id'], (int)$m['company_id'], (int)($m['project_id'] ?? 0), $qty);
    }

    audit('insert', 'movements', $mid, $type . ' ' . fmt_qty($qty));
    return $mid;
}

/** Reverse a movement's stock effect (used before deleting/updating it). */
function reverse_movement_effect(array $mv): void
{
    $qty  = (float)$mv['quantity'];
    $type = $mv['movement_type'];
    switch ($type) {
        case 'IN':
        case 'ADJUST':
            apply_stock_change((int)$mv['item_id'], (int)$mv['company_id'], (int)$mv['project_id'], -$qty);
            break;
        case 'OUT':
            apply_stock_change((int)$mv['item_id'], (int)$mv['company_id'], (int)$mv['project_id'], $qty);
            break;
        case 'TRANSFER_IN':
            apply_stock_change((int)$mv['item_id'], (int)$mv['company_id'], (int)$mv['to_project_id'], -$qty);
            break;
        case 'TRANSFER_OUT':
            apply_stock_change((int)$mv['item_id'], (int)$mv['company_id'], (int)$mv['from_project_id'], $qty);
            break;
    }
}

/** Create a paired inter-project transfer. Returns the transfer reference. */
function create_transfer(int $itemId, int $fromP, int $toP, float $qty, string $date, ?string $notes, ?string $ref = null): string
{
    $item = fetch_one('SELECT * FROM items WHERE id = ?', [$itemId]);
    if (!$item) throw new RuntimeException('Item not found.');
    if ($fromP === $toP) throw new InvalidArgumentException('Source and destination must differ.');
    $ref = $ref ?: 'TRF-' . date('ymd', strtotime($date)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    db()->beginTransaction();
    try {
        record_movement([
            'company_id' => (int)$item['company_id'], 'item_id' => $itemId, 'movement_type' => 'TRANSFER_OUT',
            'quantity' => $qty, 'uom' => $item['uom'], 'unit_cost' => $item['unit_cost'], 'project_id' => $fromP,
            'from_project_id' => $fromP, 'to_project_id' => $toP, 'transfer_ref' => $ref,
            'reference' => 'Transfer ' . project_name($fromP) . ' → ' . project_name($toP),
            'movement_date' => $date, 'notes' => $notes,
        ]);
        record_movement([
            'company_id' => (int)$item['company_id'], 'item_id' => $itemId, 'movement_type' => 'TRANSFER_IN',
            'quantity' => $qty, 'uom' => $item['uom'], 'unit_cost' => $item['unit_cost'], 'project_id' => $toP,
            'from_project_id' => $fromP, 'to_project_id' => $toP, 'transfer_ref' => $ref,
            'reference' => 'Transfer ' . project_name($fromP) . ' → ' . project_name($toP),
            'movement_date' => $date, 'notes' => $notes,
        ]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $ref;
}

/* ------------------------------------------------------------- doc numbering */
function next_dn_no(int $companyId): string
{
    $code = (string)fetch_val('SELECT code FROM companies WHERE id = ?', [$companyId]) ?: 'CO';
    $n = (int)fetch_val('SELECT COUNT(*) FROM delivery_notes WHERE company_id = ?', [$companyId]);
    do {
        $n++;
        $candidate = sprintf('DN-%s-%04d', $code, $n);
    } while (fetch_val('SELECT id FROM delivery_notes WHERE company_id = ? AND dn_no = ?', [$companyId, $candidate]));
    return $candidate;
}

/* -------------------------------------------------------------- validation */
function post(string $k, $default = '') { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $default; }
function get(string $k, $default = '')  { return isset($_GET[$k]) ? (is_string($_GET[$k]) ? trim($_GET[$k]) : $_GET[$k]) : $default; }
function post_num(string $k, $default = 0) { $v = post($k, $default); return is_numeric($v) ? $v + 0 : $default; }
function get_num(string $k, $default = 0)  { $v = get($k, $default);  return is_numeric($v) ? $v + 0 : $default; }

function is_dob(string $d): bool
{
    $t = date_create($d);
    return $t && $t->format('Y-m-d') === $d;
}

/* -------------------------------------------------------------- pagination */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function pager_links(array $pg, array $query = []): string
{
    if ($pg['pages'] <= 1) return '';
    $out = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $pg['pages']; $i++) {
        if ($pg['pages'] > 12 && $i > 3 && $i < $pg['pages'] - 2 && abs($i - $pg['page']) > 1) {
            if ($i === 4) $out .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            continue;
        }
        $query['page'] = $i;
        $cls = $i === $pg['page'] ? ' active' : '';
        $out .= '<li class="page-item' . $cls . '"><a class="page-link" href="?' . e(http_build_query($query)) . '">' . $i . '</a></li>';
    }
    return $out . '</ul></nav>';
}

/* ------------------------------------------------------------------ exports */
function export_filename(string $base): string
{
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $base) . '_' . date('Ymd_His');
}

/**
 * Work out which row keys to print.
 * When $keys is not supplied and the rows are associative (the usual report shape),
 * use the row's own keys; otherwise fall back to the positional column order.
 */
function export_guess_keys(array $headers, array $rows): array
{
    if ($rows) {
        $first = reset($rows);
        if (is_array($first) && $first) {
            $ks = array_keys($first);
            if ($ks !== range(0, count($ks) - 1)) {   // not a plain 0..n list
                return $ks;
            }
        }
    }
    return array_keys($headers);
}

/** CSV download (opens in Excel). $rows = list of assoc/array rows. $headers = column labels. */
function export_csv(string $basename, array $headers, array $rows, array $keys = null): void
{
    $keys = $keys ?: export_guess_keys($headers, $rows);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . export_filename($basename) . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
    fputcsv($out, array_values($headers));
    foreach ($rows as $r) {
        $line = [];
        foreach ($keys as $k) { $line[] = is_array($r) ? ($r[$k] ?? '') : '';
        }
        fputcsv($out, $line);
    }
    fputcsv($out, []);
    fputcsv($out, [RT_ATTRIBUTION_TEXT]);
    fclose($out);
    audit('export', 'reports', null, 'CSV ' . $basename . ' (' . count($rows) . ' rows)');
    exit;
}

/** Excel download via a real HTML table with an .xls extension (Excel/Sheets open it natively). */
function export_xls(string $basename, string $title, array $headers, array $rows, array $keys = null, array $meta = []): void
{
    $keys = $keys ?: export_guess_keys($headers, $rows);
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . export_filename($basename) . '.xls"');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<table border="0" cellspacing="0" cellpadding="4">';
    echo '<tr><td colspan="' . count($headers) . '" style="font-size:16pt;font-weight:bold">' . e($title) . '</td></tr>';
    foreach ($meta as $k => $v) {
        echo '<tr><td style="font-weight:bold">' . e($k) . '</td><td colspan="' . (count($headers) - 1) . '">' . e($v) . '</td></tr>';
    }
    echo '<tr><td colspan="' . count($headers) . '"></td></tr>';
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th style="background:#1f3a5f;color:#fff;border:1px solid #999;text-align:left">' . e($h) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($keys as $k) {
            $v = is_array($r) ? ($r[$k] ?? '') : '';
            echo '<td style="border:1px solid #ccc">' . e($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '<tr><td colspan="' . count($headers) . '" style="color:#6b7a8d;font-size:9pt">'
        . e(RT_ATTRIBUTION_TEXT) . '</td></tr>';
    echo '</table></body></html>';
    audit('export', 'reports', null, 'Excel ' . $basename . ' (' . count($rows) . ' rows)');
    exit;
}

/** Export buttons toolbar. */
function export_buttons(array $paths): string
{
    $h = '<div class="btn-group btn-group-sm no-print" role="group">';
    foreach ($paths as $label => $href) {
        $h .= '<a class="btn btn-outline-secondary" href="' . e($href) . '">' . e($label) . '</a>';
    }
    return $h . '</div>';
}

/* --------------------------------------------------------- letterhead helper */
function company_letterhead(int $companyId): ?string
{
    $f = fetch_val('SELECT letterhead FROM companies WHERE id = ?', [$companyId]);
    if (!$f) return null;
    $abs = __DIR__ . '/assets/letterheads/' . basename($f);
    return is_file($abs) ? url('assets/letterheads/' . basename($f)) : null;
}

/** Printable document header with letterhead image when available. */
function print_header(array $company, string $docTitle, array $metaRows = []): string
{
    $lh = company_letterhead((int)$company['id']);
    $h  = '<div class="print-head">';
    if ($lh) {
        $h .= '<img src="' . e($lh) . '" class="letterhead" alt="Company letterhead">';
        $h .= '<div class="lh-overlay"><h4>' . e($docTitle) . '</h4></div>';
    } else {
        $h .= '<div class="head-top">';
        $h .= '<div><h3>' . e($company['name']) . '</h3>';
        $h .= '<div class="muted">' . e($company['address']) . '</div>';
        $h .= '<div class="muted">Tel: ' . e($company['phone']) . ' &nbsp;|&nbsp; Email: ' . e($company['email'])
            . ($company['trn'] ? ' &nbsp;|&nbsp; TRN: ' . e($company['trn']) : '') . '</div></div>';
        $h .= '<div class="text-end"><h4>' . e($docTitle) . '</h4></div>';
        $h .= '</div>';
    }
    if ($metaRows) {
        $h .= '<table class="meta-table"><tbody>';
        $half = (int)ceil(count($metaRows) / 2);
        $rows = array_chunk($metaRows, max(1, $half), true);
        for ($i = 0; $i < count($rows[0] ?? []); $i++) {
            $h .= '<tr>';
            foreach ($rows as $chunk) {
                $pair = array_slice($chunk, $i, 1, true);
                foreach ($pair as $k => $v) { $h .= '<th>' . e($k) . '</th><td>' . e($v) . '</td>'; }
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
    }
    return $h . '</div>';
}

/* -------------------------------------------------------------- misc queries */
function items_for_select(?int $companyId = null, bool $onlyActive = true): array
{
    $sql = 'SELECT id, company_id, item_code, item_name, uom, quantity, unit_cost, reorder_level, project_id
            FROM items WHERE 1=1';
    $p = [];
    if ($companyId)  { $sql .= ' AND company_id = ?'; $p[] = $companyId; }
    if ($onlyActive) { $sql .= ' AND active = 1'; }
    $sql .= ' ORDER BY item_code';
    return fetch_all($sql, $p);
}

function projects_for_select(?int $companyId = null): array
{
    $sql = 'SELECT * FROM projects WHERE 1=1';
    $p = [];
    if ($companyId) { $sql .= ' AND company_id = ?'; $p[] = $companyId; }
    $sql .= ' ORDER BY code';
    return fetch_all($sql, $p);
}

function suppliers_for_select(?int $companyId = null): array
{
    $sql = 'SELECT * FROM suppliers WHERE active = 1';
    $p = [];
    if ($companyId) { $sql .= ' AND company_id = ?'; $p[] = $companyId; }
    $sql .= ' ORDER BY name';
    return fetch_all($sql, $p);
}

/** Location selector: 0 = main store plus the company's projects. */
function locations_for_select(?int $companyId = null, bool $includeMain = true): array
{
    $out = [];
    if ($includeMain) $out[] = ['id' => 0, 'label' => 'Main Store / Central Warehouse'];
    foreach (projects_for_select($companyId) as $p) {
        $out[] = ['id' => (int)$p['id'], 'label' => $p['code'] . ' — ' . $p['name']];
    }
    return $out;
}

/** Dashboard / report KPIs. */
function kpi_summary(?int $companyId): array
{
    $w  = $companyId ? ' WHERE company_id = ' . (int)$companyId : '';
    $wi = $companyId ? ' WHERE i.company_id = ' . (int)$companyId : '';
    return [
        'items'        => (int)fetch_val('SELECT COUNT(*) FROM items' . $wi),
        'items_active' => (int)fetch_val('SELECT COUNT(*) FROM items' . ($companyId ? ' WHERE company_id = ' . (int)$companyId . ' AND active = 1' : ' WHERE active = 1')),
        'stock_value'  => (float)fetch_val('SELECT COALESCE(SUM(quantity * unit_cost),0) FROM items' . $wi),
        'projects'     => (int)fetch_val('SELECT COUNT(*) FROM projects' . $w),
        'suppliers'    => (int)fetch_val('SELECT COUNT(*) FROM suppliers' . $w),
        'movements'    => (int)fetch_val('SELECT COUNT(*) FROM movements' . $w),
        'movements_30' => (int)fetch_val('SELECT COUNT(*) FROM movements'
                            . ($companyId ? ' WHERE company_id = ' . (int)$companyId . ' AND ' : ' WHERE ')
                            . 'movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'),
        'delivery_notes' => (int)fetch_val('SELECT COUNT(*) FROM delivery_notes' . $w),
        'dn_open'      => (int)fetch_val('SELECT COUNT(*) FROM delivery_notes'
                            . ($companyId ? ' WHERE company_id = ' . (int)$companyId . ' AND ' : ' WHERE ')
                            . "status IN ('draft','issued')"),
        'low_stock'    => (int)fetch_val('SELECT COUNT(*) FROM items' . ($companyId ? ' WHERE company_id = ' . (int)$companyId . ' AND ' : ' WHERE ')
                            . 'quantity <= reorder_level'),
        'zero_stock'   => (int)fetch_val('SELECT COUNT(*) FROM items' . ($companyId ? ' WHERE company_id = ' . (int)$companyId . ' AND ' : ' WHERE ')
                            . 'quantity <= 0'),
    ];
}
