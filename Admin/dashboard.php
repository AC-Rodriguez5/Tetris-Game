<?php
require_once __DIR__ . '/../backEnd/admin_session.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../backEnd/AdminAuth.php';

$auth          = new AdminAuth();
$adminUsername = htmlspecialchars($_SESSION['admin_username'] ?? 'Admin', ENT_QUOTES);

// ── Handle POST actions (block / unblock) ────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['target_username'])) {
    // CSRF check — reject forged block/unblock requests
    $postedToken  = $_POST['admin_csrf'] ?? '';
    $sessionToken = $_SESSION['admin_csrf'] ?? '';
    if (!is_string($postedToken) || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        header('Location: dashboard.php');
        exit();
    }
    $target = trim($_POST['target_username']);
    if ($target !== '') {
        if ($_POST['action'] === 'block') {
            $auth->blockPlayer($target);
            $actionMsg = "Player \"$target\" has been blocked.";
        } elseif ($_POST['action'] === 'unblock') {
            $auth->unblockPlayer($target);
            $actionMsg = "Player \"$target\" has been unblocked.";
        }
    }
}

// ── Fetch stats ───────────────────────────────────────────────────────────────
$totalPlayers  = $auth->getTotalPlayers();
$activePlayers = $auth->getActivePlayersCount();
$blockedCount  = $auth->getBlockedCount();
$blitzGames    = $auth->getTotalBlitzGames();

// ── Fetch player list (with search + pagination) ──────────────────────────────
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$result  = $auth->getAllPlayers($search, $page, $perPage);
$players = $result['players'];
$totalRows = $result['total'];
$totalPages = (int)ceil($totalRows / $perPage);

// ── Leaderboards ──────────────────────────────────────────────────────────────
$soloBoard  = $auth->getSoloLeaderboard(10);
$blitzBoard = $auth->getBlitzLeaderboard(10);

