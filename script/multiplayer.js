const ROWS = 15;
const COLS = 10;
const MY_BS = 38;
const OPP_BS = 24;
const BLITZ_SECS = 120;
const API_URL = '../backEnd/blitz_api.php';

// SRS-standard colors: I=cyan, O=yellow, J=blue, L=orange, S=green, Z=red, T=purple.
const PIECES = [
    { color: 'cyan',    shape: [[1, 1, 1, 1]] },            // I
    { color: '#ffdd00', shape: [[1, 1], [1, 1]] },          // O
    { color: '#4488ff', shape: [[1, 1, 1], [1, 0, 0]] },    // J
    { color: 'orange',  shape: [[1, 1, 1], [0, 0, 1]] },    // L
    { color: '#00dd66', shape: [[0, 1, 1], [1, 1, 0]] },    // S
    { color: '#ff4444', shape: [[1, 1, 0], [0, 1, 1]] },    // Z
    { color: '#cc44ff', shape: [[0, 1, 0], [1, 1, 1]] },    // T
];

const GARBAGE_TABLE = [0, 0, 1, 2, 4];
const PHASES = [
    'cooldownPhase',
    'waitingPhase',
    'readyPhase',
    'countdownPhase',
    'gamePhase',
    'resultPhase',
    'errorPhase',
];

let currentMode = 'quick';
let currentRoomCode = '';
let myPlayer = 0;
let oppName = 'Opponent';
let myReady = false;
let oppReady = false;
let countdownStarted = false;
let gameStarted = false;

let lobbyPollHandle = null;
let readyPollHandle = null;
let readyTimerHandle = null;
let readyDeadline = 0;
let syncHandle = null;
let timerHandle = null;
let syncInFlight = false;
let syncFailures = 0;
let pendingGarbageToSend = 0;
let lastOppGarbageSeen = 0;

const REMATCH_LIMIT = 2;
const REMATCH_INVITE_TTL = 15;

let resultPollHandle = null;
let inviteTimerHandle = null;
let inviteDeadline = 0;
let rematchRole = null;          // 'requester' | 'invitee' | null
let pendingRematchCode = null;   // new room code, once allocated
let rematchHandled = false;      // navigated/cleaned up already
let resultRematchCount = 0;      // rematch_count of the room we just played

let myCanvas = null;
let myCtx = null;
let oppCanvas = null;
let oppCtx = null;
let myBoard = emptyBoard();
let cur = null;
let nxt = null;
let pos = { x: 3, y: 0 };
let myScore = 0;
let oppScore = 0;
let dropMs = 600;     // ms between automatic drops; lower = faster
let lastDropTime = 0;
let lastSpeedTime = 0;
let gameOver = false;
let gameEndReason = '';
let resultShown = false;
let scoreSaved = false;
let timerSecs = BLITZ_SECS;
let garbageQueue = [];
// Most recent opponent board snapshot. Updated at sync rate (~100ms) but
// rendered every animation frame so the rival canvas paints smoothly
// instead of stepping at 10Hz.
let oppBoardCache = null;

function byId(id) {
    return document.getElementById(id);
}

function setText(id, value) {
    const el = byId(id);
    if (el) el.textContent = value;
}

function showPhase(id) {
    PHASES.forEach(phaseId => {
        const el = byId(phaseId);
        if (el) el.classList.toggle('d-none', phaseId !== id);
    });
}

function isPhaseVisible(id) {
    const el = byId(id);
    return Boolean(el && !el.classList.contains('d-none'));
}

function emptyBoard() {
    return Array.from({ length: ROWS }, () => Array(COLS).fill(null));
}

function clonePiece(piece) {
    return { color: piece.color, shape: piece.shape.map(row => [...row]) };
}

function randomPiece() {
    return clonePiece(PIECES[Math.floor(Math.random() * PIECES.length)]);
}

async function blitzApi(action, body = {}) {
    // Bound the request so a hung fetch can't keep syncInFlight=true forever
    // and stall the entire polling loop. 4s is generous vs the ~300ms RTT
    // budget but short enough that a real stall recovers within one tick.
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 4000);

    let response;
    try {
        response = await fetch(API_URL + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: controller.signal,
        });
    } catch (error) {
        if (error && error.name === 'AbortError') {
            throw new Error('Request timed out. Check your connection.');
        }
        throw error;
    } finally {
        clearTimeout(timeoutId);
    }

    const text = await response.text();
    let data = null;
    try {
        data = text ? JSON.parse(text) : {};
    } catch (error) {
        throw new Error('Server returned invalid JSON. Check the PHP error log.');
    }

    if (!response.ok || data.error) {
        throw new Error(data.error || 'Request failed.');
    }

    return data;
}

function friendlyError(error) {
    const message = String(error && error.message ? error.message : error);
    const lower = message.toLowerCase();

    if (lower.includes('relation "blitz_rooms" does not exist')) {
        return 'Blitz tables are missing. Run blitz_tables.sql in the Supabase SQL Editor.';
    }

    if (lower.includes('relation "blitz_leaderboard" does not exist')) {
        return 'Blitz leaderboard table is missing. Run blitz_tables.sql in the Supabase SQL Editor.';
    }

    if (lower.includes('php postgresql pdo driver is not enabled') || lower.includes('could not find driver')) {
        return 'PostgreSQL is not enabled in XAMPP PHP. Enable pdo_pgsql and pgsql in php.ini, then restart Apache.';
    }

    if (lower.includes('database unavailable')) {
        return message;
    }

    return message || 'Room setup failed. Try again.';
}

