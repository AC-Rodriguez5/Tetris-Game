<?php
require_once __DIR__ . '/../backEnd/session_bootstrap.php';
include '../backEnd/tetrisgame.php';

if (isset($_POST['register'])) {
    if (empty($_POST['email']) || empty($_POST['username']) || empty($_POST['password']) || empty($_POST['repassword'])) {
        $_SESSION['msg'] = "All fields are required.";
        $_SESSION['old_register'] = [
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
        ];
    } else {
        $auth = new tetrisgame();
        $auth->RegisterUser($_POST['email'], $_POST['username'], $_POST['password'], $_POST['repassword']);
    }
}
$oldRegister = $_SESSION['old_register'] ?? [
    'username' => $_POST['username'] ?? '',
    'email' => $_POST['email'] ?? '',
];
unset($_SESSION['old_register']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
</head>
<body class="galaxy-bg cosmic-app-page auth-page">

<div class="nebula-layer" aria-hidden="true"></div>

<main class="container cosmic-app-shell">
    <section class="cosmic-card auth-card auth-card-wide page-enter" aria-labelledby="register-title">

        <div class="auth-brand">
            <div class="cosmic-kicker">New Commander</div>
            <h1 class="text-white cosmic-title" id="register-title">Create Account</h1>
            <p class="cosmic-subtitle">
                Join the cosmos
            </p>
        </div>

        <form method="POST" id="register-form" novalidate>
            <div class="mb-3">
                <label class="cosmic-label" for="register-username">Commander Name</label>
                <input type="text" id="register-username" name="username" class="form-control glass-input w-100"
                       placeholder="Commander Name" value="<?php echo htmlspecialchars($oldRegister['username'] ?? '', ENT_QUOTES); ?>" required minlength="3" autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="cosmic-label" for="register-email">Email Address</label>
                <input type="email" id="register-email" name="email" class="form-control glass-input w-100"
                       placeholder="Email Address" value="<?php echo htmlspecialchars($oldRegister['email'] ?? '', ENT_QUOTES); ?>" required autocomplete="email">
            </div>
            <div class="mb-3">
                <label class="cosmic-label" for="register-password">Password</label>
                <input type="password" id="register-password" name="password" class="form-control glass-input w-100"
                       placeholder="Password" required minlength="6" autocomplete="new-password">
            </div>
            <div class="mb-4">
                <label class="cosmic-label" for="register-repassword">Confirm Password</label>
                <input type="password" id="register-repassword" name="repassword" class="form-control glass-input w-100"
                       placeholder="Confirm Password" required minlength="6" autocomplete="new-password">
            </div>
            <div class="mb-3" id="register-inline-error" aria-live="polite" style="display:none;color:var(--neon-pink);font-size:0.85rem;font-family:'Orbitron',sans-serif;letter-spacing:1px;"></div>

            <?php if (!empty($_SESSION['msg'])): ?>
            <div class="mb-3" style="color:var(--neon-pink);font-size:0.85rem;font-family:'Orbitron',sans-serif;letter-spacing:1px;">
                <?php echo htmlspecialchars($_SESSION['msg'], ENT_QUOTES); unset($_SESSION['msg']); ?>
            </div>
            <?php endif; ?>

            <button type="submit" name="register" class="btn neon-btn w-100 mb-3">Register</button>
            <p class="mb-0 cosmic-link-copy" style="font-size:0.85rem;">
                Already have an account? <a href="login.php" style="color:var(--neon-blue);text-decoration:none;">Login here</a>
            </p>
        </form>
    </section>
</main>

<script>
(function() {
    const form = document.getElementById('register-form');
    const err = document.getElementById('register-inline-error');
    if (!form || !err) return;
    form.addEventListener('submit', function(e) {
        const username = document.getElementById('register-username');
        const password = document.getElementById('register-password');
        const confirm = document.getElementById('register-repassword');
        err.style.display = 'none';
        err.textContent = '';
        if (!form.checkValidity()) {
            e.preventDefault();
            err.textContent = 'Complete all fields. Passwords need at least 6 characters.';
            err.style.display = 'block';
            (username && !username.validity.valid ? username : form.querySelector(':invalid'))?.focus();
            return;
        }
        if (password.value !== confirm.value) {
            e.preventDefault();
            err.textContent = 'Passwords do not match.';
            err.style.display = 'block';
            confirm.focus();
        }
    });
})();

</script>
</body>
</html>
