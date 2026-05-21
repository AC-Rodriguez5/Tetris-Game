// ─── Constants ───────────────────────────────────────────────────────────────
const ROWS       = 15;
const COLS       = 10;
const MY_BS      = 38;
const OPP_BS     = 24;
const BLITZ_SECS = 120;

const PIECES = [
    { color: 'cyan',    shape: [[1,1,1,1]] },
    { color: '#4488ff', shape: [[1,1],[1,1]] },
    { color: 'orange',  shape: [[1,1,1],[1,0,0]] },
    { color: '#ffdd00', shape: [[1,1,1],[0,0,1]] },
    { color: '#00dd66', shape: [[1,1,0],[0,1,1]] },
    { color: '#ff4444', shape: [[0,1,1],[1,1,0]] },
    { color: '#cc44ff', shape: [[0,1,0],[1,1,1]] },
];

const GARBAGE_TABLE = [0, 0, 1, 2, 4]; // lines cleared → rows sent to opponent

// Polling tolerance — how many consecutive failures before we give up
const MAX_LOBBY_ERRORS = 8;   // ~16s of failures at 2s interval
const MAX_READY_ERRORS = 8;   // ~8s at 1s interval
const MAX_SYNC_ERRORS  = 30;  // ~9s at 300ms interval (game keeps going)

// ─── Network / room state ─────────────────────────────────────────────────────
let roomCode     = '';
let myPlayer     = 0;   // 1 or 2
let oppName      = 'Opponent';

let lobbyPollHandle = null;
let readyPollHandle = null;
let syncHandle      = null;
let timerHandle     = null;

let lobbyPollErrors = 0;
let readyPollErrors = 0;
let syncErrors      = 0;
let syncInFlight    = false;
let activeFlowId    = 0;

// ─── Game state ───────────────────────────────────────────────────────────────
let myBoard  = [];
let cur      = null;
let nxt      = null;
let pos      = { x: 3, y: 0 };
let myScore  = 0;
let oppScore = 0;
let dropMs        = 400;
let lastDropTime  = 0;
let lastSpeedTime = 0;
let gameOver      = false;
let playerFinished = false;
let gameEndReason = '';
let resultShown   = false;
let scoreSaved    = false;
let timerSecs     = BLITZ_SECS;
let garbageQueue  = [];           // incoming garbage rows to apply on next piece lock
let myGarbagePending = 0;         // garbage I've queued up to send to opponent
let oppGarbageAcked  = 0;         // cumulative opponent garbage already processed

let readyTimerSecs   = 20;
let readyTimerHandle = null;
let myReadyClicked   = false;
let cooldownHandle   = null;

// ─── Canvases ─────────────────────────────────────────────────────────────────
let myCanvas, myCtx, oppCanvas, oppCtx;