function showSetupError(error) {
    stopAllPolls();
    setText('matchError', friendlyError(error));
    showPhase('errorPhase');
}

function resetMatchState() {
    stopAllPolls();
    currentRoomCode = '';
    myPlayer = 0;
    oppName = 'Opponent';
    myReady = false;
    oppReady = false;
    countdownStarted = false;
    gameStarted = false;
    pendingGarbageToSend = 0;
    lastOppGarbageSeen = 0;
    syncFailures = 0;
    myBoard = emptyBoard();
    cur = null;
    nxt = null;
    pos = { x: 3, y: 0 };
    myScore = 0;
    oppScore = 0;
    dropMs = 600;
    gameOver = false;
    gameEndReason = '';
    resultShown = false;
    scoreSaved = false;
    timerSecs = BLITZ_SECS;
    garbageQueue = [];
    oppBoardCache = null;
    rematchRole = null;
    pendingRematchCode = null;
    rematchHandled = false;
    resultRematchCount = 0;
    inviteDeadline = 0;
    setText('myScoreDisplay', '0');
    setText('oppScoreDisplay', '0');
    setText('timerDisplay', '2:00');
    setText('oppNameReady', 'Opponent');
    setText('countdownOppName', 'Opponent');
    setText('oppNameHeader', 'Opponent');
    setText('oppNameGame', 'Opponent');
    setText('finalOppNameLabel', 'Opponent');
    setReadyLabels(false, false);
}

function stopAllPolls() {
    clearInterval(lobbyPollHandle);
    clearInterval(readyPollHandle);
    clearInterval(readyTimerHandle);
    clearInterval(syncHandle);
    clearInterval(timerHandle);
    clearInterval(resultPollHandle);
    clearInterval(inviteTimerHandle);
    lobbyPollHandle = null;
    readyPollHandle = null;
    readyTimerHandle = null;
    syncHandle = null;
    timerHandle = null;
    resultPollHandle = null;
    inviteTimerHandle = null;
}

async function initBlitzPage(mode, initialCode) {
    resetMatchState();
    currentMode = mode || 'quick';
    showWaitingStartup();

    try {
        let data;
        if (currentMode === 'join') {
            data = await blitzApi('join', { code: initialCode });
        } else if (currentMode === 'create') {
            data = await blitzApi('create');
        } else {
            data = await blitzApi('find');
        }
        handleSetupResponse(data);
    } catch (error) {
        showSetupError(error);
    }
}

function showWaitingStartup() {
    const title = currentMode === 'quick'
        ? 'Finding Match'
        : (currentMode === 'join' ? 'Joining Room' : 'Creating Room');
    setText('waitingTitle', title);
    setText('lobbyStatusMsg', 'Connecting to Blitz matchmaking...');
    const codeWrap = byId('waitingRoomCode');
    if (codeWrap) codeWrap.style.display = 'none';
    showPhase('waitingPhase');
}

function handleSetupResponse(data) {
    if (!data || !data.success) {
        throw new Error('Room setup failed.');
    }

    currentRoomCode = data.code || currentRoomCode;
    myPlayer = Number(data.player || myPlayer || 0);
    setText('roomCodeBig', currentRoomCode);
    setText('readyRoomCode', currentRoomCode);

    if (data.opp_name || data.p2_username || data.status === 'ready' || data.status === 'playing') {
        showReadyFromRoom(data);
        return;
    }

    showWaitingForOpponent();
    startLobbyPoll();
}

function showWaitingForOpponent() {
    const codeWrap = byId('waitingRoomCode');
    if (codeWrap) codeWrap.style.display = '';
    setText('waitingTitle', currentMode === 'quick' ? 'Quick Match' : 'Room Created');
    setText('waitingSubtext', currentMode === 'quick'
        ? 'Share this code or wait for another quick-match player.'
        : 'Share this code with your friend.');
    setText('roomCodeBig', currentRoomCode);
    setText('lobbyStatusMsg', 'Waiting for opponent...');
    showPhase('waitingPhase');
}

function startLobbyPoll() {
    clearInterval(lobbyPollHandle);
    lobbyPollHandle = setInterval(() => {
        pollLobby().catch(showSetupError);
    }, 1200);
    pollLobby().catch(showSetupError);
}

async function pollLobby() {
    if (!currentRoomCode || countdownStarted || gameStarted) return;
    const data = await blitzApi('poll', { code: currentRoomCode });
    if (data.opp_name || data.p2_username || data.status === 'ready' || data.status === 'playing') {
        showReadyFromRoom(data);
    } else {
        setText('lobbyStatusMsg', 'Waiting for opponent...');
    }
}

function showReadyFromRoom(data) {
    clearInterval(lobbyPollHandle);
    lobbyPollHandle = null;

    updateRoomSnapshot(data);
    setText('readyRoomCode', currentRoomCode);
    setText('oppNameReady', oppName);
    setText('countdownOppName', oppName);
    setText('oppNameHeader', oppName);
    setText('oppNameGame', oppName);
    setText('finalOppNameLabel', oppName);
    setReadyLabels(myReady, oppReady);

    showPhase('readyPhase');
    startReadyTimer();
    startReadyPoll();

    if (data.status === 'playing' || (myReady && oppReady)) {
        startCountdown();
    }
}

