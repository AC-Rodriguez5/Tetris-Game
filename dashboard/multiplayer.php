<?php
session_start();
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header("Location: login.php"); exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blitz Mode - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="galaxy-bg">

<div class="container min-vh-100 d-flex flex-column justify-content-center align-items-center py-4">
    <div class="glass-card blitz-menu-card text-center">
        <div class="blitz-kicker mb-2">2-minute battle</div>
        <h2 class="cosmic-title text-white mb-4">Blitz Mode</h2>

        <div class="blitz-choice-stack">
            <a class="blitz-choice-btn blitz-choice-primary" href="blitz_room.php?mode=quick">
                <span>Quick Match</span>
                <small>Find the next open room</small>
            </a>
            <a class="blitz-choice-btn" href="blitz_room.php?mode=create">
                <span>Create Room</span>
                <small>Host with a room code</small>
            </a>
            <a class="blitz-choice-btn" href="blitz_join.php">
                <span>Join Room</span>
                <small>Enter a friend's code</small>
            </a>
        </div>

        <div class="blitz-link-row mt-4">
            <a href="dashboard.php">Dashboard</a>
            <a href="blitz_leaderboard.php">Rankings</a>
        </div>
    </div>
</div>

</body>
</html>
