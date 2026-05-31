<?php
require_once __DIR__ . '/../backEnd/admin_session.php';

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../backEnd/AdminAuth.php';

$error   = '';
$success = '';
$old     = ['username' => '', 'email' => ''];

if (isset($_POST['admin_register'])) {
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $repassword = $_POST['repassword'] ?? '';
    $secretKey = $_POST['secret_key'] ?? '';
    $old = ['username' => $username, 'email' => $email];

    if ($username === '' || $email === '' || $password === '' || $repassword === '' || $secretKey === '') {
        $error = 'All fields are required.';
    } else {
        $auth   = new AdminAuth();
        $result = $auth->registerAdmin($username, $email, $password, $repassword, $secretKey);
        if ($result['success']) {
            $success = $result['message'];
            $old = ['username' => '', 'email' => ''];
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Register — Cosmic Tetris</title>
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
        .key-hint {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.35);
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 1px;
            margin-top: 4px;
        }
    </style>
</head>
<body class="galaxy-bg cosmic-app-page auth-page">

<div class="nebula-layer" aria-hidden="true"></div>

<main class="container cosmic-app-shell">
    <section class="cosmic-card auth-card auth-card-wide page-enter" aria-labelledby="admin-reg-title">

        <div class="auth-brand">
            <div class="admin-badge">&#9670; Admin Registration &#9670;</div>
            <h1 class="text-white cosmic-title" id="admin-reg-title">Create Admin</h1>
            <p class="cosmic-subtitle">Requires the admin access key</p>
        </div>

        <div class="admin-login-divider"></div>

        <?php if ($success): ?>
        <div class="mb-4" style="color:var(--neon-green);font-size:0.85rem;font-family:'Orbitron',sans-serif;letter-spacing:1px;text-align:center;">
            <?php echo htmlspecialchars($success, ENT_QUOTES); ?>
            <div class="mt-2"><a href="login.php" style="color:var(--neon-blue);text-decoration:none;">Go to Login &rarr;</a></div>
        </div>
        <?php else: ?>

        <form method="POST" id="admin-reg-form" novalidate>
            <div class="mb-3">
                <label class="cosmic-label" for="reg-username">Username</label>
                <input type="text" id="reg-username" name="username" class="form-control glass-input w-100"
                       placeholder="Admin username" value="<?php echo htmlspecialchars($old['username'], ENT_QUOTES); ?>"
                       required minlength="3" autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="cosmic-label" for="reg-email">Email Address</label>
                <input type="email" id="reg-email" name="email" class="form-control glass-input w-100"
                       placeholder="admin@domain.com" value="<?php echo htmlspecialchars($old['email'], ENT_QUOTES); ?>"
                       required autocomplete="email">
            </div>
            <div class="mb-3">
                <label class="cosmic-label" for="reg-password">Password</label>
                <input type="password" id="reg-password" name="password" class="form-control glass-input w-100"
                       placeholder="Min. 6 characters" required minlength="6" autocomplete="new-password">
            </div>
            <div class="mb-3">
                <label class="cosmic-label" for="reg-repassword">Confirm Password</label>
                <input type="password" id="reg-repassword" name="repassword" class="form-control glass-input w-100"
                       placeholder="Repeat password" required minlength="6" autocomplete="new-password">
            </div>
            <div class="mb-4">
                <label class="cosmic-label" for="reg-key">Admin Access Key</label>
                <input type="password" id="reg-key" name="secret_key" class="form-control glass-input w-100"
                       placeholder="Secret access key" required autocomplete="off">
                <p class="key-hint">Contact your system administrator for the access key.</p>
            </div>

            <?php if ($error): ?>
            <div class="mb-3" style="color:var(--neon-pink);font-size:0.85rem;font-family:'Orbitron',sans-serif;letter-spacing:1px;">
                <?php echo htmlspecialchars($error, ENT_QUOTES); ?>
            </div>
            <?php endif; ?>

            <button type="submit" name="admin_register" class="btn neon-btn admin-neon-btn w-100 mb-3">
                Create Admin Account
            </button>
            <p class="mb-0 cosmic-link-copy" style="font-size:0.85rem;">
                Already have an account? <a href="login.php" style="color:var(--neon-pink);text-decoration:none;">Login here</a>
            </p>
        </form>

        <script>
        (function() {
            const form = document.getElementById('admin-reg-form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                const pw  = document.getElementById('reg-password');
                const rpw = document.getElementById('reg-repassword');
                if (!form.checkValidity()) { e.preventDefault(); return; }
                if (pw.value !== rpw.value) {
                    e.preventDefault();
                    rpw.setCustomValidity('Passwords do not match.');
                    rpw.reportValidity();
                } else {
                    rpw.setCustomValidity('');
                }
            });
        })();
        </script>

        <?php endif; ?>
    </section>
</main>
</body>
</html>