function updateRoomSnapshot(data) {
    if (!data) return;
    currentRoomCode = data.code || currentRoomCode;
    myPlayer = Number(data.my_player || data.player || myPlayer || 0);

    const opponentFromRoom = myPlayer === 1 ? data.p2_username : data.p1_username;
    oppName = data.opp_name || opponentFromRoom || oppName || 'Opponent';

    const myReadyKey = myPlayer === 2 ? 'p2_ready' : 'p1_ready';
    const oppReadyKey = myPlayer === 2 ? 'p1_ready' : 'p2_ready';
    myReady = Boolean(Number(data[myReadyKey] || 0));
    oppReady = Boolean(Number(data[oppReadyKey] || 0));
}

function setReadyLabels(isMeReady, isOppReady) {
    const myLabel = byId('myReadyLabel');
    const oppLabel = byId('oppReadyLabel');
    const readyBtn = byId('readyBtn');

    if (myLabel) {
        myLabel.textContent = isMeReady ? 'You: Ready!' : 'You: Not ready';
        myLabel.classList.toggle('text-success', isMeReady);
        myLabel.classList.toggle('text-white-50', !isMeReady);
    }

    if (oppLabel) {
        oppLabel.textContent = (oppName || 'Opponent') + (isOppReady ? ': Ready!' : ': Not ready');
        oppLabel.classList.toggle('text-success', isOppReady);
        oppLabel.classList.toggle('text-white-50', !isOppReady);
    }

    if (readyBtn) readyBtn.disabled = isMeReady || countdownStarted;
}

function startReadyPoll() {
    clearInterval(readyPollHandle);
    readyPollHandle = setInterval(() => {
        pollReady().catch(showSetupError);
    }, 400);
    pollReady().catch(showSetupError);
}

async function pollReady() {
    if (!currentRoomCode || countdownStarted || gameStarted) return;
    const data = await blitzApi('poll', { code: currentRoomCode });
    updateRoomSnapshot(data);
    setReadyLabels(myReady, oppReady);

    if (data.status === 'playing' || (myReady && oppReady)) {
        startCountdown();
    }
}

function startReadyTimer() {
    if (readyTimerHandle) return;
    readyDeadline = Date.now() + 20000;
    readyTimerHandle = setInterval(() => {
        const remaining = Math.max(0, Math.ceil((readyDeadline - Date.now()) / 1000));
        setText('readyTimerDisplay', remaining + 's');
        const fill = byId('readyTimerBar');
        if (fill) fill.style.width = Math.max(0, (remaining / 20) * 100) + '%';

        if (remaining <= 0 && !countdownStarted) {
            clearInterval(readyTimerHandle);
            readyTimerHandle = null;
            leaveRoomQuietly();
            showSetupError(new Error('Ready timer expired. Start a new Blitz room and try again.'));
        }
    }, 250);
}

async function sendReady() {
    if (!currentRoomCode || countdownStarted) return;
    myReady = true;
    setReadyLabels(true, oppReady);

    try {
        const data = await blitzApi('ready', { code: currentRoomCode });
        if (data.both_ready) {
            startCountdown();
        }
    } catch (error) {
        showSetupError(error);
    }
}

function startCountdown() {
    if (countdownStarted) return;
    countdownStarted = true;
    clearInterval(readyPollHandle);
    clearInterval(readyTimerHandle);
    readyPollHandle = null;
    readyTimerHandle = null;
    setReadyLabels(true, true);
    showPhase('countdownPhase');

    let count = 5;
    setText('countdownNum', String(count));
    const countdownHandle = setInterval(() => {
        count -= 1;
        if (count <= 0) {
            clearInterval(countdownHandle);
            setText('countdownNum', 'GO!');
            setTimeout(startGame, 600);
        } else {
            setText('countdownNum', String(count));
        }
    }, 1000);
}

function startGame() {
    if (gameStarted) return;
    gameStarted = true;
    showPhase('gamePhase');

    myCanvas = byId('myCanvas');
    oppCanvas = byId('oppCanvas');
    myCtx = myCanvas.getContext('2d');
    oppCtx = oppCanvas.getContext('2d');
    myCanvas.width = COLS * MY_BS;
    myCanvas.height = ROWS * MY_BS;
    oppCanvas.width = COLS * OPP_BS;
    oppCanvas.height = ROWS * OPP_BS;

    myBoard = emptyBoard();
    cur = randomPiece();
    nxt = randomPiece();
    pos = { x: 3, y: 0 };
    myScore = 0;
    oppScore = 0;
    gameOver = false;
    resultShown = false;
    scoreSaved = false;
    gameEndReason = '';
    dropMs = 600;
    timerSecs = BLITZ_SECS;
    garbageQueue = [];
    oppBoardCache = null;
    pendingGarbageToSend = 0;
    lastOppGarbageSeen = 0;
    syncFailures = 0;

    setText('myScoreDisplay', '0');
    setText('oppScoreDisplay', '0');
    drawNextPiece();
    startTimer();
    syncHandle = setInterval(syncRoom, 100);
    syncRoom();

    lastDropTime = performance.now();
    lastSpeedTime = Date.now();
    requestAnimationFrame(gameLoop);
}

