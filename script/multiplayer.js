const ROWS = 15;
const COLS = 10;
const MY_BS = 38;
const OPP_BS = 24;
const BLITZ_SECS = 120;
const API_URL = '../backEnd/blitz_api.php';

const PIECES = [
    { color: 'cyan', shape: [[1, 1, 1, 1]] },
    { color: '#4488ff', shape: [[1, 1], [1, 1]] },
    { color: 'orange', shape: [[1, 1, 1], [1, 0, 0]] },
    { color: '#ffdd00', shape: [[1, 1, 1], [0, 0, 1]] },
    { color: '#00dd66', shape: [[1, 1, 0], [0, 1, 1]] },
    { color: '#ff4444', shape: [[0, 1, 1], [1, 1, 0]] },
    { color: '#cc44ff', shape: [[0, 1, 0], [1, 1, 1]] },
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
let dropMs = 400;
let lastDropTime = 0;
let lastSpeedTime = 0;
let gameOver = false;
let gameEndReason = '';
let resultShown = false;
let scoreSaved = false;
let timerSecs = BLITZ_SECS;
let garbageQueue = [];

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
    const response = await fetch(API_URL + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

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
    dropMs = 400;
    gameOver = false;
    gameEndReason = '';
    resultShown = false;
    scoreSaved = false;
    timerSecs = BLITZ_SECS;
    garbageQueue = [];
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
    lobbyPollHandle = null;
    readyPollHandle = null;
    readyTimerHandle = null;
    syncHandle = null;
    timerHandle = null;
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
    }, 1000);
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

    let count = 3;
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
    dropMs = 400;
    timerSecs = BLITZ_SECS;
    garbageQueue = [];
    pendingGarbageToSend = 0;
    lastOppGarbageSeen = 0;
    syncFailures = 0;

    setText('myScoreDisplay', '0');
    setText('oppScoreDisplay', '0');
    drawNextPiece();
    startTimer();
    syncHandle = setInterval(syncRoom, 350);
    syncRoom();

    lastDropTime = performance.now();
    lastSpeedTime = Date.now();
    requestAnimationFrame(gameLoop);
}

function startTimer() {
    updateTimerEl();
    timerHandle = setInterval(() => {
        timerSecs -= 1;
        updateTimerEl();
        if (timerSecs <= 0) {
            endGame('TIME_UP');
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

async function syncRoom() {
    if (!currentRoomCode || syncInFlight || resultShown) return;
    syncInFlight = true;
    const garbage = pendingGarbageToSend;

    try {
        const data = await blitzApi('sync', {
            code: currentRoomCode,
            board: myBoard,
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

    if (Array.isArray(data.opp_board) && oppCtx) {
        renderOppBoard(data.opp_board);
    }

    const oppGarbage = Number(data.opp_garbage || 0);
    if (oppGarbage > lastOppGarbageSeen) {
        garbageQueue.push(oppGarbage - lastOppGarbageSeen);
        lastOppGarbageSeen = oppGarbage;
    }

    if (data.status === 'finished' && !resultShown) {
        const title = data.winner === MY_USERNAME
            ? 'YOU WIN!'
            : (data.winner ? 'YOU LOST' : 'DRAW!');
        finishLocal(title, data.winner || null);
        return;
    }

    if (Number(data.opp_alive) === 0 && !gameOver) {
        finishLocal('YOU WIN!', data.winner || MY_USERNAME);
        return;
    }

    if (data.opp_disconnected && !gameOver) {
        finishByReason('OPPONENT LEFT', 'disconnected');
    }
}

function gameLoop(timestamp) {
    if (gameOver) return;

    if (timestamp - lastDropTime >= dropMs) {
        dropPiece();
        lastDropTime = timestamp;
    }

    if (Date.now() - lastSpeedTime >= 5000) {
        dropMs = Math.max(80, dropMs - 20);
        lastSpeedTime = Date.now();
    }

    drawMyBoard();
    drawCurrent();
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
    clearInterval(timerHandle);
    clearInterval(syncHandle);
    timerHandle = null;
    syncHandle = null;

    if (!scoreSaved) {
        scoreSaved = true;
        saveHighScore(myScore);
    }

    if (reason === 'TOPPED_OUT') {
        finishByReason('YOU LOST', 'topped_out');
    } else {
        finishByReason(null, 'time_up');
    }
}

async function finishByReason(localTitle, apiReason) {
    try {
        const data = await blitzApi('end', {
            code: currentRoomCode,
            score: myScore,
            reason: apiReason,
        });

        if (localTitle) {
            showResultScreen(localTitle, myScore, oppScore);
            return;
        }

        const winner = data.winner || null;
        const title = winner === MY_USERNAME
            ? 'YOU WIN!'
            : (winner ? 'YOU LOST' : scoreTitle());
        showResultScreen(title, myScore, oppScore);
    } catch (error) {
        showResultScreen(localTitle || scoreTitle(), myScore, oppScore);
    }
}

function finishLocal(title) {
    if (resultShown) return;
    gameOver = true;
    clearInterval(timerHandle);
    clearInterval(syncHandle);
    timerHandle = null;
    syncHandle = null;
    saveHighScore(myScore);
    showResultScreen(title || scoreTitle(), myScore, oppScore);
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
}

function saveHighScore(score) {
    if (score <= 0) return;
    fetch('../dashboard/dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ score }),
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

async function startRematch() {
    const button = byId('rematchBtn');
    const status = byId('rematchStatus');
    if (button) button.disabled = true;
    if (status) {
        status.textContent = 'Creating rematch...';
        status.style.display = '';
    }

    try {
        const data = await blitzApi('rematch', { code: currentRoomCode });
        const code = data.rematch_code;
        if (!code) throw new Error('Could not create rematch room.');
        if (data.is_host) {
            resetMatchState();
            currentMode = 'create';
            currentRoomCode = code;
            myPlayer = 1;
            setText('roomCodeBig', currentRoomCode);
            setText('readyRoomCode', currentRoomCode);
            showWaitingForOpponent();
            startLobbyPoll();
            return;
        }
        window.location.href = 'blitz_room.php?mode=join&code=' + encodeURIComponent(code);
    } catch (error) {
        if (status) status.textContent = friendlyError(error);
        if (button) button.disabled = false;
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
