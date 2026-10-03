<?php
/**
 * Minimal print-first layout used by every report page.
 * Reports are plain HTML + print CSS, so the browser's own
 * "Print" / "Save as PDF" produces a clean A4 document.
 */
if (!function_exists('e')) { require_once __DIR__ . '/../helpers.php'; }
require_login();
require_can('view_reports');

$page_title = $page_title ?? 'Report';
$company    = $company ?? null;

/** Toolbar with print + export actions, preserving the current filters. */
function report_toolbar(array $extraLinks = []): string
{
    $qs = $_GET;
    unset($qs['export']);
    $excel = '?' . http_build_query($qs + ['export' => 'xls']);
    $csv   = '?' . http_build_query($qs + ['export' => 'csv']);
    $h  = '<div class="report-toolbar no-print">';
    $h .= '<div class="d-flex align-items-center gap-2">';
    $h .= '<a class="btn btn-sm btn-outline-secondary" href="' . e(url('reports/index.php')) . '"><i class="bi bi-arrow-left"></i> All reports</a>';
    foreach ($extraLinks as $label => $href) {
        $h .= '<a class="btn btn-sm btn-outline-secondary" href="' . e($href) . '">' . $label . '</a>';
    }
    $h .= '</div><div class="d-flex align-items-center gap-2">';
    if (can('export_data')) {
        $h .= '<a class="btn btn-sm btn-outline-success" href="' . e($excel) . '"><i class="bi bi-file-earmark-excel"></i> Excel</a>';
        $h .= '<a class="btn btn-sm btn-outline-secondary" href="' . e($csv) . '"><i class="bi bi-filetype-csv"></i> CSV</a>';
    }
    $h .= '<button class="btn btn-sm btn-brand" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button>';
    $h .= '</div></div>';
    return $h;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="report-body">
<?= report_toolbar($report_extra_links ?? []) ?>
<div class="container-fluid report-wrap">
