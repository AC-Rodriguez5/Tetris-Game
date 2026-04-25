<?php
session_start();
include '../backEnd/tetrisgame.php';

if (isset($_POST['login'])) {
    if(empty($_POST['email']) || empty($_POST['password'])) {
        $_SESSION['msg']="All fields are required.";
    } else {
        $auth = new tetrisgame();
        $auth->LoginUser($_POST['email'], $_POST['password']);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="galaxy-bg">
    <div class="container min-vh-100 d-flex flex-column justify-content-center align-items-center">
        <div class="glass-card p-5 w-100 text-center" style="max-width: 400px;">
            <h2 class="text-white mb-4 cosmic-title">Cosmic Tetris</h2>
            <form method="POST">
                <div class="mb-3">
                    <input type="email" name="email" class="form-control glass-input" placeholder="Email Address" required>
                </div>
                <div class="mb-4">
                    <input type="password" name="password" class="form-control glass-input" placeholder="Password" required>
                </div>
                <button type="submit" name="login" class="btn neon-btn w-100 mb-3">Login</button>
                <p class="text-light-50 small mb-0">Don't have an account? <a href="register.php" class="text-info">Register here</a></p>
                <p class="text-light-50 small mb-0"><?php echo $_SESSION['msg'] ?? '';
                unset($_SESSION['msg']); ?></p>
            </form>
        </div>
    </div>
</body>
</html>
