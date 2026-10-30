<?php
/**
 * 退出登录
 */
require_once __DIR__ . '/_lib.php';

$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

json_ok(null, '已退出登录');
