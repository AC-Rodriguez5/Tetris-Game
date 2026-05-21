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

        <!-- Invite received from opponent -->
        <div id="rematchInvite" class="blitz-rematch-invite d-none mb-3">
            <p class="mb-2"><strong id="rematchInviteFrom">Opponent</strong> wants a rematch!</p>
            <p class="text-white-50 small mb-3">Auto-decline in <span id="rematchInviteCountdown">15</span>s</p>
            <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                <button class="btn neon-btn neon-blitz" onclick="acceptRematch()">Accept</button>
                <button class="btn btn-outline-light" onclick="declineRematch()">Decline</button>
            </div>
        </div>

        <!-- Waiting after I requested a rematch -->
        <div id="rematchWaiting" class="blitz-rematch-waiting d-none mb-3">
            <p class="text-info mb-1">Waiting for opponent's response&hellip;</p>
            <p class="text-white-50 small mb-0"><span id="rematchWaitingCountdown">15</span>s remaining</p>
        </div>

        <!-- Opponent declined -->
        <div id="rematchDeclined" class="d-none text-warning mb-3">
            Opponent declined the rematch.
        </div>

        <!-- Invite expired (timed out) -->
        <div id="rematchExpired" class="d-none text-warning mb-3">
            Rematch invite expired with no response.
        </div>

        <!-- Two rematches already played between this pair -->
        <div id="rematchLimit" class="d-none text-info mb-3 small">
            Rematch limit reached for this pairing.
        </div>

        <div class="d-grid gap-2">
            <button id="rematchBtn" class="btn neon-btn neon-blitz btn-large" onclick="requestRematch()">Rematch</button>
            <button class="btn btn-outline-light" onclick="location.href='blitz_room.php?mode=quick'">Find Another Match</button>
            <button class="btn btn-link text-white-50" onclick="location.href='dashboard.php'">Exit</button>
        </div>
    </div>
</div>
