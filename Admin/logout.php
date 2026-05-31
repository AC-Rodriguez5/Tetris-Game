<?php
require_once __DIR__ . '/../backEnd/admin_session.php';
$_SESSION = [];

// Expire the session cookie in the browser, then destroy server-side data
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php');
exit();
