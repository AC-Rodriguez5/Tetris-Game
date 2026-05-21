<div id="cooldownPhase" style="display:none"
     class="container min-vh-100 d-flex flex-column justify-content-center align-items-center py-4 blitz-stage">
    <div class="glass-card blitz-arena-card text-center">
        <div class="blitz-kicker mb-2">Queue cooldown</div>
        <h2 class="cosmic-title text-white mb-3">Cooldown</h2>
        <div id="cooldownMessage" class="text-warning mb-4 blitz-error-text"></div>
        <div class="blitz-cooldown-timer mb-4">
            <span id="cooldownTimer">15s</span>
        </div>
        <div id="cooldownQueueBtn" style="display:none" class="d-grid gap-2">
            <button class="btn neon-btn neon-blitz btn-large"
                    onclick="location.href='blitz_room.php?mode=quick'">Find Match</button>
            <a href="multiplayer.php" class="btn btn-link text-white-50">Back to Blitz</a>
        </div>
    </div>
</div>
