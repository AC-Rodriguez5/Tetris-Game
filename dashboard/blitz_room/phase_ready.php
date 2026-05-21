<div id="readyPhase" style="display:none"
     class="container min-vh-100 d-flex flex-column justify-content-center align-items-center py-4 blitz-stage">
    <div class="glass-card blitz-arena-card text-center">
        <div class="blitz-kicker mb-2">Versus locked</div>
        <h2 class="cosmic-title text-white mb-4">Opponent Found</h2>

        <div class="blitz-versus-row mb-4">
            <div class="blitz-player-plate">
                <span>You</span>
                <strong><?php echo htmlspecialchars($currentUsername); ?></strong>
            </div>
            <div class="blitz-vs-mark">VS</div>
            <div class="blitz-player-plate blitz-player-plate-warn">
                <span>Opponent</span>
                <strong id="oppNameReady">Opponent</strong>
            </div>
        </div>

        <div id="readyRoomCodeWrap" class="mb-4">
            <p class="text-info mb-1 blitz-room-label">Room Code</p>
            <div id="readyRoomCode" class="blitz-room-code blitz-room-code-small"></div>
        </div>
        <div class="blitz-ready-list mb-3" aria-live="polite">
            <div id="myReadyLabel" class="text-white-50 mb-1">You: Not ready</div>
            <div id="oppReadyLabel" class="text-white-50">Opponent: Not ready</div>
        </div>
        <div class="blitz-ready-timer-wrap mb-4">
            <div class="blitz-ready-timer-bar">
                <div id="readyTimerBar" class="blitz-ready-timer-fill"></div>
            </div>
            <small class="text-warning mt-1">
                Ready up within <span id="readyTimerDisplay">20s</span>
            </small>
        </div>
        <button id="readyBtn" class="btn neon-btn neon-blitz btn-large w-100 mb-0"
                onclick="sendReady()">Ready!</button>
    </div>
</div>
