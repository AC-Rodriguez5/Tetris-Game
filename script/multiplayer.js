// ─── Constants ───────────────────────────────────────────────────────────────
const ROWS        = 15;
const COLS        = 10;
const MY_BS       = 38;   // block size — my board
const OPP_BS      = 24;   // block size — opponent board
const BLITZ_SECS  = 120;  // 2-minute blitz

const PIECES = [
    { color: 'cyan',    shape: [[1,1,1,1]] },
    { color: '#4488ff', shape: [[1,1],[1,1]] },
    { color: 'orange',  shape: [[1,1,1],[1,0,0]] },
    { color: '#ffdd00', shape: [[1,1,1],[0,0,1]] },
    { color: '#00dd66', shape: [[1,1,0],[0,1,1]] },
    { color: '#ff4444', shape: [[0,1,1],[1,1,0]] },
    { color: '#cc44ff', shape: [[0,1,0],[1,1,1]] },
];

// lines cleared → garbage rows sent
const GARBAGE_TABLE = [0, 0, 1, 2, 4];

// ─── Network state ────────────────────────────────────────────────────────────
let peer        = null;
let conn        = null;
let isHost      = false;
let helloSent   = false;

// ─── Match state ──────────────────────────────────────────────────────────────
let myReady  = false;
let oppReady = false;
let oppName  = 'Opponent';

// ─── Canvases ─────────────────────────────────────────────────────────────────
let myCanvas, myCtx, oppCanvas, oppCtx;

// ─── Game state ───────────────────────────────────────────────────────────────
let myBoard  = emptyBoard();
let cur      = null;
let nxt      = null;
let pos      = { x: 3, y: 0 };
let myScore  = 0;
let oppScore = 0;
let dropMs        = 400;
let lastDropTime  = 0;
let lastSpeedTime = 0;
let gameOver      = false;
let gameEndReason = '';   // 'TIME_UP' | 'TOPPED_OUT' | 'OPP_TOPPED'
let resultShown   = false;
let scoreSaved    = false;
let timerSecs     = BLITZ_SECS;
let timerHandle   = null;
let bcastHandle   = null;
let garbageQueue  = [];

// ─── Helpers ──────────────────────────────────────────────────────────────────
function emptyBoard() {
    return Array.from({ length: ROWS }, () => Array(COLS).fill(null));
}

function clonePiece(p) {
    return { color: p.color, shape: p.shape.map(r => [...r]) };
}

function randomPiece() {
    return clonePiece(PIECES[Math.floor(Math.random() * PIECES.length)]);
}

// ─── Phase management ─────────────────────────────────────────────────────────
const ALL_PHASES = [
    'lobbyPhase','waitingPhase','readyPhase',
    'countdownPhase','gamePhase','resultPhase'
];

function showPhase(id) {
    ALL_PHASES.forEach(p => { document.getElementById(p).style.display = 'none'; });
    document.getElementById(id).style.display = '';
}

// ─── Lobby error helper ───────────────────────────────────────────────────────
function lobbyError(msg) {
    const el = document.getElementById('lobbyError');
    el.textContent = msg;
    el.style.display = '';
    if (peer) { peer.destroy(); peer = null; }
    conn = null;
    helloSent = false;
    showPhase('lobbyPhase');
}

// ─── Create Room (host) ───────────────────────────────────────────────────────
function createRoom() {
    document.getElementById('lobbyError').style.display = 'none';
    isHost = true;
    helloSent = false;
    const code = makeCode();

    peer = new Peer(code, {
        config: {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun2.l.google.com:19302' },
            ]
        }
    });

    peer.on('open', id => {
        document.getElementById('roomCodeBig').textContent = id;
        showPhase('waitingPhase');
    });

    peer.on('connection', incoming => {
        conn = incoming;
        wireConn();
    });

    peer.on('error', err => {
        if (err.type === 'unavailable-id') {
            peer.destroy();
            createRoom();
        } else if (err.type === 'server-error' || err.type === 'socket-error') {
            lobbyError('Could not reach the matchmaking server. Check your internet connection.');
        } else {
            lobbyError('Error: ' + err.message);
        }
    });
}