$cssVersion = filemtime(__DIR__ . '/../css/style.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo $cssVersion; ?>">
    <style>
        /* ── Admin overrides ─────────────────────────────────────────── */
        body { overflow-x: hidden; }

        .admin-shell {
            max-width: 1300px;
            padding: 0 16px 60px;
        }

        /* Top nav */
        .admin-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 0 10px;
            border-bottom: 1px solid rgba(255,0,127,0.15);
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .admin-topbar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .admin-topbar-badge {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            letter-spacing: 3px;
            color: var(--neon-pink);
            background: rgba(255,0,127,0.12);
            border: 1px solid rgba(255,0,127,0.35);
            border-radius: 20px;
            padding: 3px 10px;
        }
        .admin-topbar-title {
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            font-size: 1.15rem;
            color: #fff;
            margin: 0;
            text-shadow: 0 0 12px rgba(255,0,127,0.5);
        }
        .admin-topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .admin-user-chip {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.7rem;
            color: rgba(255,255,255,0.55);
            letter-spacing: 1px;
        }
        .admin-user-chip span {
            color: var(--neon-pink);
        }
        .admin-logout-btn {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.65rem;
            letter-spacing: 2px;
            padding: 6px 16px;
            border-radius: 6px;
            background: rgba(255,0,127,0.1);
            border: 1px solid rgba(255,0,127,0.35);
            color: var(--neon-pink);
            text-decoration: none;
            transition: all 0.2s;
        }
        .admin-logout-btn:hover {
            background: rgba(255,0,127,0.25);
            color: #fff;
        }

        /* Stat cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(18px) saturate(130%);
            -webkit-backdrop-filter: blur(18px) saturate(130%);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            border-radius: 14px 14px 0 0;
        }
        .stat-card.blue::before  { background: linear-gradient(90deg, var(--neon-blue), transparent); }
        .stat-card.green::before { background: linear-gradient(90deg, var(--neon-green), transparent); }
        .stat-card.pink::before  { background: linear-gradient(90deg, var(--neon-pink), transparent); }
        .stat-card.gold::before  { background: linear-gradient(90deg, var(--neon-gold), transparent); }

        .stat-label {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.45);
            margin-bottom: 8px;
        }
        .stat-value {
            font-family: 'Orbitron', sans-serif;
            font-size: 2.2rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 4px;
        }
        .stat-card.blue  .stat-value { color: var(--neon-blue);  text-shadow: 0 0 20px rgba(0,243,255,0.5); }
        .stat-card.green .stat-value { color: var(--neon-green); text-shadow: 0 0 20px rgba(0,255,136,0.5); }
        .stat-card.pink  .stat-value { color: var(--neon-pink);  text-shadow: 0 0 20px rgba(255,0,127,0.5); }
        .stat-card.gold  .stat-value { color: var(--neon-gold);  text-shadow: 0 0 20px rgba(255,215,0,0.5); }
        .stat-sub {
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.8rem;
            color: rgba(255,255,255,0.3);
        }

        /* Tabs */
        .admin-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            padding-bottom: 0;
        }
        .admin-tab-btn {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.65rem;
            letter-spacing: 2px;
            padding: 10px 20px;
            border: none;
            background: transparent;
            color: rgba(255,255,255,0.4);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: all 0.2s;
            border-radius: 6px 6px 0 0;
        }
        .admin-tab-btn:hover { color: rgba(255,255,255,0.7); }
        .admin-tab-btn.active {
            color: var(--neon-blue);
            border-bottom-color: var(--neon-blue);
            background: rgba(0,243,255,0.05);
        }
        .admin-tab-pane { display: none; }
        .admin-tab-pane.active { display: block; }

        /* Main content card */
        .admin-content-card {
            background: var(--glass-bg);
            backdrop-filter: blur(18px) saturate(130%);
            -webkit-backdrop-filter: blur(18px) saturate(130%);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 24px;
        }

        /* Action message */
        .action-toast {
            background: rgba(0,255,136,0.1);
            border: 1px solid rgba(0,255,136,0.3);
            border-radius: 8px;
            padding: 10px 16px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.72rem;
            color: var(--neon-green);
            letter-spacing: 1px;
            margin-bottom: 16px;
        }

        /* Search bar */
        .admin-search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .admin-search-bar input {
            flex: 1;
            min-width: 200px;
        }

        /* Player table */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Rajdhani', sans-serif;
        }
        .admin-table th {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            letter-spacing: 2px;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            text-align: left;
            white-space: nowrap;
        }
        .admin-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .admin-table tr:hover td { background: rgba(255,255,255,0.03); }
        .admin-table tr.blocked-row td { opacity: 0.5; }

        .status-chip {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.55rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .status-chip.active  { background: rgba(0,255,136,0.12); border: 1px solid rgba(0,255,136,0.3); color: var(--neon-green); }
        .status-chip.blocked { background: rgba(255,0,127,0.12);  border: 1px solid rgba(255,0,127,0.3);  color: var(--neon-pink); }
        .status-chip.offline { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.35); }

        .action-btn {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.55rem;
            letter-spacing: 1.5px;
            padding: 4px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .block-btn   { background: rgba(255,0,127,0.15); border: 1px solid rgba(255,0,127,0.35); color: var(--neon-pink); }
        .block-btn:hover { background: rgba(255,0,127,0.3); color: #fff; }
        .unblock-btn { background: rgba(0,255,136,0.12); border: 1px solid rgba(0,255,136,0.3); color: var(--neon-green); }
        .unblock-btn:hover { background: rgba(0,255,136,0.25); color: #fff; }

        /* Pagination */
        .admin-pagination {
            display: flex;
            gap: 6px;
            align-items: center;
            justify-content: center;
            padding-top: 18px;
            flex-wrap: wrap;
        }
        .page-chip {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            padding: 5px 12px;
            border-radius: 6px;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.5);
            transition: all 0.2s;
        }
        .page-chip:hover { border-color: var(--neon-blue); color: var(--neon-blue); }
        .page-chip.current { border-color: var(--neon-blue); color: var(--neon-blue); background: rgba(0,243,255,0.08); }
        .page-info {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.6rem;
            color: rgba(255,255,255,0.3);
            letter-spacing: 1px;
        }

        /* Leaderboard table inside admin */
        .lb-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Rajdhani', sans-serif;
        }
        .lb-table th {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.58rem;
            letter-spacing: 2px;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            text-align: left;
        }
        .lb-table td {
            padding: 9px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 0.95rem;
        }
        .lb-table tr:hover td { background: rgba(255,255,255,0.03); }
        .lb-rank {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.7rem;
            color: rgba(255,255,255,0.35);
            width: 36px;
        }
        .lb-rank.gold-rank   { color: var(--neon-gold); }
        .lb-rank.silver-rank { color: #c0c0c0; }
        .lb-rank.bronze-rank { color: #cd7f32; }
        .lb-score {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.85rem;
            color: var(--neon-blue);
        }
        .lb-wins { color: var(--neon-green); font-family: 'Orbitron', sans-serif; font-size: 0.8rem; }

        .lb-section-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.7rem;
            letter-spacing: 3px;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .lb-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        @media(max-width: 768px) {
            .lb-grid { grid-template-columns: 1fr; }
            .admin-table { font-size: 0.8rem; }
            .admin-table th, .admin-table td { padding: 8px 6px; }
        }

        /* Responsive table wrapper */
        .table-scroll { overflow-x: auto; }
    </style>
</head>
<body class="galaxy-bg cosmic-app-page" style="overflow-y:auto;">

<div class="nebula-layer" aria-hidden="true"></div>

<div class="container admin-shell" style="position:relative;z-index:1;">

    <!-- Top bar -->
    <div class="admin-topbar">
        <div class="admin-topbar-brand">
            <div class="admin-topbar-badge">&#9670; Admin Portal</div>
            <h1 class="admin-topbar-title">Cosmic Tetris</h1>
        </div>
        <div class="admin-topbar-right">
            <div class="admin-user-chip">Logged in as <span><?php echo $adminUsername; ?></span></div>
            <a href="logout.php" class="admin-logout-btn">Logout</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card blue">
            <div class="stat-label">Registered Players</div>
            <div class="stat-value"><?php echo number_format($totalPlayers); ?></div>
            <div class="stat-sub">Total accounts</div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Active Today</div>
            <div class="stat-value"><?php echo number_format($activePlayers); ?></div>
            <div class="stat-sub">Logged in last 24 h</div>
        </div>
        <div class="stat-card pink">
            <div class="stat-label">Blocked Players</div>
            <div class="stat-value"><?php echo number_format($blockedCount); ?></div>
            <div class="stat-sub">Access suspended</div>
        </div>
        <div class="stat-card gold">
            <div class="stat-label">Blitz Matches</div>
            <div class="stat-value"><?php echo number_format($blitzGames); ?></div>
            <div class="stat-sub">Completed games</div>
        </div>
    </div>

    <!-- Main content card -->
    <div class="admin-content-card">

        <!-- Tabs -->
        <div class="admin-tabs" role="tablist">
            <button class="admin-tab-btn active" onclick="switchTab('players', this)" role="tab">Players</button>
            <button class="admin-tab-btn" onclick="switchTab('leaderboard', this)" role="tab">Leaderboard</button>
        </div>

        <!-- Players tab -->
        <div id="tab-players" class="admin-tab-pane active">
            <form class="admin-search-bar" method="GET">
                <input type="text" name="search" class="form-control glass-input"
                       placeholder="Search by username or email..."
                       value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
                <button type="submit" class="btn neon-btn" style="white-space:nowrap;padding:8px 20px;font-size:0.7rem;">Search</button>
                <?php if ($search): ?>
                <a href="dashboard.php" class="btn" style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.15);color:rgba(255,255,255,0.5);font-size:0.7rem;padding:8px 14px;border-radius:8px;">Clear</a>
                <?php endif; ?>
            </form>

            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Best Score</th>
                            <th>Last Login</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($players)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:30px;color:rgba(255,255,255,0.3);font-family:'Orbitron',sans-serif;font-size:0.75rem;letter-spacing:2px;">
                                No players found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($players as $i => $p):
                            $isBlocked  = !empty($p['is_blocked']);
                            $rowNum     = ($page - 1) * $perPage + $i + 1;
                            $lastLogin  = $p['last_login'] ? date('Y-m-d H:i', strtotime($p['last_login'])) : '—';
                            $isActiveToday = $p['last_login'] && strtotime($p['last_login']) > strtotime('-24 hours');
                            if ($isBlocked) {
                                $statusClass = 'blocked';
                                $statusLabel = 'Blocked';
                            } elseif ($isActiveToday) {
                                $statusClass = 'active';
                                $statusLabel = 'Active';
                            } else {
                                $statusClass = 'offline';
                                $statusLabel = 'Offline';
                            }
                        ?>
                        <tr class="<?php echo $isBlocked ? 'blocked-row' : ''; ?>">
                            <td style="color:rgba(255,255,255,0.25);font-size:0.75rem;"><?php echo $rowNum; ?></td>
                            <td style="font-family:'Orbitron',sans-serif;font-size:0.78rem;color:#fff;">
                                <?php echo htmlspecialchars($p['username'], ENT_QUOTES); ?>
                            </td>
                            <td style="color:rgba(255,255,255,0.5);font-size:0.82rem;">
                                <?php echo htmlspecialchars($p['email'], ENT_QUOTES); ?>
                            </td>
                            <td style="font-family:'Orbitron',sans-serif;font-size:0.78rem;color:var(--neon-blue);">
                                <?php echo $p['score'] !== null ? number_format((int)$p['score']) : '—'; ?>
                            </td>
                            <td style="font-size:0.8rem;color:rgba(255,255,255,0.4);"><?php echo $lastLogin; ?></td>
                            <td><span class="status-chip <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo $isBlocked ? 'Unblock' : 'Block'; ?> <?php echo htmlspecialchars(addslashes($p['username']), ENT_QUOTES); ?>?')">
                                    <input type="hidden" name="admin_csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf'] ?? '', ENT_QUOTES); ?>">
                                    <input type="hidden" name="target_username" value="<?php echo htmlspecialchars($p['username'], ENT_QUOTES); ?>">
                                    <?php if ($isBlocked): ?>
                                    <input type="hidden" name="action" value="unblock">
                                    <button type="submit" class="action-btn unblock-btn">Unblock</button>
                                    <?php else: ?>
                                    <input type="hidden" name="action" value="block">
                                    <button type="submit" class="action-btn block-btn">Block</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="admin-pagination">
                <?php
                $baseUrl = 'dashboard.php?' . ($search ? 'search=' . urlencode($search) . '&' : '');
                if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="page-chip">&lsaquo; Prev</a>
                <?php endif;
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                for ($pg = $start; $pg <= $end; $pg++): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $pg; ?>" class="page-chip <?php echo $pg === $page ? 'current' : ''; ?>"><?php echo $pg; ?></a>
                <?php endfor;
                if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="page-chip">Next &rsaquo;</a>
                <?php endif; ?>
                <span class="page-info">&nbsp; <?php echo number_format($totalRows); ?> total</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Leaderboard tab -->
        <div id="tab-leaderboard" class="admin-tab-pane">
            <div class="lb-grid">

                <!-- Solo -->
                <div>
                    <div class="lb-section-title">&#9733; Solo — Top 10</div>
                    <div class="table-scroll">
                        <table class="lb-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Player</th>
                                    <th>Score</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($soloBoard)): ?>
                                <tr><td colspan="3" style="color:rgba(255,255,255,0.3);font-size:0.75rem;padding:20px;text-align:center;">No data</td></tr>
                            <?php else: ?>
                                <?php foreach ($soloBoard as $idx => $row):
                                    $rank = $idx + 1;
                                    $rankClass = $rank === 1 ? 'gold-rank' : ($rank === 2 ? 'silver-rank' : ($rank === 3 ? 'bronze-rank' : ''));
                                ?>
                                <tr>
                                    <td class="lb-rank <?php echo $rankClass; ?>"><?php echo $rank; ?></td>
                                    <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?></td>
                                    <td class="lb-score"><?php echo number_format((int)$row['score']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Blitz -->
                <div>
                    <div class="lb-section-title">&#9889; Blitz — Top 10</div>
                    <div class="table-scroll">
                        <table class="lb-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Player</th>
                                    <th>Wins</th>
                                    <th>Losses</th>
                                    <th>Games</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($blitzBoard)): ?>
                                <tr><td colspan="5" style="color:rgba(255,255,255,0.3);font-size:0.75rem;padding:20px;text-align:center;">No data</td></tr>
                            <?php else: ?>
                                <?php foreach ($blitzBoard as $idx => $row):
                                    $rank = $idx + 1;
                                    $rankClass = $rank === 1 ? 'gold-rank' : ($rank === 2 ? 'silver-rank' : ($rank === 3 ? 'bronze-rank' : ''));
                                ?>
                                <tr>
                                    <td class="lb-rank <?php echo $rankClass; ?>"><?php echo $rank; ?></td>
                                    <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?></td>
                                    <td class="lb-wins"><?php echo (int)$row['wins']; ?></td>
                                    <td style="color:var(--neon-pink);font-family:'Orbitron',sans-serif;font-size:0.8rem;"><?php echo (int)$row['losses']; ?></td>
                                    <td style="color:rgba(255,255,255,0.4);font-size:0.8rem;"><?php echo (int)$row['total_games']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
        <!-- end tabs -->

    </div><!-- end admin-content-card -->
</div><!-- end container -->

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.admin-tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.admin-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
}

// Keep active tab after page reload (search / block action)
(function() {
    const saved = sessionStorage.getItem('adminTab');
    if (saved) {
        const btn = document.querySelector('.admin-tab-btn[onclick*="' + saved + '"]');
        if (btn) switchTab(saved, btn);
    }
    document.querySelectorAll('.admin-tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const match = this.getAttribute('onclick').match(/'(\w+)'/);
            if (match) sessionStorage.setItem('adminTab', match[1]);
        });
    });
})();
</script>

</body>
</html>
