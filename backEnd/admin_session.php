<?php
if (session_status() !== PHP_SESSION_NONE) {
    return;
}

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

if ($isSecure) {
    ini_set('session.cookie_secure', '1');
}

session_name('COSMIC_ADMIN');
session_start();

// CSRF token for admin POST actions (block / unblock)
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
