<?php
require_once __DIR__ . '/helpers.php';
require_login();
if (!is_admin()) { redirect('index.php'); }
$_SESSION['active_company'] = (get('cid') === 'all') ? 'all' : (int)get('cid');
$ret = get('return');
if ($ret && strpos($ret, '//') === false && strpos($ret, ':') === false) {
    header('Location: ' . $ret);
} else {
    redirect('index.php');
}
exit;