function makeCode() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    return Array.from({ length: 6 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

function cancelAndReset() {
    if (peer) peer.destroy();
    peer = null; conn = null; helloSent = false;
    showPhase('lobbyPhase');
}

// ─── Join Room (guest) ────────────────────────────────────────────────────────
function joinRoom() {
    document.getElementById('lobbyError').style.display = 'none';
    const code = document.getElementById('joinCode').value.trim().toUpperCase();
    if (code.length < 4) { lobbyError('Enter a valid room code.'); return; }

    isHost = false;
    helloSent = false;

    peer = new Peer(undefined, {
        config: {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun2.l.google.com:19302' },
            ]
        }
    });

    peer.on('open', () => {
        conn = peer.connect(code, { reliable: true });
        wireConn();
    });

    peer.on('error', err => {
        const msg = err.type === 'peer-unavailable'
            ? 'Room not found. Double-check the code.'
            : 'Error: ' + err.message;
        lobbyError(msg);
    });
}

// ─── Wire connection events ───────────────────────────────────────────────────
function wireConn() {
    conn.on('data', onMessage);

    conn.on('close', () => {
        if (!gameOver) onDisconnect();
    });

    conn.on('error', () => {
        if (!gameOver) onDisconnect();
    });

    // Send HELLO exactly once when the channel is open
    const sendHello = () => {
        if (!helloSent) {
            helloSent = true;
            send({ type: 'HELLO', name: MY_USERNAME });
        }
    };

    if (conn.open) sendHello();
    else conn.on('open', sendHello);
}

function send(obj) {
    if (conn && conn.open) conn.send(obj);
}

function onDisconnect() {
    gameOver = true;
    clearInterval(timerHandle);
    clearInterval(bcastHandle);
    showResultScreen('OPPONENT LEFT', myScore, '—');
}

// ─── Message handler ──────────────────────────────────────────────────────────
function onMessage(data) {
    switch (data.type) {

        case 'HELLO':
            oppName = data.name;
            setOppName(oppName);
            showPhase('readyPhase');
            break;

        case 'READY':
            oppReady = true;
            document.getElementById('oppReadyLabel').textContent = oppName + ': Ready!';
            document.getElementById('oppReadyLabel').classList.replace('text-white-50', 'text-success');
            checkBothReady();
            break;

        case 'START':
            startCountdown();
            break;

        case 'BOARD':
            oppScore = data.score;
            document.getElementById('oppScoreDisplay').textContent = oppScore;
            renderOppBoard(data.board);
            if (data.dead && !gameOver) endGame('OPP_TOPPED');
            break;

        case 'GARBAGE':
            garbageQueue.push(data.lines);
            break;

        case 'FINAL':
            oppScore = data.score;
            document.getElementById('oppScoreDisplay').textContent = oppScore;
            // Only settle scores when both timers expired; top-out outcomes are fixed
            if (gameOver && gameEndReason === 'TIME_UP') determineWinner();
            break;
    }
}

function setOppName(name) {
    ['oppNameReady','countdownOppName','oppNameHeader','oppNameGame','finalOppNameLabel']
        .forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = name;
        });
}

// ─── Ready flow ───────────────────────────────────────────────────────────────
function sendReady() {
    myReady = true;
    document.getElementById('myReadyLabel').textContent = 'You: Ready!';
    document.getElementById('myReadyLabel').classList.replace('text-white-50', 'text-success');
    document.getElementById('readyBtn').disabled = true;
    send({ type: 'READY' });
    checkBothReady();
}

function checkBothReady() {
    if (myReady && oppReady && isHost) {
        setTimeout(() => {
            send({ type: 'START' });
            startCountdown();
        }, 300);
    }
}