function startTimer() {
    updateTimerEl();
    timerHandle = setInterval(() => {
        timerSecs -= 1;
        if (timerSecs < 0) timerSecs = 0;
        updateTimerEl();
        if (timerSecs <= 0) {
            // Self-clear so the interval doesn't tick into negatives if
            // gameOver is already true (spectator after a TOPPED_OUT).
            clearInterval(timerHandle);
            timerHandle = null;
            if (!gameOver) endGame('TIME_UP');
        }
    }, 1000);
}

function updateTimerEl() {
    const minutes = Math.floor(timerSecs / 60);
    const seconds = timerSecs % 60;
    const el = byId('timerDisplay');
    if (!el) return;
    el.textContent = minutes + ':' + String(seconds).padStart(2, '0');
    el.style.color = timerSecs <= 10 ? '#ff4444' : '';
    el.style.textShadow = timerSecs <= 10 ? '0 0 18px #ff4444' : '';
}

function boardSnapshot() {
    // Copy the locked board and overlay the active tetromino so the opponent
    // sees the falling piece in real time instead of only the settled blocks.
    const snapshot = myBoard.map(row => row.slice());
    if (cur && !gameOver) {
        const { shape, color } = cur;
        for (let y = 0; y < shape.length; y += 1) {
            for (let x = 0; x < shape[y].length; x += 1) {
                if (!shape[y][x]) continue;
                const by = pos.y + y;
                const bx = pos.x + x;
                if (by >= 0 && by < ROWS && bx >= 0 && bx < COLS) {
                    snapshot[by][bx] = color;
                }
            }
        }
    }
    return snapshot;
}

async function syncRoom() {
    if (!currentRoomCode || syncInFlight || resultShown) return;
    syncInFlight = true;
    const garbage = pendingGarbageToSend;

    try {
        const data = await blitzApi('sync', {
            code: currentRoomCode,
            board: boardSnapshot(),
            score: myScore,
            alive: gameOver ? 0 : 1,
            garbage,
        });

        if (garbage > 0) {
            pendingGarbageToSend = Math.max(0, pendingGarbageToSend - garbage);
        }

        syncFailures = 0;
        applySyncResponse(data);
    } catch (error) {
        syncFailures += 1;
        if (syncFailures >= 4 && !resultShown) {
            showSetupError(error);
        }
    } finally {
        syncInFlight = false;
    }
}

function applySyncResponse(data) {
    if (!data) return;
    oppName = data.opp_name || oppName;
    setText('oppNameHeader', oppName);
    setText('oppNameGame', oppName);
    setText('finalOppNameLabel', oppName);

    oppScore = Number(data.opp_score || 0);
    setText('oppScoreDisplay', String(oppScore));

    if (Array.isArray(data.opp_board)) {
        // Stash for the gameLoop to paint on the next animation frame so
        // the rival canvas refreshes at rAF rate, not the sync interval.
        oppBoardCache = data.opp_board;
        // Spectator (after topout): gameLoop has stopped scheduling frames,
        // so paint directly here to keep the rival board ticking.
        if (gameOver && oppCtx) renderOppBoard(oppBoardCache);
    }

    const oppGarbage = Number(data.opp_garbage || 0);
    if (oppGarbage > lastOppGarbageSeen) {
        garbageQueue.push(oppGarbage - lastOppGarbageSeen);
        lastOppGarbageSeen = oppGarbage;
    }

    // Visual marker on the rival board when they top out — game keeps going.
    updateOppToppedOutBanner(Number(data.opp_alive) === 0);

    // Capture rematch_count from the current room so the result screen can
    // immediately decide whether the Rematch button should be visible.
    if (typeof data.rematch_count === 'number') {
        resultRematchCount = data.rematch_count;
    }

    if (data.status === 'finished' && !resultShown) {
        const title = data.winner === MY_USERNAME
            ? 'YOU WIN!'
            : (data.winner ? 'YOU LOST' : 'DRAW!');
        finishLocal(title, data.winner || null);
        return;
    }

    if (data.opp_disconnected && !gameOver) {
        finishByReason('OPPONENT LEFT', 'disconnected');
    }
}

function updateOppToppedOutBanner(isToppedOut) {
    const banner = byId('oppToppedBanner');
    if (!banner) return;
    banner.classList.toggle('d-none', !isToppedOut || resultShown);
}

function gameLoop(timestamp) {
    if (gameOver) return;

    if (timestamp - lastDropTime >= dropMs) {
        dropPiece();
        lastDropTime = timestamp;
    }

    if (Date.now() - lastSpeedTime >= 30000) {
        dropMs = Math.max(200, dropMs - 20);
        lastSpeedTime = Date.now();
    }

    drawMyBoard();
    drawGhost();
    drawCurrent();
    if (oppBoardCache) renderOppBoard(oppBoardCache);
    requestAnimationFrame(gameLoop);
}

