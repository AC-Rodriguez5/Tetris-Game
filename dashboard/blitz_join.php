<?php
session_start();
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header("Location: login.php"); exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Blitz Room - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="galaxy-bg">

<div class="container min-vh-100 d-flex flex-column justify-content-center align-items-center py-4">
    <div class="glass-card blitz-menu-card text-center">
        <a class="blitz-back-btn mb-4" href="multiplayer.php">Back</a>
        <div class="blitz-kicker mb-2">Private room</div>
        <h2 class="cosmic-title text-white mb-4">Join Room</h2>

        <form id="joinRoomForm">
            <input type="text" id="joinCode"
                class="form-control text-center fw-bold glass-input blitz-code-input"
                placeholder="ROOM CODE"
                maxlength="6"
                autocomplete="off"
                oninput="this.value=this.value.toUpperCase().replace(/[^A-Z]/g,'')">

            <button class="btn neon-btn neon-blitz btn-large w-100 mt-4 mb-0" type="submit">
                Join
            </button>
        </form>

        <div id="joinError" class="text-warning mt-4" style="display:none;font-size:0.9rem"></div>
    </div>
</div>

<script>
document.getElementById('joinRoomForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const input = document.getElementById('joinCode');
    const error = document.getElementById('joinError');
    const code = input.value.trim().toUpperCase().replace(/[^A-Z]/g, '');
    input.value = code;

    if (code.length !== 6) {
        error.textContent = 'Enter the 6-letter room code.';
        error.style.display = '';
        return;
    }

    window.location.href = 'blitz_room.php?mode=join&code=' + encodeURIComponent(code);
});
</script>
</body>
</html>
