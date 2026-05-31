<?php
require_once __DIR__ . '/../backEnd/session_bootstrap.php';
include '../backEnd/tetrisgame.php';

$auth = new tetrisgame();

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }
    if (!empty($data['score'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in.']);
        exit();
    }
    $_SESSION['msg'] = "Please log in to access the dashboard.";
    header("Location: login.php");
    exit();
}

// Handle AJAX score updates
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$newscore = isset($data['score'])
    ? filter_var($data['score'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
    : 0;
$username= $_SESSION['username'] ?? '';

if ($newscore === false) {
    header('Content-Type: application/json');
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid score.']);
    exit();
}

if ($newscore > 0) {
    $clientToken = $data['csrf_token'] ?? '';
    $sessionToken= $_SESSION['csrf_token'] ?? '';
    if (!is_string($clientToken) || $sessionToken === '' || !hash_equals($sessionToken, $clientToken)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit();
    }
    $elapsed = max(1, time() - (int)($_SESSION['solo_game_started_at'] ?? time()));
    $maxPlausibleScore = max(50000, $elapsed * 5000);
    if ($newscore > $maxPlausibleScore) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Score failed sanity checks.']);
        exit();
    }
    $updatedScore = $auth->UpdateScore($username, $newscore);
    $_SESSION['score'] = $updatedScore;
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'score' => $updatedScore]);
    exit();
}

if (isset($_POST['logout'])) {
    session_unset(); session_destroy();
    header("Location: login.php"); exit();
}

$highScore = $auth->getScore($_SESSION['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mission Control — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
</head>
<body class="galaxy-bg cosmic-app-page mission-page">

<div class="nebula-layer" aria-hidden="true"></div>

<main class="container cosmic-app-shell mission-shell">
    <section class="cosmic-card mission-card page-enter" aria-labelledby="mission-title">

        <div class="mission-header">
            <div class="cosmic-kicker">Command Deck</div>
            <h1 class="text-white cosmic-title glitch" id="mission-title" data-text="Mission Control">Mission Control</h1>
            <p class="cosmic-subtitle">
            Welcome, Commander <?php echo htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES); ?>
            </p>
        </div>

        <div class="score-hero">
            <div class="score-hero-label">Personal Best</div>
            <div class="score-display-num" id="score-counter">0</div>
        </div>

        <div class="mission-actions stagger-children">
            <a class="btn neon-btn btn-large" href="game.php">Play</a>
            <a class="btn neon-btn neon-blitz btn-large" href="multiplayer.php">Blitz Mode</a>
            <a class="btn neon-btn btn-large" href="leaderboard.php">Leaderboard</a>
            <a class="btn neon-btn neon-blitz btn-large" href="blitz_leaderboard.php">Blitz Rankings</a>
        </div>

        <form method="POST" class="mt-4">
            <button class="btn logout-link" type="submit" name="logout">
                Logout
            </button>
        </form>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Animated score counter
(function() {
    const target = <?php echo (int)$highScore; ?>;
    const el     = document.getElementById('score-counter');
    if (!el) return;
    let current  = 0;
    const step   = Math.max(1, Math.floor(target / 60));
    const timer  = setInterval(() => {
        current = Math.min(current + step, target);
        el.textContent = current.toLocaleString();
        if (current >= target) clearInterval(timer);
    }, 20);
})();

</script>
</body>
</html>