function block(ctx, x, y, color, size) {
    ctx.fillStyle = color;
    ctx.fillRect(x * size, y * size, size, size);
    ctx.strokeStyle = 'rgba(255,255,255,0.22)';
    ctx.lineWidth = 1;
    ctx.strokeRect(x * size + 0.5, y * size + 0.5, size - 1, size - 1);
    ctx.fillStyle = 'rgba(255,255,255,0.11)';
    ctx.fillRect(x * size + 2, y * size + 2, size * 0.45, 3);
    ctx.fillRect(x * size + 2, y * size + 2, 3, size * 0.45);
}

function grid(ctx, size) {
    ctx.strokeStyle = 'rgba(255,255,255,0.04)';
    ctx.lineWidth = 1;
    for (let y = 0; y < ROWS; y += 1) {
        for (let x = 0; x < COLS; x += 1) {
            ctx.strokeRect(x * size, y * size, size, size);
        }
    }
}

function drawMyBoard() {
    if (!myCtx || !myCanvas) return;
    myCtx.clearRect(0, 0, myCanvas.width, myCanvas.height);
    grid(myCtx, MY_BS);
    for (let y = 0; y < ROWS; y += 1) {
        for (let x = 0; x < COLS; x += 1) {
            if (myBoard[y][x]) block(myCtx, x, y, myBoard[y][x], MY_BS);
        }
    }
}

function drawCurrent() {
    if (!cur || !myCtx) return;
    const { shape, color } = cur;
    for (let y = 0; y < shape.length; y += 1) {
        for (let x = 0; x < shape[y].length; x += 1) {
            if (shape[y][x]) block(myCtx, pos.x + x, pos.y + y, color, MY_BS);
        }
    }
}

// Project the active piece down to its landing row and render a translucent
// outline there so the player can see exactly where it will lock.
function drawGhost() {
    if (!cur || !myCtx || gameOver) return;
    let dy = 0;
    while (!collides(0, dy + 1)) dy += 1;
    if (dy === 0) return; // piece already resting — ghost would overlap

    const { shape, color } = cur;
    myCtx.save();
    myCtx.globalAlpha = 0.22;
    myCtx.fillStyle = color;
    for (let y = 0; y < shape.length; y += 1) {
        for (let x = 0; x < shape[y].length; x += 1) {
            if (!shape[y][x]) continue;
            const py = pos.y + dy + y;
            if (py < 0) continue;
            myCtx.fillRect((pos.x + x) * MY_BS, py * MY_BS, MY_BS, MY_BS);
        }
    }
    myCtx.restore();

    myCtx.strokeStyle = color;
    myCtx.lineWidth = 1.5;
    for (let y = 0; y < shape.length; y += 1) {
        for (let x = 0; x < shape[y].length; x += 1) {
            if (!shape[y][x]) continue;
            const py = pos.y + dy + y;
            if (py < 0) continue;
            myCtx.strokeRect((pos.x + x) * MY_BS + 1, py * MY_BS + 1, MY_BS - 2, MY_BS - 2);
        }
    }
    myCtx.lineWidth = 1;
}

function renderOppBoard(board) {
    if (!oppCtx || !oppCanvas) return;
    oppCtx.clearRect(0, 0, oppCanvas.width, oppCanvas.height);
    grid(oppCtx, OPP_BS);
    for (let y = 0; y < ROWS; y += 1) {
        for (let x = 0; x < COLS; x += 1) {
            if (board[y] && board[y][x]) block(oppCtx, x, y, board[y][x], OPP_BS);
        }
    }
}

function collides(offsetX, offsetY, shapeOverride) {
    const shape = shapeOverride || (cur && cur.shape);
    if (!shape) return false;

    for (let y = 0; y < shape.length; y += 1) {
        for (let x = 0; x < shape[y].length; x += 1) {
            if (!shape[y][x]) continue;
            const nextX = pos.x + x + offsetX;
            const nextY = pos.y + y + offsetY;
            if (nextX < 0 || nextX >= COLS || nextY >= ROWS) return true;
            if (nextY >= 0 && myBoard[nextY][nextX]) return true;
        }
    }

    return false;
}

function move(deltaX) {
    if (!gameOver && gameStarted && !collides(deltaX, 0)) {
        pos.x += deltaX;
    }
}

function rotateTetromino() {
    if (gameOver || !gameStarted || !cur) return;
    const original = cur.shape;
    cur.shape = original[0].map((_, index) => original.map(row => row[index]).reverse());
    if (collides(0, 0)) cur.shape = original;
}

function hardDrop() {
    if (gameOver || !gameStarted) return;
    while (!collides(0, 1)) pos.y += 1;
    dropPiece();
}

function dropPiece() {
    if (!cur) return;
    if (!collides(0, 1)) {
        pos.y += 1;
    } else {
        lockPiece();
    }
}

function lockPiece() {
    const { shape, color } = cur;
    for (let y = 0; y < shape.length; y += 1) {
        for (let x = 0; x < shape[y].length; x += 1) {
            if (shape[y][x] && pos.y + y >= 0) {
                myBoard[pos.y + y][pos.x + x] = color;
            }
        }
    }

    const lines = clearLines();
    if (garbageQueue.length > 0) {
        const total = garbageQueue.reduce((sum, count) => sum + count, 0);
        garbageQueue = [];
        addGarbage(total);
    }

    const garbage = GARBAGE_TABLE[Math.min(lines, 4)];
    if (garbage > 0) pendingGarbageToSend += garbage;

    cur = nxt;
    nxt = randomPiece();
    pos = { x: 3, y: 0 };
    drawNextPiece();

    if (collides(0, 0)) {
        endGame('TOPPED_OUT');
    }

    // Locking a piece is the biggest change the opponent sees — push it
    // immediately instead of waiting up to 100ms for the next sync tick.
    // syncInFlight already guards against overlapping requests.
    syncRoom();
}