// ─── API helper ───────────────────────────────────────────────────────────────
async function api(action, body) {
    const ctrl    = new AbortController();
    const timeout = setTimeout(() => ctrl.abort(), 20000);
    try {
        const r = await fetch(`../backEnd/blitz_api.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
            signal: ctrl.signal,
            credentials: 'same-origin'
        });
        clearTimeout(timeout);
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            return { error: 'Server error: ' + text.replace(/<[^>]+>/g, '').trim().substring(0, 160) };
        }
    } catch (e) {
        clearTimeout(timeout);
        return { error: e.name === 'AbortError' ? 'Request timed out. Check your connection.' : 'Network error: ' + e.message };
    }
}

// ─── Phases ───────────────────────────────────────────────────────────────────
const PHASES = [
    'lobbyPhase','joinPhase','errorPhase','waitingPhase','readyPhase',
    'cooldownPhase','countdownPhase','gamePhase','resultPhase'
];

function showPhase(id) {
    PHASES.forEach(p => {
        const el = document.getElementById(p);
        if (el) el.style.setProperty('display', 'none', 'important');
    });
    const target = document.getElementById(id);
    if (target) target.style.setProperty('display', 'flex', 'important');
}

function lobbyError(msg) {
    console.error('[blitz] lobby error:', msg);
    stopAllPolls();
    roomCode = ''; myPlayer = 0;
    setRoomCodeDisplay('');
    const el = document.getElementById('lobbyError') || document.getElementById('matchError');
    if (el) {
        el.textContent = msg;
        el.style.display = '';
    }
    const joinEl = document.getElementById('joinError');
    if (joinEl) joinEl.style.display = 'none';
    const lobbyStatusEl = document.getElementById('lobbyStatusMsg');
    if (lobbyStatusEl) lobbyStatusEl.textContent = '';
    const joinStatusEl = document.getElementById('joinStatusMsg');
    if (joinStatusEl) joinStatusEl.textContent = '';
    if (document.getElementById('errorPhase')) showPhase('errorPhase');
    else if (document.getElementById('lobbyPhase')) showPhase('lobbyPhase');
    else showPhase('joinPhase');
}

function lobbyStatus(msg) {
    const el = document.getElementById('lobbyStatusMsg');
    if (el) el.textContent = msg;
}

function joinError(msg) {
    const el = document.getElementById('joinError');
    if (el) {
        el.textContent = msg;
        el.style.display = '';
    } else {
        const matchEl = document.getElementById('matchError');
        if (matchEl) {
            matchEl.textContent = msg;
            matchEl.style.display = '';
        }
    }
    const statusEl = document.getElementById('joinStatusMsg');
    if (statusEl) statusEl.textContent = '';
    if (document.getElementById('joinPhase')) showPhase('joinPhase');
    else showPhase('errorPhase');
}

function joinStatus(msg) {
    const el = document.getElementById('joinStatusMsg') || document.getElementById('lobbyStatusMsg');
    if (el) el.textContent = msg;
}

function clearLobbyFeedback() {
    ['lobbyError', 'joinError', 'matchError'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = '';
            el.style.display = 'none';
        }
    });
    ['lobbyStatusMsg', 'joinStatusMsg'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '';
    });
}

function openJoinRoom() {
    clearLobbyFeedback();
    showPhase('joinPhase');
    setTimeout(() => {
        const input = document.getElementById('joinCode');
        if (input) input.focus();
    }, 0);
}

function backToLobby() {
    clearLobbyFeedback();
    if (document.getElementById('lobbyPhase')) showPhase('lobbyPhase');
    else window.location.href = 'multiplayer.php';
}

function stopAllPolls() {
    clearInterval(lobbyPollHandle);
    clearInterval(readyPollHandle);
    clearInterval(syncHandle);
    clearInterval(timerHandle);
    clearInterval(readyTimerHandle);
    clearInterval(cooldownHandle);
    lobbyPollHandle = readyPollHandle = syncHandle = timerHandle =
    readyTimerHandle = cooldownHandle = null;
}

function newFlowId() {
    activeFlowId++;
    return activeFlowId;
}

function isActiveFlow(flowId) {
    return flowId === undefined || flowId === activeFlowId;
}

function isRecoverableLobbyError(message) {
    return /network|timed out|server error|database unavailable|connection/i.test(String(message || ''));
}

function setRoomCodeDisplay(code) {
    const text = code || '';
    ['roomCodeBig', 'readyRoomCode'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    });
    const readyWrap = document.getElementById('readyRoomCodeWrap');
    if (readyWrap) readyWrap.style.display = text ? '' : 'none';
}

async function copyRoomCode() {
    if (!roomCode) return;

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(roomCode);
        } else {
            const temp = document.createElement('textarea');
            temp.value = roomCode;
            temp.style.position = 'fixed';
            temp.style.opacity = '0';
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            temp.remove();
        }
        lobbyStatus('Room code copied.');
    } catch (_) {
        lobbyStatus('Copy failed. Select the code manually.');
    }
}

function resetReadyLobby() {
    const readyBtn = document.getElementById('readyBtn');
    if (readyBtn) readyBtn.disabled = false;

    const myReady = document.getElementById('myReadyLabel');
    if (myReady) {
        myReady.textContent = 'You: Not ready';
        myReady.classList.remove('text-success');
        myReady.classList.add('text-white-50');
    }

    const oppReady = document.getElementById('oppReadyLabel');
    if (oppReady) {
        oppReady.textContent = 'Opponent: Not ready';
        oppReady.classList.remove('text-success');
        oppReady.classList.add('text-white-50');
    }
}

function showWaitingLobby(title, subtext, showCode) {
    document.getElementById('waitingTitle').textContent = title;
    document.getElementById('waitingSubtext').textContent = subtext;
    document.getElementById('waitingRoomCode').style.display = showCode ? '' : 'none';
    showPhase('waitingPhase');
}

function initBlitzPage(mode, code) {
    stopAllPolls();
    clearLobbyFeedback();
    const flowId = newFlowId();
    const selectedMode = mode || 'quick';

    if (selectedMode === 'create') {
        createRoom(flowId);
    } else if (selectedMode === 'join') {
        joinRoomWithCode(code || '', null, flowId);
    } else {
        quickFind(flowId);
    }
}

// ─── CREATE ROOM ──────────────────────────────────────────────────────────────
async function createRoom(flowId) {
    clearLobbyFeedback();
    showWaitingLobby('Create Room', 'Setting up your room...', false);
    lobbyStatus('Creating room...');
    const data = await api('create');
    if (!isActiveFlow(flowId)) return;
    if (data.error) { lobbyError(data.error); return; }
    if (!data.code) { lobbyError('Server did not return a room code. Try again.'); return; }
    roomCode = data.code;
    myPlayer = 1;
    resetReadyLobby();
    setRoomCodeDisplay(roomCode);
    showWaitingLobby('Room Lobby', 'Share this code with your opponent', true);
    lobbyStatus('Waiting for opponent...');
    startLobbyPoll(flowId);
}

// ─── QUICK FIND MATCH ─────────────────────────────────────────────────────────
async function quickFind(flowId) {
    clearLobbyFeedback();
    showWaitingLobby('Quick Match', 'Searching for an open room...', false);
    lobbyStatus('Searching...');
    const data = await api('find');
    if (!isActiveFlow(flowId)) return;
    if (data.error) { lobbyError(data.error); return; }
    if (!data.code) { lobbyError('No room code returned. Try again.'); return; }
    roomCode = data.code;
    myPlayer = data.player;
    resetReadyLobby();
    setRoomCodeDisplay(roomCode);

    if (data.player === 2) {
        oppName = data.opp_name || 'Opponent';
        setOppName(oppName);
        enterReadyPhase();
    } else {
        showWaitingLobby('Room Lobby', 'Share this code with your opponent', true);
        lobbyStatus('Waiting for opponent...');
        startLobbyPoll(flowId);
    }
}

// ─── JOIN ROOM ────────────────────────────────────────────────────────────────
async function joinRoom() {
    clearLobbyFeedback();
    const joinBtn = document.getElementById('joinRoomBtn');
    const code = document.getElementById('joinCode').value.trim().toUpperCase().replace(/[^A-Z]/g, '');
    document.getElementById('joinCode').value = code;
    await joinRoomWithCode(code, joinBtn);
}

async function joinRoomWithCode(code, joinBtn, flowId) {
    if (flowId === undefined) flowId = newFlowId();
    clearLobbyFeedback();
    code = String(code || '').trim().toUpperCase().replace(/[^A-Z]/g, '');
    if (code.length !== 6) { joinError('Enter the 6-letter room code.'); return; }
    if (joinBtn) joinBtn.disabled = true;
    joinStatus('Joining room ' + code + '...');
    const data = await api('join', { code });
    if (!isActiveFlow(flowId)) return;
    if (joinBtn) joinBtn.disabled = false;
    if (data.error) { joinError('Could not join ' + code + ': ' + data.error); return; }
    if (!data.code) { joinError('Server did not confirm room ' + code + '. Try again.'); return; }
    roomCode = data.code;
    myPlayer = data.player;
    oppName  = data.opp_name || 'Opponent';
    resetReadyLobby();
    setRoomCodeDisplay(roomCode);
    setOppName(oppName);
    joinStatus('');
    if (myPlayer === 1 && !data.p2_username) {
        showWaitingLobby('Room Lobby', 'Share this code with your opponent', true);
        lobbyStatus('Waiting for opponent...');
        startLobbyPoll(flowId);
    } else {
        enterReadyPhase();
    }
}

function cancelAndReset() {
    newFlowId();
    stopAllPolls();
    if (roomCode) api('leave', { code: roomCode }).catch(() => {});
    roomCode = ''; myPlayer = 0;
    resetReadyLobby();
    setRoomCodeDisplay('');
    clearLobbyFeedback();
    if (document.getElementById('lobbyPhase')) showPhase('lobbyPhase');
    else window.location.href = 'multiplayer.php';
}

// ─── LOBBY POLL: waiting for opponent to join ─────────────────────────────────
function startLobbyPoll(flowId) {
    clearInterval(lobbyPollHandle);
    lobbyPollErrors = 0;
    lobbyPollHandle = setInterval(async () => {
        if (!isActiveFlow(flowId)) return;
        const data = await api('poll', { code: roomCode });
        if (!isActiveFlow(flowId)) return;
        if (data.error) {
            lobbyPollErrors++;
            if (lobbyPollErrors >= MAX_LOBBY_ERRORS) {
                if (isRecoverableLobbyError(data.error)) {
                    lobbyStatus('Still reconnecting... ' + data.error);
                } else {
                    lobbyError(data.error);
                }
            } else {
                lobbyStatus('Reconnecting... (' + lobbyPollErrors + '/' + MAX_LOBBY_ERRORS + ')');
            }
            return;
        }
        lobbyPollErrors = 0;
        lobbyStatus('Waiting for opponent...');
        if (data.p2_username) {
            clearInterval(lobbyPollHandle);
            lobbyPollHandle = null;
            oppName = data.p2_username;
            setOppName(oppName);
            enterReadyPhase();
        }
    }, 2000);
}

// ─── ENTER READY PHASE ────────────────────────────────────────────────────────
function enterReadyPhase() {
    myReadyClicked = false;
    resetReadyLobby();
    setRoomCodeDisplay(roomCode);
    showPhase('readyPhase');
    startReadyTimer();
}

function startReadyTimer() {
    clearInterval(readyTimerHandle);
    readyTimerSecs = 20;
    updateReadyTimerEl();
    readyTimerHandle = setInterval(() => {
        readyTimerSecs--;
        updateReadyTimerEl();
        if (readyTimerSecs <= 0) {
            clearInterval(readyTimerHandle);
            readyTimerHandle = null;
            handleReadyTimeout();
        }
    }, 1000);
}

function updateReadyTimerEl() {
    const el = document.getElementById('readyTimerDisplay');
    if (el) el.textContent = readyTimerSecs + 's';
    const bar = document.getElementById('readyTimerBar');
    if (bar) bar.style.width = (readyTimerSecs / 20 * 100) + '%';
}

function handleReadyTimeout() {
    const code = roomCode;
    roomCode = ''; myPlayer = 0;
    stopAllPolls();
    if (code) api('leave', { code }).catch(() => {});
    const msg = myReadyClicked
        ? 'Opponent did not ready up in time.'
        : 'You did not ready up in time.';
    showCooldown(15, msg);
}

function showCooldown(secs, message) {
    stopAllPolls();
    let remaining = secs;
    const msgEl   = document.getElementById('cooldownMessage');
    const timerEl = document.getElementById('cooldownTimer');
    const btn     = document.getElementById('cooldownQueueBtn');
    if (msgEl)   msgEl.textContent = message || '';
    if (timerEl) timerEl.textContent = remaining + 's';
    if (btn)     btn.style.display = 'none';
    showPhase('cooldownPhase');

    cooldownHandle = setInterval(() => {
        remaining--;
        if (timerEl) timerEl.textContent = remaining + 's';
        if (remaining <= 0) {
            clearInterval(cooldownHandle);
            cooldownHandle = null;
            if (btn) btn.style.display = '';
        }
    }, 1000);
}

// ─── READY UP ─────────────────────────────────────────────────────────────────
async function sendReady() {
    myReadyClicked = true;
    document.getElementById('readyBtn').disabled = true;
    const myReady = document.getElementById('myReadyLabel');
    myReady.textContent = 'You: Ready!';
    myReady.classList.remove('text-white-50');
    myReady.classList.add('text-success');
    const data = await api('ready', { code: roomCode });
    if (data.error) {
        // Don't kick out — show error inline and allow retry
        document.getElementById('readyBtn').disabled = false;
        myReady.textContent = 'You: Not ready (retry)';
        myReady.classList.remove('text-success');
        myReady.classList.add('text-warning');
        return;
    }
    if (data.both_ready) {
        startCountdown();
    } else {
        startReadyPoll();
    }
}

function startReadyPoll() {
    readyPollErrors = 0;
    readyPollHandle = setInterval(async () => {
        const data = await api('poll', { code: roomCode });
        if (data.error) {
            readyPollErrors++;
            if (readyPollErrors >= MAX_READY_ERRORS) {
                clearInterval(readyPollHandle);
                lobbyError('Lost connection: ' + data.error);
            }
            return;
        }
        readyPollErrors = 0;

        if (!data.p1_username || !data.p2_username) {
            // Opponent left
            clearInterval(readyPollHandle);
            readyPollHandle = null;
            lobbyError('Opponent left the room.');
            return;
        }

        const oppReady = myPlayer === 1 ? data.p2_ready : data.p1_ready;
        if (oppReady) {
            const oppReadyLabel = document.getElementById('oppReadyLabel');
            oppReadyLabel.textContent = oppName + ': Ready!';
            oppReadyLabel.classList.remove('text-white-50');
            oppReadyLabel.classList.add('text-success');
        }

        if (data.status === 'playing') {
            clearInterval(readyPollHandle);
            readyPollHandle = null;
            startCountdown();
        }
    }, 1000);
}

// ─── COUNTDOWN ────────────────────────────────────────────────────────────────
function startCountdown() {
    stopAllPolls();
    showPhase('countdownPhase');
    let n = 3;
    const el = document.getElementById('countdownNum');
    el.textContent = n;
    const cd = setInterval(() => {
        n--;
        if (n <= 0) { clearInterval(cd); el.textContent = 'START!'; setTimeout(startGame, 800); }
        else        { el.textContent = n; }
    }, 1000);
}

// ─── GAME INIT ────────────────────────────────────────────────────────────────
function startGame() {
    showPhase('gamePhase');

    myCanvas = document.getElementById('myCanvas');
    myCtx    = myCanvas.getContext('2d');
    myCanvas.width  = COLS * MY_BS;
    myCanvas.height = ROWS * MY_BS;

    oppCanvas = document.getElementById('oppCanvas');
    oppCtx    = oppCanvas.getContext('2d');
    oppCanvas.width  = COLS * OPP_BS;
    oppCanvas.height = ROWS * OPP_BS;

    myBoard = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
    cur     = randomPiece();
    nxt     = randomPiece();
    pos     = { x: 3, y: 0 };
    myScore = oppScore = 0;
    gameOver = playerFinished = resultShown = scoreSaved = false;
    gameEndReason = '';
    dropMs = 400;
    timerSecs = BLITZ_SECS;
    garbageQueue = [];
    myGarbagePending = 0;
    oppGarbageAcked  = 0;
    syncErrors = 0;
    syncInFlight = false;

    document.getElementById('oppNameHeader').textContent = oppName;
    document.getElementById('oppNameGame').textContent   = oppName;

    drawNextPiece();
    startTimer();
    syncHandle = setInterval(syncGame, 300);
    lastDropTime  = performance.now();
    lastSpeedTime = Date.now();
    requestAnimationFrame(gameLoop);
}

// ─── TIMER ───────────────────────────────────────────────────────────────────
function startTimer() {
    updateTimerEl();
    timerHandle = setInterval(() => {
        timerSecs--;
        updateTimerEl();
        if (timerSecs <= 0) { clearInterval(timerHandle); timerHandle = null; endGame('time_up'); }
    }, 1000);
}

function updateTimerEl() {
    const m = Math.floor(timerSecs / 60);
    const s = timerSecs % 60;
    const el = document.getElementById('timerDisplay');
    el.textContent = m + ':' + String(s).padStart(2, '0');
    el.style.color      = timerSecs <= 10 ? '#ff4444' : '';
    el.style.textShadow = timerSecs <= 10 ? '0 0 18px #ff4444' : '';
}

// ─── SYNC LOOP ────────────────────────────────────────────────────────────────
async function syncGame() {
    if (gameOver || syncInFlight) return;
    syncInFlight = true;

    const sentGarbage = playerFinished ? 0 : myGarbagePending;
    myGarbagePending  = 0;

    const data = await api('sync', {
        code:    roomCode,
        board:   myBoard,
        score:   myScore,
        alive:   !playerFinished && !gameOver,
        garbage: sentGarbage
    });

    syncInFlight = false;

    if (data.error) {
        myGarbagePending += sentGarbage;
        syncErrors++;
        if (syncErrors >= MAX_SYNC_ERRORS && !gameOver) {
            endGame('disconnected');
        }
        return;
    }
    syncErrors = 0;

    if (gameOver) return;

    oppScore = data.opp_score | 0;
    document.getElementById('oppScoreDisplay').textContent = oppScore;
    if (data.opp_board) renderOppBoard(data.opp_board);

    if (data.status === 'finished' && !gameOver) {
        endGame('server_finished');
        return;
    }

    const newGarbage = (data.opp_garbage | 0) - oppGarbageAcked;
    if (newGarbage > 0) {
        oppGarbageAcked = data.opp_garbage | 0;
        garbageQueue.push(newGarbage);
    }

    if (!data.opp_alive && data.opp_name && playerFinished && !gameOver) endGame('both_topped');
    if (data.opp_disconnected && !gameOver)            endGame('disconnected');
}

// ─── GAME LOOP ────────────────────────────────────────────────────────────────
function gameLoop(ts) {
    if (gameOver || playerFinished) return;
    if (ts - lastDropTime >= dropMs) { dropPiece(); lastDropTime = ts; }
    if (Date.now() - lastSpeedTime >= 5000) {
        dropMs = Math.max(80, dropMs - 10);
        lastSpeedTime = Date.now();
    }
    drawMyBoard();
    drawCurrent();
    requestAnimationFrame(gameLoop);
}

// ─── RENDERING ────────────────────────────────────────────────────────────────
function drawBlock(ctx, x, y, color, bs) {
    ctx.fillStyle = color;
    ctx.fillRect(x * bs, y * bs, bs, bs);
    ctx.strokeStyle = 'rgba(255,255,255,0.22)';
    ctx.lineWidth = 1;
    ctx.strokeRect(x * bs + 0.5, y * bs + 0.5, bs - 1, bs - 1);
    ctx.fillStyle = 'rgba(255,255,255,0.13)';
    ctx.fillRect(x * bs + 2, y * bs + 2, bs * 0.4, 3);
    ctx.fillRect(x * bs + 2, y * bs + 2, 3, bs * 0.4);
}

function drawGrid(ctx, bs) {
    ctx.strokeStyle = 'rgba(255,255,255,0.04)';
    ctx.lineWidth = 1;
    for (let y = 0; y < ROWS; y++)
        for (let x = 0; x < COLS; x++)
            ctx.strokeRect(x * bs, y * bs, bs, bs);
}

function drawMyBoard() {
    myCtx.clearRect(0, 0, myCanvas.width, myCanvas.height);
    drawGrid(myCtx, MY_BS);
    for (let y = 0; y < ROWS; y++)
        for (let x = 0; x < COLS; x++)
            if (myBoard[y][x]) drawBlock(myCtx, x, y, myBoard[y][x], MY_BS);
}

function drawCurrent() {
    const { shape, color } = cur;
    for (let y = 0; y < shape.length; y++)
        for (let x = 0; x < shape[y].length; x++)
            if (shape[y][x]) drawBlock(myCtx, pos.x + x, pos.y + y, color, MY_BS);
}

function renderOppBoard(board) {
    if (!oppCtx || !Array.isArray(board)) return;
    oppCtx.clearRect(0, 0, oppCanvas.width, oppCanvas.height);
    drawGrid(oppCtx, OPP_BS);
    for (let y = 0; y < ROWS && y < board.length; y++) {
        const row = board[y];
        if (!Array.isArray(row)) continue;
        for (let x = 0; x < COLS && x < row.length; x++)
            if (row[x]) drawBlock(oppCtx, x, y, row[x], OPP_BS);
    }
}

// ─── PIECE HELPERS ────────────────────────────────────────────────────────────
function emptyRow() { return Array(COLS).fill(null); }

function randomPiece() {
    const p = PIECES[Math.floor(Math.random() * PIECES.length)];
    return { color: p.color, shape: p.shape.map(r => [...r]) };
}

function collides(ox, oy, shape) {
    const s = shape || cur.shape;
    for (let y = 0; y < s.length; y++)
        for (let x = 0; x < s[y].length; x++)
            if (s[y][x]) {
                const nx = pos.x + x + ox, ny = pos.y + y + oy;
                if (nx < 0 || nx >= COLS || ny >= ROWS) return true;
                if (ny >= 0 && myBoard[ny][nx]) return true;
            }
    return false;
}

// ─── CONTROLS ─────────────────────────────────────────────────────────────────
function move(dx) {
    if (!gameOver && !playerFinished && !collides(dx, 0)) pos.x += dx;
}

function rotateTetromino() {
    if (gameOver || playerFinished) return;
    const orig = cur.shape;
    cur.shape = orig[0].map((_, i) => orig.map(r => r[i]).reverse());
    if (collides(0, 0)) cur.shape = orig;
}

function hardDrop() {
    if (gameOver || playerFinished) return;
    while (!collides(0, 1)) pos.y++;
    dropPiece();
}

function dropPiece() {
    if (playerFinished) return;
    if (!collides(0, 1)) { pos.y++; }
    else                  { lockPiece(); }
}

function lockPiece() {
    const { shape, color } = cur;
    for (let y = 0; y < shape.length; y++)
        for (let x = 0; x < shape[y].length; x++)
            if (shape[y][x] && pos.y + y >= 0)
                myBoard[pos.y + y][pos.x + x] = color;

    const lines = clearLines();

    if (garbageQueue.length > 0) {
        const total = garbageQueue.reduce((a, b) => a + b, 0);
        garbageQueue = [];
        addGarbage(total);
    }

    myGarbagePending += GARBAGE_TABLE[Math.min(lines, 4)];

    cur = nxt;
    nxt = randomPiece();
    pos = { x: 3, y: 0 };
    drawNextPiece();

    if (collides(0, 0)) markPlayerFinished('topped_out');
}

function clearLines() {
    let n = 0;
    for (let y = ROWS - 1; y >= 0; y--) {
        if (myBoard[y].every(c => c)) {
            myBoard.splice(y, 1);
            myBoard.unshift(emptyRow());
            n++; y++;
        }
    }
    if (n > 0) {
        myScore += n * 100;
        document.getElementById('myScoreDisplay').textContent = myScore;
    }
    return n;
}

function addGarbage(count) {
    for (let i = 0; i < count; i++) {
        const hole = Math.floor(Math.random() * COLS);
        const row  = Array(COLS).fill('#3c3c55');
        row[hole]  = null;
        myBoard.shift();
        myBoard.push(row);
    }
    const w = document.getElementById('garbageAlert');
    w.style.display = '';
    clearTimeout(w._t);
    w._t = setTimeout(() => { w.style.display = 'none'; }, 900);
}

// ─── NEXT PIECE DISPLAY ───────────────────────────────────────────────────────
function drawNextPiece() {
    const el = document.getElementById('nextPiece');
    if (!el || !nxt) return;
    el.innerHTML = '';
    const bs = 16;
    const { shape, color } = nxt;
    const wrap = document.createElement('div');
    wrap.style.cssText = `position:relative;width:${shape[0].length * bs}px;height:${shape.length * bs}px`;
    for (let y = 0; y < shape.length; y++)
        for (let x = 0; x < shape[y].length; x++)
            if (shape[y][x]) {
                const b = document.createElement('div');
                b.style.cssText = `width:${bs}px;height:${bs}px;background:${color};border:1px solid rgba(255,255,255,0.3);position:absolute;left:${x * bs}px;top:${y * bs}px`;
                wrap.appendChild(b);
            }
    el.appendChild(wrap);
}

// ─── OPP NAME ─────────────────────────────────────────────────────────────────
function setOppName(name) {
    ['oppNameReady','countdownOppName','oppNameHeader','oppNameGame','finalOppNameLabel']
        .forEach(id => { const e = document.getElementById(id); if (e) e.textContent = name; });
}

// ─── KEYBOARD ─────────────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (gameOver || playerFinished || document.getElementById('gamePhase').style.display === 'none') return;
    switch (e.key) {
        case 'ArrowLeft':  move(-1); break;
        case 'ArrowRight': move(1);  break;
        case 'ArrowDown':  dropPiece(); lastDropTime = performance.now(); break;
        case 'ArrowUp':    rotateTetromino(); break;
        case ' ':          hardDrop(); e.preventDefault(); break;
    }
});

// ─── TOUCH / SWIPE ────────────────────────────────────────────────────────────
let touchX = 0, touchY = 0;

document.addEventListener('touchstart', e => {
    if (document.getElementById('gamePhase').style.display === 'none') return;
    if (e.target.closest('.blitz-touch-btn')) return;
    touchX = e.touches[0].clientX;
    touchY = e.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchend', e => {
    if (gameOver || playerFinished || document.getElementById('gamePhase').style.display === 'none') return;
    if (e.target.closest('.blitz-touch-btn')) return;
    const dx = e.changedTouches[0].clientX - touchX;
    const dy = e.changedTouches[0].clientY - touchY;
    const d  = Math.hypot(dx, dy);
    if (d < 18)                     rotateTetromino();
    else if (Math.abs(dx) > Math.abs(dy)) move(dx > 0 ? 1 : -1);
    else if (dy > 0)                hardDrop();
    else                            rotateTetromino();
}, { passive: true });

// ─── END GAME ─────────────────────────────────────────────────────────────────
function markPlayerFinished(reason) {
    if (playerFinished || gameOver) return;
    playerFinished = true;
    gameEndReason = reason;
    myGarbagePending = 0;
    api('sync', {
        code: roomCode,
        board: myBoard,
        score: myScore,
        alive: false,
        garbage: 0
    }).catch(() => {});
}

async function endGame(reason) {
    if (gameOver) return;
    gameOver      = true;
    playerFinished = true;
    gameEndReason = reason;
    clearInterval(timerHandle);
    clearInterval(syncHandle);
    timerHandle = syncHandle = null;

    if (!scoreSaved) {
        scoreSaved = true;
        if (reason !== 'server_finished') {
            api('end', { code: roomCode, score: myScore, reason }).catch(() => {});
        }
        saveHighScore(myScore);
    }

    const delay = (reason === 'time_up') ? 600 : 900;
    setTimeout(() => {
        if (reason === 'disconnected') showResult('YOU WIN!', myScore, oppScore);
        else                           determineWinner();
    }, delay);
}

function determineWinner() {
    if (resultShown) return;
    if (myScore > oppScore)      showResult('YOU WIN!', myScore, oppScore);
    else if (oppScore > myScore) showResult('YOU LOST', myScore, oppScore);
    else                         showResult('DRAW!',    myScore, oppScore);
}

function showResult(title, me, opp) {
    if (resultShown) return;
    resultShown = true;
    const el = document.getElementById('resultTitle');
    el.textContent = title;
    el.style.color = title === 'YOU WIN!' ? '#00ff88' : title === 'DRAW!' ? '#ffcc00' : '#ff4444';
    document.getElementById('finalMyScore').textContent  = me;
    document.getElementById('finalOppScore').textContent = opp;
    setOppName(oppName);
    showPhase('resultPhase');
}

// ─── REMATCH ──────────────────────────────────────────────────────────────────
async function startRematch() {
    const btn = document.getElementById('rematchBtn');
    if (btn) btn.disabled = true;
    const statusEl = document.getElementById('rematchStatus');
    if (statusEl) { statusEl.textContent = 'Creating rematch...'; statusEl.style.display = ''; }

    const prevCode = roomCode;
    const data = await api('rematch', { code: prevCode });
    if (data.error) {
        if (btn) btn.disabled = false;
        if (statusEl) statusEl.textContent = 'Could not rematch: ' + data.error;
        return;
    }

    const newCode = data.rematch_code;
    gameOver = false; resultShown = false; scoreSaved = false;
    roomCode = newCode;

    if (data.is_host) {
        myPlayer = 1;
        resetReadyLobby();
        setRoomCodeDisplay(roomCode);
        showWaitingLobby('Rematch Lobby', 'Waiting for opponent to accept...', true);
        lobbyStatus('Waiting for opponent...');
        startLobbyPoll();
    } else {
        myPlayer = 2;
        if (data.opp_name) { oppName = data.opp_name; setOppName(oppName); }
        enterReadyPhase();
    }
}

// ─── SAVE HIGH SCORE (regular leaderboard) ────────────────────────────────────
function saveHighScore(score) {
    if (score <= 0) return;
    fetch('../dashboard/dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ score })
    }).catch(() => {});
}

// ─── LEAVE ON UNLOAD ──────────────────────────────────────────────────────────
window.addEventListener('beforeunload', () => {
    if (roomCode && !gameOver) {
        navigator.sendBeacon &&
        navigator.sendBeacon('../backEnd/blitz_api.php?action=leave',
            new Blob([JSON.stringify({ code: roomCode })], { type: 'application/json' }));
    }
});
