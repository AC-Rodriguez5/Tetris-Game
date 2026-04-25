<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Play - Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="galaxy-bg">
    <div class="container-fluid min-vh-100 d-flex flex-column justify-content-center align-items-center py-4">
        <h2 class="text-white mb-4 cosmic-title">Cosmic Tetris</h2>
        <div class="row g-4 align-items-stretch justify-content-center w-100" style="max-width: 1000px;">
            <!-- Score Panel -->
            <div class="col-12 col-md-2">
                <div class="glass-card p-4 h-100 d-flex flex-column justify-content-center text-center">
                    <h6 class="text-info text-uppercase mb-2">Score</h6>
                    <h3 id="score" class="text-white mb-0">Score: 0</h3>
                </div>
            </div>
            
            <!-- Game Board -->
            <div class="col-12 col-md-6 d-flex justify-content-center">
                <div class="glass-card p-3">
                    <div class="tetris-board">
                        <canvas id="tetrisCanvas"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Next Piece & Controls -->
            <div class="col-12 col-md-2">
                <div class="glass-card p-4 h-100 d-flex flex-column">
                    <div class="text-center mb-3">
                        <h6 class="text-info text-uppercase mb-3">Next</h6>
                        <div class="next-piece-box mx-auto" id="nextPiece"></div>
                    </div>
                    <div class="mt-auto">
                        <button class="btn neon-btn w-100" onclick="restartGame()">Restart</button>
                        <button class="btn btn-outline-light w-100" onclick="quitGame()">Quit</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="../script/script.js"></script>
</body>
</html>
