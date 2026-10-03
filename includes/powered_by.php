<?php
/**
 * ============================================================================
 *  MANDATORY ATTRIBUTION COMPONENT  —  Powered by Red Thunder E.G
 * ============================================================================
 *  This file is part of the delivered application. It renders the fixed
 *  "Powered by Red Thunder E.G" mark that must appear on every window, form,
 *  printed report and export produced by this software, and it verifies its own
 *  integrity together with config.php on every single request.
 *
 *  Removing the mark, altering the string below, deleting this file, or
 *  changing its contents stops the application from running.
 *
 *  HONEST LIMITATION: this is a tamper-EVIDENT guard, not a tamper-PROOF one.
 *  Anybody with full write access to the source files can also edit the guard
 *  itself. Check-proof licence enforcement requires compiling/encoding the PHP
 *  sources (ionCube or SourceGuardian) so the code cannot be read or modified
 *  in place — see README.md, section "Protecting the attribution".
 * ============================================================================
 */

if (!defined('RT_ATTRIBUTION_TEXT')) {
    // The canonical string is defined in config.php, so two separate files must
    // agree before the application is allowed to start.
    exit('Configuration error: RT_ATTRIBUTION_TEXT is not defined.');
}

/**
 * Render the mandatory mark.
 * $variant: 'fixed'  → fixed strip pinned to the bottom of the window
 *           'print'  → in-flow line for printed reports / documents
 *           'card'   → small line for cards and dialogs (login etc.)
 */
function rt_attribution_html(string $variant = 'print'): string
{
    $cls = 'rt-attribution rt-attribution-' . preg_replace('/[^a-z]/', '', $variant);
    return '<div class="' . $cls . '" data-rt-attribution="1" title="' . RT_ATTRIBUTION_TEXT . '">'
         . '<span class="rt-dot">&bull;</span>'
         . '<span>' . htmlspecialchars(RT_ATTRIBUTION_TEXT, ENT_QUOTES, 'UTF-8') . '</span>'
         . '</div>';
}

/** The canonical text, for use in exports and JSON payloads. */
function rt_attribution_text(): string
{
    return RT_ATTRIBUTION_TEXT;
}

/** The blocking page shown when the attribution has been removed or altered. */
function rt_attribution_halt_page(string $why): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Application halted — attribution missing</title><style>'
        . 'body{font-family:"Segoe UI",system-ui,Arial,sans-serif;background:#16293f;color:#fff;margin:0;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}'
        . '.box{max-width:660px;background:#fff;color:#1d2733;border-radius:16px;padding:28px 32px;'
        . 'box-shadow:0 24px 60px rgba(0,0,0,.45)}h1{font-size:1.15rem;color:#b02a37;margin:0 0 12px;'
        . 'letter-spacing:.3px}code{background:#f1f5fa;padding:2px 6px;border-radius:5px;font-size:.85em}'
        . '.mark{margin-top:20px;padding-top:12px;border-top:1px solid #e3e9f0;font-size:.78rem;'
        . 'color:#6b7a8d;letter-spacing:.5px}</style></head><body><div class="box">'
        . '<h1>Application halted</h1>'
        . '<p>This installation cannot run because ' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>The mark <strong>' . htmlspecialchars(RT_ATTRIBUTION_TEXT, ENT_QUOTES, 'UTF-8') . '</strong> '
        . 'is a required, non-removable part of this software and must be present in every window, form, '
        . 'report, print-out and export it produces.</p>'
        . '<p>Restore <code>includes/powered_by.php</code> and the matching '
        . '<code>RT_POWERED_BY_SHA256</code> value in <code>config.php</code> from your original download, '
        . 'or contact Red Thunder E.G for a replacement copy.</p>'
        . '<p style="font-size:.85rem;color:#6b7a8d">If you did not intentionally change these files, a PHP '
        . 'error may have stopped a page half-way. Check your PHP error log and the value of '
        . '<code>APP_BASE_URL</code> in <code>config.php</code>, then open <code>check.php</code>.</p>'
        . '<div class="mark">' . htmlspecialchars(RT_ATTRIBUTION_TEXT, ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div></body></html>';
}

/**
 * BOOT GATE — called by config.php on every request, before anything else runs.
 * Verifies the component file, the canonical string and the file digest.
 */
function rt_attribution_verify_or_die(): void
{
    $file = __DIR__ . '/powered_by.php';          // __DIR__ === <app>/includes
    $why  = '';

    if (!is_file($file)) {
        $why = 'the attribution component file <code>includes/powered_by.php</code> is missing';
    } elseif (RT_ATTRIBUTION_TEXT !== 'Powered by Red Thunder E.G') {
        $why = 'the attribution string in <code>config.php</code> has been altered';
    } elseif (!defined('RT_POWERED_BY_SHA256') || strlen((string)RT_POWERED_BY_SHA256) !== 64) {
        $why = 'the integrity digest <code>RT_POWERED_BY_SHA256</code> is missing from <code>config.php</code>';
    } elseif (strtoupper(hash_file('sha256', $file)) !== strtoupper((string)RT_POWERED_BY_SHA256)) {
        $why = 'the attribution component <code>includes/powered_by.php</code> has been modified';
    } elseif (strpos((string)@file_get_contents($file), 'Powered by Red Thunder E.G') === false) {
        $why = 'the attribution mark has been removed from the component';
    }

    if ($why !== '') {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo rt_attribution_halt_page($why);
        exit;
    }
}

/**
 * OUTPUT GUARD — registered with ob_start() in config.php.
 * No page, report, print view or export may leave the application without the
 * mandatory mark. If the mark is missing the response is replaced by the
 * blocking page instead of the unmarked output.
 */
function rt_guard_output(string $html): string
{
    if (trim($html) === '') {
        return $html;                                  // empty body / redirects
    }
    $code = (int)http_response_code();
    if ($code >= 300 && $code < 400) {
        return $html;                                  // 3xx redirect, no body shown
    }

    // Non-HTML payloads (JSON lookups, CSV / Excel exports) cannot carry a
    // rendered footer; those bodies embed RT_ATTRIBUTION_TEXT themselves.
    $ctype = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0) { $ctype = strtolower(trim(substr($h, 13))); break; }
    }
    if ($ctype !== '' && strpos($ctype, 'text/html') === false && strpos($ctype, 'xml') === false) {
        return $html;
    }

    if (stripos($html, RT_ATTRIBUTION_TEXT) === false) {
        if (!headers_sent()) { http_response_code(500); }
        return rt_attribution_halt_page('the attribution mark is missing from the rendered output');
    }
    return $html;
}
