<?php
require_once __DIR__ . '/../backEnd/session_bootstrap.php';
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    $_SESSION['msg'] = "Please log in to access the leaderboard.";
    header("Location: login.php"); exit();
}
include '../backEnd/tetrisgame.php';
$auth    = new tetrisgame();
$entries = $auth->getLeaderboard();
$myRank  = $auth->getUserRanking($_SESSION['username']);
$myScore = $auth->getScore($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
</head>
<body class="galaxy-bg cosmic-app-page leaderboard-page">

<div class="nebula-layer" aria-hidden="true"></div>

<main class="container cosmic-app-shell leaderboard-shell">
    <section class="cosmic-card leaderboard-card page-enter" aria-labelledby="leaderboard-title">

        <div class="leaderboard-header">
            <div class="cosmic-kicker">Solo Rankings</div>
            <h1 class="text-white cosmic-title" id="leaderboard-title">Galactic Leaderboard</h1>
            <p class="cosmic-subtitle">Top commanders across the cosmos</p>
        </div>

        <div class="leaderboard-container cosmic-table-wrap">
            <div class="table-responsive">
                <table class="table table-dark cosmic-table">
                    <thead>
                        <tr>
                            <th style="width:60px">Rank</th>
                            <th>Commander</th>
                            <th style="text-align:right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $i => $entry):
                            $rank = $i + 1;
                            $rowClass = $rank === 1 ? 'rank-gold' : ($rank === 2 ? 'rank-silver' : ($rank === 3 ? 'rank-bronze' : ''));
                            $medal    = $rank === 1 ? '&#127942;' : ($rank === 2 ? '&#129352;' : ($rank === 3 ? '&#129353;' : $rank));
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td>
                                <strong>
                                    <?php if ($rank <= 3): ?>
                                        <span class="visually-hidden">Rank <?php echo $rank; ?></span>
                                        <span aria-hidden="true"><?php echo $medal; ?></span>
                                    <?php else: ?>
                                        <?php echo $rank; ?>
                                    <?php endif; ?>
                                </strong>
                            </td>
                            <td><?php echo htmlspecialchars($entry['username'], ENT_QUOTES); ?></td>
                            <td style="text-align:right;font-family:'Orbitron',sans-serif;font-size:0.95rem;">
                                <?php echo number_format((int)$entry['score']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Your stats -->
        <div class="your-rank-box standing-panel">
            <h5 class="text-uppercase tracking-wide mb-3" style="color:var(--neon-purple);font-family:'Orbitron',sans-serif;font-size:0.65rem;letter-spacing:3px;">Your Standing</h5>
            <div class="row text-center">
                <div class="col-4">
                    <div class="rank-display">
                        <span class="display-6 fw-bold"><?php echo $myRank ?: '—'; ?></span>
                        <small class="text-info" style="font-family:'Orbitron',sans-serif;font-size:0.6rem;letter-spacing:1px;text-transform:uppercase;">Rank</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="score-display">
                        <span class="display-6 fw-bold"><?php echo number_format((int)$myScore); ?></span>
                        <small class="text-info" style="font-family:'Orbitron',sans-serif;font-size:0.6rem;letter-spacing:1px;text-transform:uppercase;">Score</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="position-display">
                        <span class="display-6 fw-bold" style="font-size:1.3rem !important;"><?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES); ?></span>
                        <small class="text-info" style="font-family:'Orbitron',sans-serif;font-size:0.6rem;letter-spacing:1px;text-transform:uppercase;">Commander</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="leaderboard-actions stagger-children">
            <a class="btn neon-btn" href="dashboard.php">Mission Control</a>
            <a class="btn neon-btn" href="game.php">Play Again</a>
        </div>
    </section>
</main>
</body>
</html>
