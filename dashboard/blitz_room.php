<?php require __DIR__ . '/blitz_room/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blitz Match - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
    <link rel="stylesheet" href="../css/blitz_room.css?v=<?php echo filemtime(__DIR__ . '/../css/blitz_room.css'); ?>">
</head>
<body class="galaxy-bg blitz-arena-bg">

<?php
require __DIR__ . '/blitz_room/phase_error.php';
require __DIR__ . '/blitz_room/phase_cooldown.php';
require __DIR__ . '/blitz_room/phase_waiting.php';
require __DIR__ . '/blitz_room/phase_ready.php';
require __DIR__ . '/blitz_room/phase_countdown.php';
require __DIR__ . '/blitz_room/phase_game.php';
require __DIR__ . '/blitz_room/phase_result.php';
require __DIR__ . '/blitz_room/boot_script.php';
?>
</body>
</html>
