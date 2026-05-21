<div id="gamePhase"
     class="container-fluid min-vh-100 d-flex d-none flex-column justify-content-center align-items-center blitz-game-stage">

    <div class="blitz-match-hud">
        <div class="blitz-score-card">
            <span>You</span>
            <strong id="myScoreDisplay">0</strong>
        </div>
        <div class="blitz-score-card blitz-score-card-warn">
            <span id="oppNameHeader">Opponent</span>
            <strong id="oppScoreDisplay">0</strong>
        </div>
    </div>

    <div class="blitz-arena-layout">
        <section class="blitz-board-column blitz-board-column-main" aria-label="Your Tetris board">
            <div class="blitz-board-label">
                <span>You</span>
                <small>Attack board</small>
            </div>
            <div class="blitz-board-frame blitz-board-frame-main">
                <div class="blitz-board-timer" aria-label="Blitz timer">
                    <span>Blitz</span>
                    <strong id="timerDisplay" class="blitz-timer">2:00</strong>
                </div>
                <canvas id="myCanvas" class="blitz-canvas-me"></canvas>
                <div id="garbageAlert" class="blitz-garbage-alert" style="display:none">INCOMING!</div>
            </div>
        </section>

        <aside class="blitz-side-console" aria-label="Match controls">
            <div class="blitz-next-panel">
                <div class="blitz-panel-label">Next</div>
                <div id="nextPiece" class="blitz-next-piece"></div>
            </div>
            <div class="blitz-control-card">
                <div class="blitz-panel-label">Controls</div>
                <dl>
                    <div><dt>Move</dt><dd>Left / Right</dd></div>
                    <div><dt>Rotate</dt><dd>Up</dd></div>
                    <div><dt>Drop</dt><dd>Down</dd></div>
                    <div><dt>Slam</dt><dd>Space</dd></div>
                </dl>
            </div>
        </aside>

        <section class="blitz-board-column blitz-board-column-opp" aria-label="Opponent Tetris board">
            <div class="blitz-board-label blitz-board-label-warn">
                <span id="oppNameGame">Opponent</span>
                <small>Rival board</small>
            </div>
            <div class="blitz-board-frame blitz-board-frame-opp">
                <canvas id="oppCanvas" class="blitz-canvas-opp"></canvas>
            </div>
        </section>
    </div>

    <div class="blitz-mobile-controls d-md-none" aria-label="Touch controls">
        <button class="btn blitz-touch-btn" onclick="move(-1)" aria-label="Move left">Left</button>
        <button class="btn blitz-touch-btn" onclick="rotateTetromino()" aria-label="Rotate">Turn</button>
        <button class="btn blitz-touch-btn" onclick="hardDrop()" aria-label="Hard drop">Drop</button>
        <button class="btn blitz-touch-btn" onclick="move(1)" aria-label="Move right">Right</button>
    </div>
</div>
