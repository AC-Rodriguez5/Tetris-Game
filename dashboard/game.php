<?php
require_once __DIR__ . '/../backEnd/session_bootstrap.php';
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header("Location: login.php"); exit();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$_SESSION['solo_game_started_at'] = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <title>Play — Cosmic Tetris</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo filemtime(__DIR__ . '/../css/style.css'); ?>">
</head>
<body class="galaxy-bg solo-game-page">

<div class="nebula-layer"></div>
<div class="particle-field" id="particle-field" style="position:fixed;inset:0;pointer-events:none;z-index:1;overflow:hidden;"></div>
<div class="level-up-banner" id="level-up-banner"></div>

<div class="combo-display" id="combo-display" hidden>
    <div class="combo-x">COMBO</div>
    <div class="combo-num">0</div>
    <div class="combo-label">x Clear</div>
</div>

<button class="audio-toggle" id="audio-toggle-btn" onclick="toggleAudio()" title="Mute Audio">
    <svg class="icon-sound" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
        <path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path>
        <path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path>
    </svg>
    <svg class="icon-mute" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
        <line x1="23" y1="9" x2="17" y2="15"></line>
        <line x1="17" y1="9" x2="23" y2="15"></line>
    </svg>
</button>

<div class="game-over-overlay" id="game-over-overlay" role="dialog" aria-modal="true" aria-labelledby="game-over-title" aria-hidden="true" hidden>
    <div class="game-over-card" tabindex="-1">
        <div class="game-over-title" id="game-over-title">GAME OVER</div>
        <div class="mt-3 mb-2">
            <div class="game-over-score-label">FINAL SCORE</div>
            <div class="game-over-score-num">0</div>
        </div>
        <div class="d-flex gap-3 justify-content-center mt-4 flex-wrap">
            <button class="btn neon-btn" id="game-over-restart" style="margin-bottom:0" onclick="restartGame()">Play Again</button>
            <button class="btn neon-btn" id="game-over-exit" style="margin-bottom:0;border-color:#ff6b35;color:#ff6b35" onclick="quitGame()">Exit</button>
        </div>
    </div>
</div>

<div class="container-fluid solo-game-shell d-flex flex-column justify-content-center align-items-center page-enter">
    <h2 class="text-white mb-4 cosmic-title glitch solo-game-title" data-text="Cosmic Tetris">Cosmic Tetris</h2>
    <div class="row g-4 align-items-stretch justify-content-center w-100" style="max-width:1100px">

        <div class="col-12 col-md-2 d-flex flex-column gap-3 stagger-children">
            <div class="glass-card p-3 text-center">
                <div class="hud-panel">
                    <div class="hud-label">Score</div>
                    <div class="hud-value" id="hud-score">0</div>
                </div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="hud-panel">
                    <div class="hud-label">Level</div>
                    <div class="hud-value" id="hud-level">1</div>
                </div>
            </div>
            <div class="glass-card p-3 text-center">
                <div class="hud-panel">
                    <div class="hud-label">Lines</div>
                    <div class="hud-value" id="hud-lines">0</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 d-flex justify-content-center align-items-center">
            <div class="glass-card p-3">
                <div class="tetris-board-wrapper">
                    <div class="tetris-board">
                        <canvas id="tetrisCanvas" tabindex="0" aria-label="Cosmic Tetris game board"></canvas>
                        <canvas id="particleCanvas"></canvas>
                    </div>
                </div>
                <div class="solo-mobile-controls" aria-label="Touch controls">
                    <button type="button" class="solo-touch-btn" data-touch-action="left" aria-label="Move left">&larr;</button>
                    <button type="button" class="solo-touch-btn" data-touch-action="rotate" aria-label="Rotate">&#8635;</button>
                    <button type="button" class="solo-touch-btn" data-touch-action="down" aria-label="Soft drop">&darr;</button>
                    <button type="button" class="solo-touch-btn" data-touch-action="drop" aria-label="Hard drop">&#10515;</button>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-2">
            <div class="glass-card p-4 h-100 d-flex flex-column">
                <div class="text-center mb-4">
                    <div class="hud-label mb-2">Next</div>
                    <div class="next-piece-box mx-auto" id="nextPiece"></div>
                </div>
                <div class="mb-4 flex-grow-1">
                    <div class="hud-label mb-2">Controls</div>
                    <div class="controls-help" style="font-size:0.65rem;line-height:1.9;font-family:'Orbitron',sans-serif;letter-spacing:1px;">
                        &larr; &rarr;&nbsp; Move<br>
                        &uarr; / Z&nbsp; Rotate<br>
                        &darr;&nbsp;&nbsp;&nbsp;&nbsp; Soft Drop<br>
                        Space Hard Drop
                    </div>
                </div>
                <div class="d-grid gap-2 mt-auto">
                    <button class="btn neon-btn w-100" style="margin-bottom:0" onclick="restartGame()">Restart</button>
                    <button class="btn neon-btn w-100" style="margin-bottom:0;border-color:rgba(255,0,127,0.5);color:rgba(255,0,127,0.7)" onclick="quitGame()">Quit</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../script/script.js"></script>
<script>
(function(){
    var field=document.getElementById('particle-field');if(!field)return;
    var colors=['#00f3ff','#b026ff','#ff007f','#00ff88','#ffd700'];
    for(var i=0;i<28;i++){
        var p=document.createElement('div');p.className='particle';
        var size=2+Math.random()*3;
        p.style.cssText='position:absolute;border-radius:50%;opacity:0;animation:float-up linear infinite;width:'+size+'px;height:'+size+'px;background:'+colors[i%colors.length]+';box-shadow:0 0 '+(size*3)+'px '+colors[i%colors.length]+';left:'+(Math.random()*100)+'%;animation-duration:'+(8+Math.random()*14)+'s;animation-delay:-'+(Math.random()*12)+'s;';
        field.appendChild(p);
    }
})();
</script>
</body>
</html>
