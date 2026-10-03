<?php
/**
 * Backwards-compatible entry point.
 * The Reports & Printing hub lives in reports/index.php; older links, bookmarks
 * and typed URLs used reports.php, which is why Apache answered with
 * "Not Found — The requested URL was not found on this server."
 */
require_once __DIR__ . '/helpers.php';
redirect('reports/index.php');
