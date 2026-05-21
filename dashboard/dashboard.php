<?php
session_start();
include '../backEnd/tetrisgame.php';

$auth = new tetrisgame();

// Handle AJAX score updates first (before any HTML output)
$data = json_decode(file_get_contents('php://input'), true);
$newscore = $data['score'] ?? 0;
$username = $_SESSION['username'] ?? '';

if ($newscore > 0) {
    $updatedScore = $auth->UpdateScore($username, $newscore);
    $_SESSION['score'] = $updatedScore;

    // Send JSON response for AJAX request
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'score' => $updatedScore]);
    exit();
}

// Check login status
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    $_SESSION['msg'] = "Please log in to access the dashboard.";
    header("Location: login.php");
    exit();
}

// Handle logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="galaxy-bg">
    <div class="container min-vh-100 d-flex flex-column justify-content-center align-items-center">
        <div class="glass-card p-5 w-100 text-center" style="max-width: 600px;">
            <h2 class="text-white mb-4 cosmic-title">Mission Control</h2>
            <p class="text-white mb-4 cosmic-title" > Welcome, Commander! Are you ready to
                 embark on this interstellar challenge?</p>
            <div class="high-score-box mb-5 p-3">
                <h5 class="text-uppercase text-info tracking-wide mb-1">Personal Best</h5>
                <h1 class="display-4 text-white fw-bold mb-0"><?php echo $auth->getScore($_SESSION['username'] ?? '')
                ?></h1>
            </div>
            <div class="d-grid gap-3 d-md-flex justify-content-md-center flex-wrap">
                <button class="btn neon-btn btn-large" onclick="location.href='game.php'">Play</button>
                <button class="btn neon-btn neon-blitz btn-large" onclick="location.href='multiplayer.php'">Blitz Mode</button>
                <button class="btn neon-btn btn-large" onclick="location.href='leaderboard.php'">Leaderboard</button>
                <button class="btn neon-btn neon-blitz btn-large" onclick="location.href='blitz_leaderboard.php'">Blitz Rankings</button>
            </div>
            <form method="POST">
                <button class="btn btn-link text-white mt-4" type="submit" name="logout">Logout</button>
            </form>
        </div>
    </div>
</body>
</html>