function clearLines() {
    let cleared = 0;
    for (let y = ROWS - 1; y >= 0; y -= 1) {
        if (myBoard[y].every(Boolean)) {
            myBoard.splice(y, 1);
            myBoard.unshift(Array(COLS).fill(null));
            cleared += 1;
            y += 1;
        }
    }

    if (cleared > 0) {
        myScore += cleared * 100;
        setText('myScoreDisplay', String(myScore));
    }

    return cleared;
}

function addGarbage(count) {
    for (let i = 0; i < count; i += 1) {
        const hole = Math.floor(Math.random() * COLS);
        const row = Array(COLS).fill('#3c3c55');
        row[hole] = null;
        myBoard.shift();
        myBoard.push(row);
    }

    const alert = byId('garbageAlert');
    if (alert) {
        alert.style.display = '';
        setTimeout(() => { alert.style.display = 'none'; }, 900);
    }
}

function drawNextPiece() {
    const el = byId('nextPiece');
    if (!el || !nxt) return;
    el.innerHTML = '';
    const size = 16;
    const { shape, color } = nxt;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;width:' + (shape[0].length * size) + 'px;height:' + (shape.length * size) + 'px';

    for (let y = 0; y < shape.length; y += 1) {
        for (let x = 0; x < shape[y].length; x += 1) {
            if (!shape[y][x]) continue;
            const cell = document.createElement('div');
            cell.style.cssText = 'width:' + size + 'px;height:' + size + 'px;background:' + color + ';border:1px solid rgba(255,255,255,0.3);position:absolute;left:' + (x * size) + 'px;top:' + (y * size) + 'px';
            wrap.appendChild(cell);
        }
    }

    el.appendChild(wrap);
}

function endGame(reason) {
    if (gameOver) return;
    gameOver = true;
    gameEndReason = reason;
    // For TOPPED_OUT, the match keeps going for the surviving opponent —
    // keep the countdown visible so the spectator knows how long is left.
    // For other reasons (TIME_UP / disconnect) the timer is no longer
    // meaningful, so stop it now.
    if (reason !== 'TOPPED_OUT') {
        clearInterval(timerHandle);
        timerHandle = null;
    }
    // Important: keep syncHandle running. The room may still be playing
    // (single-side topped_out) so we need to learn when the other player
    // finishes via the next sync that flips status to 'finished'.

    if (!scoreSaved) {
        scoreSaved = true;
        saveHighScore(myScore);
    }

    if (reason === 'TOPPED_OUT') {
        showMyToppedSpectator();
        reportEnd('topped_out');
    } else if (reason === 'TIME_UP') {
        reportEnd('time_up');
    } else {
        // disconnect / other client-driven exits still need the server told.
        finishByReason(null, reason === 'OPPONENT_DISCONNECT' ? 'disconnected' : 'time_up');
    }
}

async function reportEnd(apiReason) {
    try {
        const data = await blitzApi('end', {
            code: currentRoomCode,
            score: myScore,
            reason: apiReason,
        });

        if (data && data.finalized) {
            // Room is over right now (e.g., both topped out, or I'm the
            // surviving player and the timer hit zero) → render final result.
            const winner = data.winner || null;
            const title = winner === MY_USERNAME
                ? 'YOU WIN!'
                : (winner ? 'YOU LOST' : scoreTitle());
            stopSyncForResult();
            showResultScreen(title, myScore, oppScore);
        }
        // else: room still playing — sit in spectator mode and let the
        // sync poller deliver status='finished' when the opponent's clock runs out.
    } catch (error) {
        // Network failure: don't strand the player. Show local result.
        stopSyncForResult();
        showResultScreen(scoreTitle(), myScore, oppScore);
    }
}

function stopSyncForResult() {
    clearInterval(syncHandle);
    syncHandle = null;
    // Result screen is taking over — the spectator countdown (kept alive
    // through TOPPED_OUT) is no longer needed.
    clearInterval(timerHandle);
    timerHandle = null;
}

function showMyToppedSpectator() {
    const banner = byId('myToppedBanner');
    if (banner) banner.classList.remove('d-none');
}

// Legacy entry point retained for disconnect path.
async function finishByReason(localTitle, apiReason) {
    try {
        const data = await blitzApi('end', {
            code: currentRoomCode,
            score: myScore,
            reason: apiReason,
        });

        if (localTitle) {
            stopSyncForResult();
            showResultScreen(localTitle, myScore, oppScore);
            return;
        }

        if (data && data.finalized) {
            const winner = data.winner || null;
            const title = winner === MY_USERNAME
                ? 'YOU WIN!'
                : (winner ? 'YOU LOST' : scoreTitle());
            stopSyncForResult();
            showResultScreen(title, myScore, oppScore);
        }
    } catch (error) {
        stopSyncForResult();
        showResultScreen(localTitle || scoreTitle(), myScore, oppScore);
    }
}

