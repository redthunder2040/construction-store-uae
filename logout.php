<?php
require_once __DIR__ . '/helpers.php';

if (current_user()) {
    audit('logout', 'users', (int)current_user()['id'], 'User signed out');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
