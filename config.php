<?php
/**
 * Construction Store — Inventory & Stock Movement System
 * Configuration + bootstrap.
 *
 * Runs out of the box on XAMPP / WAMP (MySQL user "root", empty password).
 */

/* ---------------------------------------------------------------- database */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'construction_store');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default is an empty password

/* ------------------------------------------------------------------- app */
define('APP_NAME', 'Construction Store');
define('APP_VERSION', '1.1.0');
define('APP_CURRENCY', 'AED');
define('APP_TZ', 'Asia/Dubai');
define('ROWS_PER_PAGE', 25);

/* =========================================================================
 *  MANDATORY ATTRIBUTION  —  do not remove or rename anything below.
 *  "'Powered by Red Thunder E.G'" must appear on every window, form, printed
 *  report and export. The digest below locks includes/powered_by.php; if that
 *  file or this string is changed the application refuses to start.
 * ========================================================================= */
define('RT_ATTRIBUTION_TEXT', 'Powered by Red Thunder E.G');
define('RT_ATTRIBUTION_OWNER', 'Red Thunder E.G');
define('RT_POWERED_BY_SHA256', '1f1ba1e3f6f0561a13b1172e786999d9ef874c16fbc78db82638f2719c5a021b');

/* =========================================================================
 *  BASE URL  — auto-detected so the app works from any folder
 *  (http://localhost/construction-store/, http://localhost/, a sub-folder, …).
 *  Only uncomment the manual override if auto-detection cannot work
 *  (e.g. the project folder is reached through an Apache Alias).
 * ========================================================================= */
/* define('APP_BASE_URL', '/construction-store'); */

if (!defined('APP_BASE_URL')) {
    $rt_base = '';
    if (PHP_SAPI !== 'cli') {
        $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
            ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/')
            : '';
        $appDir  = rtrim(str_replace('\\', '/', __DIR__), '/');

        if ($docRoot !== '' && strpos($appDir, $docRoot) === 0) {
            // /xampp/htdocs/construction-store  -  /xampp/htdocs   =>  /construction-store
            $rt_base = substr($appDir, strlen($docRoot));
        }
        if ($rt_base === '' && !empty($_SERVER['SCRIPT_NAME'])) {
            // Fallback: strip the script path down to the app folder.
            $scriptDir = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
            foreach (['/reports', '/includes'] as $sub) {
                if (substr($scriptDir, -strlen($sub)) === $sub) {
                    $scriptDir = substr($scriptDir, 0, -strlen($sub));
                }
            }
            $rt_base = $scriptDir;
        }
        $rt_base = rtrim($rt_base, '/');
        if ($rt_base === '/') { $rt_base = ''; }
    }
    define('APP_BASE_URL', $rt_base);
}

date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_name('construction_store_sid');
    session_start();
}

// Only report fatals/notices in development; keep the UI clean.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* =========================================================================
 *  ATTRIBUTION GATE + OUTPUT GUARD  —  loaded first, always.
 *  1. the boot gate stops the app if the mark file/string/digest is broken;
 *  2. the output guard replaces any response that does not carry the mark.
 * ========================================================================= */
require_once __DIR__ . '/includes/powered_by.php';
rt_attribution_verify_or_die();

if (PHP_SAPI !== 'cli') {
    ob_start('rt_guard_output');
}
