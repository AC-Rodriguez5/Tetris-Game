<div id="waitingPhase"
     class="container min-vh-100 d-flex flex-column justify-content-center align-items-center py-4 blitz-stage">
    <div class="glass-card blitz-arena-card text-center">
        <div class="blitz-kicker mb-2">Matchmaking</div>
        <h2 id="waitingTitle" class="cosmic-title text-white mb-3">Room Lobby</h2>

        <div class="blitz-mini-board" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span>
        </div>

        <div id="waitingRoomCode" style="display:none">
            <p class="text-info mb-1 blitz-helper-text" id="waitingSubtext"></p>
            <div id="roomCodeBig" class="blitz-room-code mb-4"></div>
            <button id="copyRoomCodeBtn" class="btn btn-outline-light btn-sm mb-4" onclick="copyRoomCode()">
                Copy Code
            </button>
        </div>

        <div class="blitz-status-strip mb-3">
            <span class="blitz-status-led" aria-hidden="true"></span>
            <span id="lobbyStatusMsg" aria-live="polite">Starting...</span>
        </div>
        <button class="btn btn-link text-white-50 mt-2" onclick="cancelAndReset()">Cancel</button>
    </div>
</div>
