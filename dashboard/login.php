<?php
require_once __DIR__ . '/../backEnd/session_bootstrap.php';
include '../backEnd/tetrisgame.php';

if (isset($_POST['login'])) {
    if (empty($_POST['email']) || empty($_POST['password'])) {
        $_SESSION['msg'] = "All fields are required.";
        $_SESSION['old_login_email'] = $_POST['email'] ?? '';
    } else {
        $auth = new tetrisgame();
        $auth->LoginUser($_POST['email'], $_POST['password']);
    }
}
$oldLoginEmail = $_SESSION['old_login_email'] ?? ($_POST['email'] ?? '');
unset($_SESSION['old_login_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
</head>
<body class="galaxy-bg cosmic-app-page auth-page">

<div class="nebula-layer" aria-hidden="true"></div>

<main class="container cosmic-app-shell">
    <section class="cosmic-card auth-card page-enter" aria-labelledby="login-title">

        <div class="auth-brand">
            <div class="cosmic-kicker">Access Terminal</div>
            <h1 class="text-white cosmic-title glitch" id="login-title" data-text="Cosmic Tetris">Cosmic Tetris</h1>
            <p class="cosmic-subtitle">
                Enter the void
            </p>
        </div>

        <form method="POST">
            <div class="mb-3">
                <label class="cosmic-label" for="login-email">Email Address</label>
                <input type="email" id="login-email" name="email" class="form-control glass-input w-100"
                       placeholder="Email Address" value="<?php echo htmlspecialchars($oldLoginEmail, ENT_QUOTES); ?>" required autocomplete="email">
            </div>
            <div class="mb-4">
                <label class="cosmic-label" for="login-password">Password</label>
                <input type="password" id="login-password" name="password" class="form-control glass-input w-100"
                       placeholder="Password" required autocomplete="current-password">
            </div>

            <?php if (!empty($_SESSION['msg'])): ?>
            <div class="mb-3" style="color:var(--neon-pink);font-size:0.85rem;font-family:'Orbitron',sans-serif;letter-spacing:1px;">
                <?php echo htmlspecialchars($_SESSION['msg'], ENT_QUOTES); unset($_SESSION['msg']); ?>
            </div>
            <?php endif; ?>

            <button type="submit" name="login" class="btn neon-btn w-100 mb-3">Login</button>
            <p class="mb-0 cosmic-link-copy" style="font-size:0.85rem;">
                No account? <a href="register.php" style="color:var(--neon-blue);text-decoration:none;">Register here</a>
            </p>
        </form>
    </section>
</main>
</body>
</html>