// ─── Countdown 3-2-1-GO ───────────────────────────────────────────────────────
function startCountdown() {
    showPhase('countdownPhase');
    let n = 3;
    const el = document.getElementById('countdownNum');
    el.textContent = n;

    const id = setInterval(() => {
        n--;
        if (n <= 0) {
            clearInterval(id);
            el.textContent = 'GO!';
            setTimeout(startGame, 600);
        } else {
            el.textContent = n;
        }
    }, 1000);
}

// ─── Game initialization ──────────────────────────────────────────────────────
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

    myBoard      = emptyBoard();
    cur          = randomPiece();
    nxt          = randomPiece();
    pos          = { x: 3, y: 0 };
    myScore      = oppScore  = 0;
    gameOver     = resultShown = scoreSaved = false;
    gameEndReason = '';
    dropMs       = 400;
    timerSecs    = BLITZ_SECS;
    garbageQueue = [];

    drawNextPiece();
    startTimer();
    bcastHandle = setInterval(broadcastBoard, 250);

    lastDropTime  = performance.now();
    lastSpeedTime = Date.now();
    requestAnimationFrame(gameLoop);
}

// ─── Timer ────────────────────────────────────────────────────────────────────
function startTimer() {
    updateTimerEl();
    timerHandle = setInterval(() => {
        timerSecs--;
        updateTimerEl();
        if (timerSecs <= 0) {
            clearInterval(timerHandle);
            endGame('TIME_UP');
        }
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

// ─── Board broadcast ──────────────────────────────────────────────────────────
function broadcastBoard() {
    send({ type: 'BOARD', board: myBoard, score: myScore, dead: false });
}

// ─── Main loop ────────────────────────────────────────────────────────────────
function gameLoop(ts) {
    if (gameOver) return;

    if (ts - lastDropTime >= dropMs) {
        dropPiece();
        lastDropTime = ts;
    }

    if (Date.now() - lastSpeedTime >= 5000) {
        dropMs = Math.max(80, dropMs - 20);
        lastSpeedTime = Date.now();
    }

    drawMyBoard();
    drawCurrent();
    requestAnimationFrame(gameLoop);
}

// ─── Rendering ────────────────────────────────────────────────────────────────
function block(ctx, x, y, color, bs) {
    ctx.fillStyle = color;
    ctx.fillRect(x * bs, y * bs, bs, bs);
    ctx.strokeStyle = 'rgba(255,255,255,0.22)';
    ctx.lineWidth = 1;
    ctx.strokeRect(x * bs + 0.5, y * bs + 0.5, bs - 1, bs - 1);
    ctx.fillStyle = 'rgba(255,255,255,0.11)';
    ctx.fillRect(x * bs + 2, y * bs + 2, bs * 0.45, 3);
    ctx.fillRect(x * bs + 2, y * bs + 2, 3, bs * 0.45);
}

function grid(ctx, bs) {
    ctx.strokeStyle = 'rgba(255,255,255,0.04)';
    ctx.lineWidth = 1;
    for (let y = 0; y < ROWS; y++)
        for (let x = 0; x < COLS; x++)
            ctx.strokeRect(x * bs, y * bs, bs, bs);
}

function drawMyBoard() {
    myCtx.clearRect(0, 0, myCanvas.width, myCanvas.height);
    grid(myCtx, MY_BS);
    for (let y = 0; y < ROWS; y++)
        for (let x = 0; x < COLS; x++)
            if (myBoard[y][x]) block(myCtx, x, y, myBoard[y][x], MY_BS);
}

function drawCurrent() {
    const { shape, color } = cur;
    for (let y = 0; y < shape.length; y++)
        for (let x = 0; x < shape[y].length; x++)
            if (shape[y][x]) block(myCtx, pos.x + x, pos.y + y, color, MY_BS);
}

function renderOppBoard(board) {
    oppCtx.clearRect(0, 0, oppCanvas.width, oppCanvas.height);
    grid(oppCtx, OPP_BS);
    for (let y = 0; y < ROWS; y++)
        for (let x = 0; x < COLS; x++)
            if (board[y][x]) block(oppCtx, x, y, board[y][x], OPP_BS);
}

// ─── Collision ────────────────────────────────────────────────────────────────
function collides(ox, oy, shapeOverride) {
    const s = shapeOverride || cur.shape;
    for (let y = 0; y < s.length; y++)
        for (let x = 0; x < s[y].length; x++)
            if (s[y][x]) {
                const nx = pos.x + x + ox;
                const ny = pos.y + y + oy;
                if (nx < 0 || nx >= COLS || ny >= ROWS) return true;
                if (ny >= 0 && myBoard[ny][nx]) return true;
            }
    return false;
}

// ─── Piece control ────────────────────────────────────────────────────────────
function move(dx) {
    if (!gameOver && !collides(dx, 0)) pos.x += dx;
}

function rotateTetromino() {
    if (gameOver) return;
    const orig = cur.shape;
    cur.shape = orig[0].map((_, i) => orig.map(r => r[i]).reverse());
    if (collides(0, 0)) cur.shape = orig;
}

function hardDrop() {
    if (gameOver) return;
    while (!collides(0, 1)) pos.y++;
    dropPiece();
}

function dropPiece() {
    if (!collides(0, 1)) {
        pos.y++;
    } else {
        lockPiece();
    }
}

function lockPiece() {
    // Merge into board
    const { shape, color } = cur;
    for (let y = 0; y < shape.length; y++)
        for (let x = 0; x < shape[y].length; x++)
            if (shape[y][x] && pos.y + y >= 0)
                myBoard[pos.y + y][pos.x + x] = color;

    const lines = clearLines();

    // Apply queued garbage after clearing own lines
    if (garbageQueue.length > 0) {
        const total = garbageQueue.reduce((a, b) => a + b, 0);
        garbageQueue = [];
        addGarbage(total);
    }

    // Send garbage proportional to lines cleared
    const g = GARBAGE_TABLE[Math.min(lines, 4)];
    if (g > 0) send({ type: 'GARBAGE', lines: g });

    // Spawn next
    cur = nxt;
    nxt = randomPiece();
    pos = { x: 3, y: 0 };
    drawNextPiece();

    if (collides(0, 0)) {
        send({ type: 'BOARD', board: myBoard, score: myScore, dead: true });
        endGame('TOPPED_OUT');
    }
}

// ─── Line clearing ────────────────────────────────────────────────────────────
function clearLines() {
    let cleared = 0;
    for (let y = ROWS - 1; y >= 0; y--) {
        if (myBoard[y].every(c => c)) {
            myBoard.splice(y, 1);
            myBoard.unshift(Array(COLS).fill(null));
            cleared++;
            y++;
        }
    }
    if (cleared > 0) {
        myScore += cleared * 100;
        document.getElementById('myScoreDisplay').textContent = myScore;
    }
    return cleared;
}

// ─── Garbage ──────────────────────────────────────────────────────────────────
function addGarbage(count) {
    for (let i = 0; i < count; i++) {
        const hole = Math.floor(Math.random() * COLS);
        const row = Array(COLS).fill('#3c3c55');
        row[hole] = null;
        myBoard.shift();
        myBoard.push(row);
    }
    const warn = document.getElementById('garbageAlert');
    warn.style.display = '';
    setTimeout(() => { warn.style.display = 'none'; }, 900);
}

// ─── Next piece preview ───────────────────────────────────────────────────────
function drawNextPiece() {
    const el = document.getElementById('nextPiece');
    if (!el || !nxt) return;
    el.innerHTML = '';
    const bs = 16;
    const { shape, color } = nxt;
    const wrap = document.createElement('div');
    wrap.style.cssText = `position:relative;width:${shape[0].length*bs}px;height:${shape.length*bs}px`;
    for (let y = 0; y < shape.length; y++)
        for (let x = 0; x < shape[y].length; x++)
            if (shape[y][x]) {
                const b = document.createElement('div');
                b.style.cssText = `width:${bs}px;height:${bs}px;background:${color};border:1px solid rgba(255,255,255,0.3);position:absolute;left:${x*bs}px;top:${y*bs}px`;
                wrap.appendChild(b);
            }
    el.appendChild(wrap);
}

// ─── Keyboard ─────────────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (gameOver || document.getElementById('gamePhase').style.display === 'none') return;
    switch (e.key) {
        case 'ArrowLeft':  move(-1); break;
        case 'ArrowRight': move(1);  break;
        case 'ArrowDown':  dropPiece(); lastDropTime = performance.now(); break;
        case 'ArrowUp':    rotateTetromino(); break;
        case ' ':          hardDrop(); e.preventDefault(); break;
    }
});

// ─── Touch / swipe ────────────────────────────────────────────────────────────
let touchX = 0, touchY = 0;

document.addEventListener('touchstart', e => {
    if (document.getElementById('gamePhase').style.display === 'none') return;
    if (e.target.closest('.blitz-touch-btn')) return;
    touchX = e.touches[0].clientX;
    touchY = e.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchend', e => {
    if (gameOver || document.getElementById('gamePhase').style.display === 'none') return;
    if (e.target.closest('.blitz-touch-btn')) return;
    const dx = e.changedTouches[0].clientX - touchX;
    const dy = e.changedTouches[0].clientY - touchY;
    const dist = Math.hypot(dx, dy);

    if (dist < 18) {
        rotateTetromino();
    } else if (Math.abs(dx) > Math.abs(dy)) {
        move(dx > 0 ? 1 : -1);
    } else if (dy > 0) {
        hardDrop();
    } else {
        rotateTetromino();
    }
}, { passive: true });

// ─── End game ─────────────────────────────────────────────────────────────────
function endGame(reason) {
    if (gameOver) return;
    gameOver = true;
    gameEndReason = reason;
    clearInterval(timerHandle);
    clearInterval(bcastHandle);

    if (!scoreSaved) {
        scoreSaved = true;
        send({ type: 'FINAL', score: myScore });
        saveHighScore(myScore);
    }

    if (reason === 'TOPPED_OUT') {
        setTimeout(() => showResultScreen('YOU LOST', myScore, oppScore), 900);
    } else if (reason === 'OPP_TOPPED') {
        setTimeout(() => showResultScreen('YOU WIN!', myScore, oppScore), 900);
    } else {
        // TIME_UP — wait briefly for opponent's FINAL before comparing
        setTimeout(determineWinner, 1800);
    }
}

function determineWinner() {
    if (resultShown) return;
    if (myScore > oppScore)      showResultScreen('YOU WIN!', myScore, oppScore);
    else if (oppScore > myScore) showResultScreen('YOU LOST', myScore, oppScore);
    else                         showResultScreen('DRAW!',    myScore, oppScore);
}

function showResultScreen(title, me, opp) {
    if (resultShown) return;
    resultShown = true;

    const el = document.getElementById('resultTitle');
    el.textContent = title;
    el.style.color =
        title === 'YOU WIN!' ? '#00ff88' :
        title === 'DRAW!'    ? '#ffcc00' : '#ff4444';

    document.getElementById('finalMyScore').textContent  = me;
    document.getElementById('finalOppScore').textContent = opp;
    setOppName(oppName);
    showPhase('resultPhase');
}

// ─── Save score via existing endpoint ────────────────────────────────────────
function saveHighScore(score) {
    if (score <= 0) return;
    fetch('../dashboard/dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ score })
    }).catch(() => {});
}
x