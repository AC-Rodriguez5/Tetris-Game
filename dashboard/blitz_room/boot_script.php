<script src="../script/multiplayer.js?v=<?php echo filemtime(__DIR__ . '/../../script/multiplayer.js'); ?>"></script>
<script>
    const MY_USERNAME = <?php echo json_encode($currentUsername); ?>;
    const BLITZ_MODE = <?php echo json_encode($mode); ?>;
    const INITIAL_ROOM_CODE = <?php echo json_encode($initialCode); ?>;

    function retryBlitzRoom() {
        if (typeof stopAllPolls === 'function') stopAllPolls();
        if (typeof initBlitzPage === 'function') {
            initBlitzPage(BLITZ_MODE, INITIAL_ROOM_CODE);
        } else {
            window.location.reload();
        }
    }

    function bootBlitzRoom() {
        if (typeof initBlitzPage !== 'function') {
            const errorPhase = document.getElementById('errorPhase');
            const matchError = document.getElementById('matchError');
            const waitingPhase = document.getElementById('waitingPhase');

            if (waitingPhase) waitingPhase.classList.add('d-none');
            if (matchError) matchError.textContent = 'Blitz failed to load. Refresh the page and try again.';
            if (errorPhase) errorPhase.classList.remove('d-none');
            return;
        }

        initBlitzPage(BLITZ_MODE, INITIAL_ROOM_CODE);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootBlitzRoom);
    } else {
        bootBlitzRoom();
    }
</script>