function finishLocal(title, winner) {
    if (resultShown) return;
    gameOver = true;
    clearInterval(timerHandle);
    timerHandle = null;
    stopSyncForResult();
    // endGame() may have already saved the score before the sync poller
    // delivered status='finished'. Guard against a duplicate POST.
    if (!scoreSaved) {
        scoreSaved = true;
        saveHighScore(myScore);
    }
    const resolved = title || (winner
        ? (winner === MY_USERNAME ? 'YOU WIN!' : 'YOU LOST')
        : scoreTitle());
    showResultScreen(resolved, myScore, oppScore);
}

function scoreTitle() {
    if (myScore > oppScore) return 'YOU WIN!';
    if (oppScore > myScore) return 'YOU LOST';
    return 'DRAW!';
}

function showResultScreen(title, me, opp) {
    if (resultShown) return;
    resultShown = true;

    const resultTitle = byId('resultTitle');
    if (resultTitle) {
        resultTitle.textContent = title;
        resultTitle.style.color =
            title === 'YOU WIN!' ? '#00ff88' :
            title === 'DRAW!' ? '#ffcc00' : '#ff4444';
    }

    setText('finalMyScore', String(me));
    setText('finalOppScore', String(opp));
    setText('finalOppNameLabel', oppName);
    showPhase('resultPhase');

    // Reset rematch UI to default visible-state.
    rematchRole = null;
    pendingRematchCode = null;
    rematchHandled = false;
    hideRematchSubpanels();
    refreshRematchLimitUI();

    // Start polling the old room for opponent's rematch action.
    startResultPoll();
}

function hideRematchSubpanels() {
    ['rematchInvite', 'rematchWaiting', 'rematchDeclined', 'rematchExpired'].forEach(id => {
        const el = byId(id);
        if (el) el.classList.add('d-none');
    });
}

function refreshRematchLimitUI() {
    const reached = resultRematchCount >= REMATCH_LIMIT;
    const btn = byId('rematchBtn');
    if (btn) btn.classList.toggle('d-none', reached);
    const limitEl = byId('rematchLimit');
    if (limitEl) limitEl.classList.toggle('d-none', !reached);
}

function startResultPoll() {
    clearInterval(resultPollHandle);
    resultPollHandle = setInterval(pollResultRoom, 400);
    pollResultRoom();
}

async function pollResultRoom() {
    if (!currentRoomCode || !resultShown || rematchHandled) return;
    try {
        const data = await blitzApi('poll', { code: currentRoomCode });
        handleResultPoll(data);
    } catch (error) {
        // Transient — keep polling.
    }
}

function handleResultPoll(data) {
    if (!data) return;
    resultRematchCount = Number(data.rematch_count || 0);
    refreshRematchLimitUI();

    const requester = data.rematch_requested_by || null;
    const declined  = Number(data.rematch_declined || 0) === 1;
    const expired   = Boolean(data.rematch_expired);
    const accepted  = Boolean(data.rematch_accepted);
    const code      = data.rematch_code || null;

    // I requested → check for acceptance / decline / expiry.
    if (rematchRole === 'requester') {
        if (accepted && code && !rematchHandled) {
            rematchHandled = true;
            stopAllPolls();
            window.location.href = 'blitz_room.php?mode=join&code=' + encodeURIComponent(code);
            return;
        }
        if (declined && !rematchHandled) {
            rematchHandled = true;
            clearInterval(inviteTimerHandle); inviteTimerHandle = null;
            hideRematchSubpanels();
            const el = byId('rematchDeclined'); if (el) el.classList.remove('d-none');
            return;
        }
        if (expired && !rematchHandled) {
            rematchHandled = true;
            clearInterval(inviteTimerHandle); inviteTimerHandle = null;
            hideRematchSubpanels();
            const el = byId('rematchExpired'); if (el) el.classList.remove('d-none');
            return;
        }
        return;
    }

    // Opponent requested → show invite if I haven't responded yet.
    if (requester && requester !== MY_USERNAME && !declined && !expired
        && !rematchHandled && rematchRole === null) {
        rematchRole = 'invitee';
        pendingRematchCode = code;
        showRematchInvite(requester, data.rematch_requested_at);
    }
}

function showRematchInvite(fromUsername, requestedAt) {
    hideRematchSubpanels();
    setText('rematchInviteFrom', fromUsername || 'Opponent');
    const el = byId('rematchInvite');
    if (el) el.classList.remove('d-none');

    // Compute remaining TTL based on the server-reported request time so
    // both sides agree on the deadline within network skew.
    const base = requestedAt ? Date.parse(requestedAt) : Date.now();
    inviteDeadline = base + REMATCH_INVITE_TTL * 1000;
    runInviteCountdown('rematchInviteCountdown', () => {
        // Auto-decline on timeout.
        declineRematch();
    });

    const btn = byId('rematchBtn');
    if (btn) btn.classList.add('d-none');
}

function showRematchWaiting() {
    hideRematchSubpanels();
    const el = byId('rematchWaiting');
    if (el) el.classList.remove('d-none');
    inviteDeadline = Date.now() + REMATCH_INVITE_TTL * 1000;
    runInviteCountdown('rematchWaitingCountdown', null);
    const btn = byId('rematchBtn');
    if (btn) btn.classList.add('d-none');
}

