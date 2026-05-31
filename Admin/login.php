<?php
require_once __DIR__ . '/../backEnd/admin_session.php';

// Already logged in → go to dashboard
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../backEnd/AdminAuth.php';

$error = '';

if (isset($_POST['admin_login'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'All fields are required.';
    } else {
        $auth   = new AdminAuth();
        $result = $auth->loginAdmin($email, $password);

        if ($result['success']) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id']        = $result['admin']['id'];
            $_SESSION['admin_username']  = $result['admin']['username'];
            header('Location: dashboard.php');
            exit();
        } else {
            $error = $result['message'];
        }
    }
}

$oldEmail = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
    <style>
        .admin-badge {
            display: inline-block;
            padding: 3px 12px;
            background: linear-gradient(135deg, rgba(255,0,127,0.2), rgba(176,38,255,0.2));
            border: 1px solid rgba(255,0,127,0.4);
            border-radius: 20px;
            font-family: 'Orbitron', sans-serif;
            font-size: 0.65rem;
            letter-spacing: 3px;
            color: var(--neon-pink);
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .admin-login-divider {
            border-top: 1px solid rgba(255,0,127,0.15);
            margin: 20px 0;
        }
        .glass-input:focus {
            border-color: var(--neon-pink) !important;
            box-shadow: 0 0 0 2px rgba(255,0,127,0.15) !important;
        }
        .admin-neon-btn {
            background: linear-gradient(135deg, rgba(255,0,127,0.15), rgba(176,38,255,0.15));
            border: 1px solid rgba(255,0,127,0.5);
            color: var(--neon-pink);
        }
        .admin-neon-btn:hover {
            background: linear-gradient(135deg, rgba(255,0,127,0.3), rgba(176,38,255,0.3));
            border-color: var(--neon-pink);
            color: #fff;
            box-shadow: 0 0 20px rgba(255,0,127,0.4);
        }
    </style>
</head>
<body class="galaxy-bg cosmic-app-page auth-page">

<div class="nebula-layer" aria-hidden="true"></div>

<main class="container cosmic-app-shell">
    <section class="cosmic-card auth-card page-enter" aria-labelledby="admin-login-title">

        <div class="auth-brand">
            <div class="admin-badge">&#9670; Admin Portal &#9670;</div>
            <h1 class="text-white cosmic-title glitch" id="admin-login-title" data-text="Cosmic Tetris">Cosmic Tetris</h1>
            <p class="cosmic-subtitle">Restricted access — administrators only</p>
        </div>

        <div class="admin-login-divider"></div>

        <form method="POST" novalidate>
            <div class="mb-3">
                <label class="cosmic-label" for="admin-email">Admin Email</label>
                <input type="email" id="admin-email" name="email" class="form-control glass-input w-100"
                       placeholder="admin@domain.com" value="<?php echo $oldEmail; ?>"
                       required autocomplete="email">
            </div>
            <div class="mb-4">
                <label class="cosmic-label" for="admin-password">Password</label>
                <input type="password" id="admin-password" name="password" class="form-control glass-input w-100"
                       placeholder="Password" required autocomplete="current-password">
            </div>

            <?php if ($error): ?>
            <div class="mb-3" style="color:var(--neon-pink);font-size:0.85rem;font-family:'Orbitron',sans-serif;letter-spacing:1px;">
                <?php echo htmlspecialchars($error, ENT_QUOTES); ?>
            </div>
            <?php endif; ?>

            <button type="submit" name="admin_login" class="btn neon-btn admin-neon-btn w-100 mb-3">
                Access Admin Panel
            </button>
            <p class="mb-0 cosmic-link-copy" style="font-size:0.85rem;">
                New admin? <a href="register.php" style="color:var(--neon-pink);text-decoration:none;">Register here</a>
            </p>
        </form>
    </section>
</main>
</body>
</html>
