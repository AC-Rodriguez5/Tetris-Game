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
