<?php
session_start();
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    $_SESSION['msg'] = "Please log in to access the leaderboard.";
    header("Location: login.php");
    exit();
}
include '../backEnd/tetrisgame.php';
$auth = new tetrisgame();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="galaxy-bg">
    <div class="container min-vh-100 d-flex flex-column justify-content-center align-items-center py-4">
        <div class="glass-card p-5 w-100 text-center" style="max-width: 800px;">
            <h2 class="text-white mb-4 cosmic-title">Galactic Leaderboard</h2>
            <p class="text-white mb-4">Top commanders across the cosmos</p>

            <!-- Leaderboard Table -->
            <div class="leaderboard-container mb-4">
                <div class="table-responsive">
                    <table class="table table-dark table-striped">
                        <thead>
                            <tr>
                                <th class="text-info">Rank</th>
                                <th class="text-info">Commander</th>
                                <th class="text-info">Score</th>
                            </tr>
                        </thead>
                        <tbody id="leaderboard-body">
                            <!-- Backend will populate this with PHP -->
                            <?php
                            foreach($auth->getLeaderboard() as $index => $entry) {
                                echo "<tr>";
                                echo "<td>" . ($index + 1) . "</td>";
                                echo "<td>" . htmlspecialchars($entry['username']) . "</td>";
                                echo "<td>" . htmlspecialchars($entry['score']) . "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Your Position -->
             <br>
             <br>
            <div class="your-rank-box mb-4 p-3">
                <h5 class="text-uppercase text-info tracking-wide mb-1">Your Ranking</h5>
                <div class="row text-center">
                    <div class="col-4">
                        <div class="rank-display">
                            <span class="display-6 text-white fw-bold" id="your-rank"><?php echo $auth->getUserRanking($_SESSION['username']) ?: '-'; ?></span>
                            <small class="text-info">Rank</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="score-display">
                            <span class="display-6 text-white fw-bold" id="your-score"><?php echo $auth->getScore($_SESSION['username']); ?></span>
                            <small class="text-info">Score</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="position-display">
                            <span class="display-6 text-white fw-bold" id="position-diff"><?php echo($_SESSION['username']); ?></span>
                            <small class="text-info">Commander</small>
                        </div>
                    </div>
                </div>
            </div>
            <br>

            <!-- Navigation -->
            <div class="d-grid gap-3 d-md-flex justify-content-md-center">
                <button class="btn neon-btn" onclick="location.href='dashboard.php'">Back to Mission Control</button>
                <button class="btn neon-btn" onclick="location.href='game.php'">Play Again</button>
            </div>
        </div>
    </div>

    <style>
        .leaderboard-container {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid var(--glass-border);
        }

        .table-dark {
            background: transparent;
            border: none;
        }

        .table-dark thead th {
            border-bottom: 2px solid var(--neon-blue);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .table-dark tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: background-color 0.3s ease;
        }

        .table-dark tbody tr:hover {
            background: rgba(0, 243, 255, 0.1);
        }

        .table-dark td {
            padding: 12px;
            vertical-align: middle;
        }

        .your-rank-box {
            background: rgba(176, 38, 255, 0.1);
            border: 1px solid var(--neon-purple);
            border-radius: 15px;
            box-shadow: inset 0 0 20px rgba(176, 38, 255, 0.2);
        }

        .rank-display, .score-display, .position-display {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .rank-display .display-6 {
            color: var(--neon-blue) !important;
        }

        .score-display .display-6 {
            color: var(--neon-pink) !important;
        }

        .position-display .display-6 {
            color: var(--neon-purple) !important;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        /* Trophy icons for top 3 */
        .rank-1::before {
            content: "🏆";
            margin-right: 8px;
        }

        .rank-2::before {
            content: "🥈";
            margin-right: 8px;
        }

        .rank-3::before {
            content: "🥉";
            margin-right: 8px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .glass-card {
                padding: 20px !important;
            }

            .table-responsive {
                font-size: 14px;
            }
        }
    </style>
</body>
</html>