function runInviteCountdown(displayId, onZero) {
    clearInterval(inviteTimerHandle);
    inviteTimerHandle = setInterval(() => {
        const remaining = Math.max(0, Math.ceil((inviteDeadline - Date.now()) / 1000));
        setText(displayId, String(remaining));
        if (remaining <= 0) {
            clearInterval(inviteTimerHandle);
            inviteTimerHandle = null;
            if (typeof onZero === 'function') onZero();
        }
    }, 250);
}

async function requestRematch() {
    if (rematchRole) return;
    if (resultRematchCount >= REMATCH_LIMIT) return;
    rematchRole = 'requester';
    showRematchWaiting();

    try {
        const data = await blitzApi('rematch_request', { code: currentRoomCode });
        pendingRematchCode = data.rematch_code;

        // If the opponent had already clicked Rematch, the server routes
        // this request through rematch_accept and returns is_host=false —
        // we're already joined to the new room as p2, so jump straight in
        // instead of sitting on the "Waiting" screen.
        if (data && data.is_host === false && data.rematch_code) {
            rematchHandled = true;
            stopAllPolls();
            window.location.href = 'blitz_room.php?mode=join&code=' + encodeURIComponent(data.rematch_code);
            return;
        }
        // Result poll will pick up acceptance/decline/expiry from now on.
    } catch (error) {
        rematchRole = null;
        rematchHandled = false;
        hideRematchSubpanels();
        const status = byId('rematchStatus');
        if (status) {
            status.textContent = friendlyError(error);
            status.style.display = '';
        }
        const btn = byId('rematchBtn');
        if (btn) btn.classList.remove('d-none');
    }
}

async function acceptRematch() {
    if (rematchHandled) return;
    rematchHandled = true;
    clearInterval(inviteTimerHandle); inviteTimerHandle = null;

    try {
        const data = await blitzApi('rematch_accept', { code: currentRoomCode });
        const code = data.rematch_code || pendingRematchCode;
        if (!code) throw new Error('No rematch code returned.');
        stopAllPolls();
        window.location.href = 'blitz_room.php?mode=join&code=' + encodeURIComponent(code);
    } catch (error) {
        rematchHandled = false;
        const status = byId('rematchStatus');
        if (status) {
            status.textContent = friendlyError(error);
            status.style.display = '';
        }
    }
}

async function declineRematch() {
    if (rematchHandled) return;
    rematchHandled = true;
    clearInterval(inviteTimerHandle); inviteTimerHandle = null;
    hideRematchSubpanels();
    const el = byId('rematchDeclined');
    if (el) el.classList.remove('d-none');
    try {
        await blitzApi('rematch_decline', { code: currentRoomCode });
    } catch (error) {
        // ignore — server may already have timed out the invite.
    }
}

function saveHighScore(score) {
    if (score <= 0) return;
    fetch('../dashboard/dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ score, csrf_token: typeof CSRF_TOKEN === 'string' ? CSRF_TOKEN : '' }),
    }).catch(() => {});
}

function cancelAndReset() {
    stopAllPolls();
    leaveRoomQuietly();
    window.location.href = 'multiplayer.php';
}

function leaveRoomQuietly() {
    if (!currentRoomCode) return;
    blitzApi('leave', { code: currentRoomCode }).catch(() => {});
}

function copyRoomCode() {
    if (!currentRoomCode) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(currentRoomCode).catch(() => {});
    }
}


document.addEventListener('keydown', event => {
    if (!gameStarted || gameOver || !isPhaseVisible('gamePhase')) return;

    switch (event.key) {
        case 'ArrowLeft':
            move(-1);
            break;
        case 'ArrowRight':
            move(1);
            break;
        case 'ArrowDown':
            dropPiece();
            lastDropTime = performance.now();
            break;
        case 'ArrowUp':
            rotateTetromino();
            break;
        case ' ':
            hardDrop();
            event.preventDefault();
            break;
        default:
            break;
    }
});

let touchX = 0;
let touchY = 0;

document.addEventListener('touchstart', event => {
    if (!gameStarted || !isPhaseVisible('gamePhase')) return;
    if (event.target.closest('.blitz-touch-btn')) return;
    touchX = event.touches[0].clientX;
    touchY = event.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchend', event => {
    if (!gameStarted || gameOver || !isPhaseVisible('gamePhase')) return;
    if (event.target.closest('.blitz-touch-btn')) return;

    const dx = event.changedTouches[0].clientX - touchX;
    const dy = event.changedTouches[0].clientY - touchY;
    const distance = Math.hypot(dx, dy);

    if (distance < 18) {
        rotateTetromino();
    } else if (Math.abs(dx) > Math.abs(dy)) {
        move(dx > 0 ? 1 : -1);
    } else if (dy > 0) {
        hardDrop();
    } else {
        rotateTetromino();
    }
}, { passive: true });

window.addEventListener('beforeunload', () => {
    if (!currentRoomCode || resultShown) return;
    const payload = JSON.stringify({ code: currentRoomCode });
    const blob = new Blob([payload], { type: 'application/json' });
    navigator.sendBeacon(API_URL + '?action=leave', blob);
});
