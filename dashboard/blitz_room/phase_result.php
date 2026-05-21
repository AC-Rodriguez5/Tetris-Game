<div id="resultPhase"
     class="container min-vh-100 d-flex d-none flex-column justify-content-center align-items-center py-4 blitz-stage">
    <div class="glass-card blitz-arena-card text-center">
        <div class="blitz-kicker mb-2">Match complete</div>
        <h1 id="resultTitle" class="blitz-result-title mb-4"></h1>
        <div class="blitz-result-grid mb-4">
            <div class="blitz-player-plate">
                <span>You</span>
                <strong id="finalMyScore">0</strong>
            </div>
            <div class="blitz-player-plate blitz-player-plate-warn">
                <span id="finalOppNameLabel">Opponent</span>
                <strong id="finalOppScore">0</strong>
            </div>
        </div>
        <div id="rematchStatus" class="text-info mb-2 small" style="display:none"></div>
        <div class="d-grid gap-2">
            <button id="rematchBtn" class="btn neon-btn neon-blitz btn-large" onclick="startRematch()">Rematch</button>
            <button class="btn btn-outline-light" onclick="location.href='blitz_room.php?mode=quick'">Find Another Match</button>
            <button class="btn btn-link text-white-50" onclick="location.href='dashboard.php'">Exit</button>
        </div>
    </div>
</div>
