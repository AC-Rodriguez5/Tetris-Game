<?php
session_start();

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$mode = $_GET['mode'] ?? 'quick';
if (!in_array($mode, ['quick', 'create', 'join'], true)) {
    $mode = 'quick';
}

// Uppercase BEFORE stripping non-letters so lowercase URLs (?code=abcdef)
// are accepted — otherwise the [^A-Z] regex would remove the lowercase
// characters first and the user would be bounced to blitz_join.php.
$initialCode = preg_replace('/[^A-Z]/', '', strtoupper($_GET['code'] ?? ''));
if ($mode === 'join' && strlen($initialCode) !== 6) {
    header("Location: blitz_join.php");
    exit();
}

$currentUsername = $_SESSION['username'] ?? 'Player';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$currentCsrfToken = $_SESSION['csrf_token'];
