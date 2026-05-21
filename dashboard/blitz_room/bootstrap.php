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

$initialCode = strtoupper(preg_replace('/[^A-Z]/', '', $_GET['code'] ?? ''));
if ($mode === 'join' && strlen($initialCode) !== 6) {
    header("Location: blitz_join.php");
    exit();
}

$currentUsername = $_SESSION['username'] ?? 'Player';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$currentCsrfToken = $_SESSION['csrf_token'];
