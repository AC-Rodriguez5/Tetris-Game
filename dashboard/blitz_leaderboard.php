<?php
session_start();
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header("Location: login.php"); exit();
}
include '../dbConnect/dbconnect.php';

$dbcon = new dbcon();
$db    = $dbcon->dbconnect();

$rows = [];
$myRow = null;

if ($db) {
    $s = $db->query("SELECT username, wins, losses, best_score, total_games
                     FROM blitz_leaderboard
                     ORDER BY wins DESC, best_score DESC
                     LIMIT 15");
    $rows = $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];

    $s2 = $db->prepare("SELECT username, wins, losses, best_score, total_games FROM blitz_leaderboard WHERE username = ?");
    $s2->execute([$_SESSION['username']]);
    $myRow = $s2->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blitz Leaderboard - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="galaxy-bg">
<div class="container py-5">
    <div class="glass-card p-4 p-md-5">
        <h2 class="cosmic-title text-white text-center mb-1">Blitz Leaderboard</h2>
        <p class="text-info text-center mb-4" style="font-size:0.85rem;letter-spacing:2px">
            2-MINUTE SPEED BATTLE RANKINGS
        </p>

        <?php if ($myRow): ?>
        <div class="high-score-box p-3 mb-4 text-center">
            <div class="text-info text-uppercase mb-1" style="font-size:0.75rem;letter-spacing:3px">Your Stats</div>
            <div class="row text-white">
                <div class="col"><div class="fw-bold fs-4"><?= $myRow['wins'] ?></div><div class="text-info small">Wins</div></div>
                <div class="col"><div class="fw-bold fs-4"><?= $myRow['losses'] ?></div><div class="text-warning small">Losses</div></div>
                <div class="col"><div class="fw-bold fs-4"><?= number_format($myRow['best_score']) ?></div><div class="text-info small">Best Score</div></div>
                <div class="col"><div class="fw-bold fs-4"><?= $myRow['total_games'] ?></div><div class="text-white-50 small">Games</div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
        <p class="text-white-50 text-center py-4">No blitz games played yet. Be the first!</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-hover" style="background:transparent">
                <thead>
                    <tr class="text-info text-uppercase" style="font-size:0.75rem;letter-spacing:2px">
                        <th>Rank</th>
                        <th>Player</th>
                        <th class="text-center">Wins</th>
                        <th class="text-center">Losses</th>
                        <th class="text-center">Best Score</th>
                        <th class="text-center">Games</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r):
                    $rank = $i + 1;
                    $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : $rank));
                    $isMe  = ($r['username'] === $_SESSION['username']);
                    $wr    = $r['total_games'] > 0 ? round(($r['wins'] / $r['total_games']) * 100) : 0;
                ?>
                    <tr class="<?= $isMe ? 'blitz-my-row' : '' ?>"
                        style="background:<?= $isMe ? 'rgba(255,107,53,0.12)' : 'transparent' ?>">
                        <td class="text-white fw-bold" style="font-size:1.1rem"><?= $medal ?></td>
                        <td class="text-white fw-bold">
                            <?= htmlspecialchars($r['username']) ?>
                            <?php if ($isMe): ?><span class="badge ms-1" style="background:rgba(255,107,53,0.4);font-size:0.65rem">You</span><?php endif; ?>
                        </td>
                        <td class="text-center text-success fw-bold"><?= $r['wins'] ?></td>
                        <td class="text-center text-danger"><?= $r['losses'] ?></td>
                        <td class="text-center text-info fw-bold"><?= number_format($r['best_score']) ?></td>
                        <td class="text-center text-white-50"><?= $r['total_games'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-center gap-3 mt-4">
            <button class="btn neon-btn neon-blitz" onclick="location.href='multiplayer.php'">Play Blitz</button>
            <button class="btn neon-btn" onclick="location.href='dashboard.php'">Dashboard</button>
        </div>
    </div>
</div>
</body>
</html>
