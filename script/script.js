// COSMIC TETRIS - PREMIUM GAME ENGINE
const canvas = document.getElementById('tetrisCanvas');
if (!canvas) console.warn('Tetris canvas not found.');
const ctx = canvas ? canvas.getContext('2d') : null;
const ROWS=20, COLS=10, BLOCK_SIZE=30;
if(canvas&&ctx){canvas.width=COLS*BLOCK_SIZE;canvas.height=ROWS*BLOCK_SIZE;}

const pCanvas=document.getElementById('particleCanvas');
const pCtx=pCanvas?pCanvas.getContext('2d'):null;
if(pCanvas){pCanvas.width=COLS*BLOCK_SIZE;pCanvas.height=ROWS*BLOCK_SIZE;}

const TETROMINOES=[
    {color:'#00f3ff',shape:[[1,1,1,1]]},
    {color:'#ffd700',shape:[[1,1],[1,1]]},
    {color:'#4a6fff',shape:[[1,1,1],[1,0,0]]},
    {color:'#ff8c00',shape:[[1,1,1],[0,0,1]]},
    {color:'#00ff88',shape:[[0,1,1],[1,1,0]]},
    {color:'#ff007f',shape:[[1,1,0],[0,1,1]]},
    {color:'#b026ff',shape:[[0,1,0],[1,1,1]]},
];

let board=Array.from({length:ROWS},()=>Array(COLS).fill(null));
let currentTetromino=null,nextTetromino=null;
let currentPosition={x:3,y:0};
let score=0,lines=0,level=1,combo=0;
let interval=400,gameOver=false,scoreSent=false;
let lastMoveTime=0,speedIncreaseInterval=5000,lastSpeedIncreaseTime=Date.now();
let particles=[];
let pieceBag=[],gameStartedAt=Date.now(),lastFocusedBeforeGameOver=null;

// ── AUDIO ENGINE ──────────────────────────────────────────────
const AudioEngine=(()=>{
    let AC=null,masterGain=null,sfxGain=null,musicGain=null;
    let musicTimer=null,enabled=true,musicEnabled=true,beat=0;
    const SCALE=[130.81,155.56,174.61,196,220,261.63,293.66,329.63,349.23,392];
    const BASS=[65.41,87.31,98,110];
    const ARP=[0,4,7,4,2,7,4,2];

    function ensureCtx(){
        if(AC)return;
        AC=new(window.AudioContext||window.webkitAudioContext)();
        masterGain=AC.createGain();masterGain.gain.value=0.7;masterGain.connect(AC.destination);
        sfxGain=AC.createGain();sfxGain.gain.value=0.85;sfxGain.connect(masterGain);
        musicGain=AC.createGain();musicGain.gain.value=0;musicGain.connect(masterGain);
        musicGain.gain.linearRampToValueAtTime(0.22,AC.currentTime+2);
    }
    function note({freq=440,type='square',gain=0.4,duration=0.12,delay=0,attack=0.005,decay=0.05,sustain=0.5,filterFreq=null,dest=null}){
        if(!AC||!enabled)return;
        const t=AC.currentTime+delay;
        const osc=AC.createOscillator(),env=AC.createGain();
        osc.type=type;osc.frequency.setValueAtTime(freq,t);
        env.gain.setValueAtTime(0,t);env.gain.linearRampToValueAtTime(gain,t+attack);
        env.gain.linearRampToValueAtTime(gain*sustain,t+attack+decay);
        env.gain.exponentialRampToValueAtTime(0.0001,t+duration);
        if(filterFreq){const f=AC.createBiquadFilter();f.type='lowpass';f.frequency.value=filterFreq;osc.connect(f);f.connect(env);}
        else osc.connect(env);
        env.connect(dest||sfxGain);osc.start(t);osc.stop(t+duration+0.02);
    }
    function noise({gain=0.3,duration=0.08,delay=0,filterFreq=2000}){
        if(!AC||!enabled)return;
        const t=AC.currentTime+delay,size=AC.sampleRate*duration;
        const buf=AC.createBuffer(1,size,AC.sampleRate),data=buf.getChannelData(0);
        for(let i=0;i<size;i++)data[i]=Math.random()*2-1;
        const src=AC.createBufferSource();src.buffer=buf;
        const flt=AC.createBiquadFilter();flt.type='bandpass';flt.frequency.value=filterFreq;flt.Q.value=0.5;
        const env=AC.createGain();env.gain.setValueAtTime(gain,t);env.gain.exponentialRampToValueAtTime(0.0001,t+duration);
        src.connect(flt);flt.connect(env);env.connect(sfxGain);src.start(t);src.stop(t+duration);
    }
    function sndMove(){note({freq:180,type:'square',gain:0.15,duration:0.06,attack:0.002,decay:0.03,sustain:0.2});}
    function sndRotate(){note({freq:320,type:'sine',gain:0.25,duration:0.1,attack:0.003,decay:0.04,sustain:0.3});note({freq:480,type:'sine',gain:0.15,duration:0.08,attack:0.002,decay:0.03,sustain:0.2,delay:0.04});}
    function sndPlace(){noise({gain:0.4,duration:0.07,filterFreq:1500});note({freq:90,type:'sawtooth',gain:0.3,duration:0.12,attack:0.001,decay:0.08,sustain:0.1,filterFreq:500});}
    function sndHardDrop(){noise({gain:0.6,duration:0.12,filterFreq:2500});note({freq:60,type:'sawtooth',gain:0.5,duration:0.2,attack:0.001,decay:0.12,sustain:0.1,filterFreq:400});}
    function sndLineClear(count){
        const freqs=count===4?[220,277,330,440,554,659,880]:count===3?[220,330,440,554]:count===2?[220,330,440]:[220,330];
        freqs.forEach((f,i)=>note({freq:f,type:'sine',gain:0.4,duration:0.18,attack:0.005,decay:0.05,sustain:0.6,delay:i*0.06}));
        if(count===4){[880,1100,1320].forEach((f,i)=>note({freq:f,type:'triangle',gain:0.3,duration:0.25,attack:0.01,decay:0.08,sustain:0.5,delay:0.3+i*0.07}));}
    }
    function sndLevelUp(){
        [262,330,392,523,659,784,1047].forEach((f,i)=>note({freq:f,type:'square',gain:0.35,duration:0.15,attack:0.005,decay:0.04,sustain:0.5,delay:i*0.07,filterFreq:3000}));
        [1046,1318,1568].forEach((f,i)=>note({freq:f,type:'sine',gain:0.2,duration:0.2,attack:0.01,decay:0.06,sustain:0.4,delay:0.5+i*0.09}));
    }
    function sndGameOver(){
        [392,330,262,220,196,165,131,98].forEach((f,i)=>note({freq:f,type:'sawtooth',gain:0.4,duration:0.25,attack:0.01,decay:0.1,sustain:0.4,delay:i*0.12,filterFreq:1500}));
        noise({gain:0.3,duration:1.0,filterFreq:800,delay:0.1});
    }
    function startMusic(){
        if(!musicEnabled||!AC)return;
        beat=0;
        musicTimer=setInterval(()=>{
            if(!musicEnabled||!AC)return;
            const t=AC.currentTime;
            const freq=SCALE[ARP[beat%ARP.length]%SCALE.length]*(Math.floor(beat/ARP.length)%2===0?1:2);
            const bassF=BASS[beat%BASS.length];
            const osc=AC.createOscillator(),env=AC.createGain();
            osc.type='square';osc.frequency.setValueAtTime(freq,t);
            env.gain.setValueAtTime(0.16,t);env.gain.exponentialRampToValueAtTime(0.0001,t+0.14);
            osc.connect(env);env.connect(musicGain);osc.start(t);osc.stop(t+0.15);
            if(beat%4===0){
                const b=AC.createOscillator(),be=AC.createGain(),bf=AC.createBiquadFilter();
                b.type='sawtooth';b.frequency.setValueAtTime(bassF,t);
                bf.type='lowpass';bf.frequency.value=400;
                be.gain.setValueAtTime(0.22,t);be.gain.exponentialRampToValueAtTime(0.0001,t+0.35);
                b.connect(bf);bf.connect(be);be.connect(musicGain);b.start(t);b.stop(t+0.36);
            }
            beat++;
        },160);
    }
    function stopMusic(){if(musicTimer){clearInterval(musicTimer);musicTimer=null;}if(musicGain&&AC)musicGain.gain.linearRampToValueAtTime(0,AC.currentTime+0.5);}
    function toggleAudio(){
        enabled=!enabled;musicEnabled=enabled;
        if(!enabled)stopMusic();
        else{ensureCtx();musicGain.gain.linearRampToValueAtTime(0.22,AC.currentTime+1);startMusic();}
        return enabled;
    }
    function init(){ensureCtx();startMusic();}
    return{init,toggleAudio,
        move:()=>{ensureCtx();sndMove();},rotate:()=>{ensureCtx();sndRotate();},
        place:()=>{ensureCtx();sndPlace();},hardDrop:()=>{ensureCtx();sndHardDrop();},
        lineClear:(n)=>{ensureCtx();sndLineClear(n);},levelUp:()=>{ensureCtx();sndLevelUp();},
        gameOver:()=>{ensureCtx();sndGameOver();},isEnabled:()=>enabled};
})();

// ── PARTICLES ──────────────────────────────────────────────────
function spawnLineClearParticles(clearedRows){
    if(!pCtx)return;
    clearedRows.forEach(row=>{
        for(let x=0;x<COLS;x++){
            for(let i=0;i<7;i++){
                const hue=Math.random()*360;
                particles.push({x:(x+0.5)*BLOCK_SIZE,y:(row+0.5)*BLOCK_SIZE,
                    vx:(Math.random()-0.5)*9,vy:(Math.random()-1.8)*8,
                    life:1,decay:0.022+Math.random()*0.02,
                    radius:2+Math.random()*4,color:`hsl(${hue},100%,70%)`});
            }
        }
    });
}
function updateParticles(){
    if(!pCtx)return;
    pCtx.clearRect(0,0,pCanvas.width,pCanvas.height);
    particles=particles.filter(p=>p.life>0);
    particles.forEach(p=>{
        p.x+=p.vx;p.y+=p.vy;p.vy+=0.28;p.life-=p.decay;
        pCtx.globalAlpha=Math.max(0,p.life);pCtx.fillStyle=p.color;
        pCtx.shadowColor=p.color;pCtx.shadowBlur=10;
        pCtx.beginPath();pCtx.arc(p.x,p.y,p.radius,0,Math.PI*2);pCtx.fill();
    });
    pCtx.globalAlpha=1;pCtx.shadowBlur=0;
}

// ── SCREEN SHAKE / BOARD FLASH ────────────────────────────────
function triggerScreenShake(){
    const el=document.querySelector('.solo-game-shell')||document.body;
    el.classList.add('screen-shake');setTimeout(()=>el.classList.remove('screen-shake'),500);
}
function triggerBoardFlash(isTetris){
    const el=document.querySelector('.tetris-board');if(!el)return;
    const cls=isTetris?'tetris-flash':'line-clear-flash';
    el.classList.add(cls);setTimeout(()=>el.classList.remove(cls),isTetris?700:400);
}

// ── HUD ────────────────────────────────────────────────────────
function getCsrfToken(){const m=document.querySelector('meta[name="csrf-token"]');return m?m.content:'';}
function updateHUD(){
    const el=id=>document.getElementById(id);
    if(el('hud-score'))el('hud-score').textContent=score;
    if(el('hud-level'))el('hud-level').textContent=level;
    if(el('hud-lines'))el('hud-lines').textContent=lines;
    if(el('score'))el('score').textContent='Score: '+score;
}
function animateScorePop(){
    const el=document.getElementById('hud-score');if(!el)return;
    el.classList.remove('score-pop');void el.offsetWidth;el.classList.add('score-pop');
}
function showLevelUpBanner(lvl){
    const b=document.getElementById('level-up-banner');if(!b)return;
    b.textContent='LEVEL '+lvl;b.classList.remove('show');void b.offsetWidth;b.classList.add('show');
    setTimeout(()=>b.classList.remove('show'),1600);
    const el=document.getElementById('hud-level');
    if(el){el.classList.remove('level-up-flash');void el.offsetWidth;el.classList.add('level-up-flash');}
}
function updateComboDisplay(c){
    const el=document.getElementById('combo-display');if(!el)return;
    if(c>=2){
        el.hidden=false;
        el.classList.add('active');
        const n=el.querySelector('.combo-num');
        if(n){n.textContent=c;n.classList.remove('combo-pulse');void n.offsetWidth;n.classList.add('combo-pulse');}
    }else{el.classList.remove('active');el.hidden=true;}
}

// ── SAVE SCORE ─────────────────────────────────────────────────
function saveHighScore(){
    return fetch('../dashboard/dashboard.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({score,elapsed_ms:Date.now()-gameStartedAt,csrf_token:getCsrfToken()})})
    .then(r=>r.json()).then(d=>d.success?d.score:null).catch(()=>null);
}
function quitGame(){
    if(score>0){saveHighScore().finally(()=>{window.location.href='dashboard.php';});return;}
    window.location.href='dashboard.php';
}

// ── TETROMINOS ─────────────────────────────────────────────────
function refillPieceBag(){
    pieceBag=TETROMINOES.map((_,i)=>i);
    for(let i=pieceBag.length-1;i>0;i--){
        const j=Math.floor(Math.random()*(i+1));
        [pieceBag[i],pieceBag[j]]=[pieceBag[j],pieceBag[i]];
    }
}
function getRandomTetromino(){
    if(pieceBag.length===0)refillPieceBag();
    const p=TETROMINOES[pieceBag.pop()];
    return{color:p.color,shape:p.shape.map(r=>r.slice())};
}
function initializeTetrominos(){currentTetromino=getRandomTetromino();nextTetromino=getRandomTetromino();updateNextPieceDisplay();}
initializeTetrominos();

function updateNextPieceDisplay(){
    const c=document.getElementById('nextPiece');if(!c||!nextTetromino)return;
    c.innerHTML='';
    const shape=nextTetromino.shape,color=nextTetromino.color,bs=22;
    const wrap=document.createElement('div');
    wrap.style.cssText=`position:relative;width:${shape[0].length*bs}px;height:${shape.length*bs}px;margin:auto;`;
    shape.forEach((row,y)=>row.forEach((cell,x)=>{
        if(!cell)return;
        const b=document.createElement('div');
        b.style.cssText=`position:absolute;width:${bs}px;height:${bs}px;left:${x*bs}px;top:${y*bs}px;background:${color};border:1px solid rgba(0,0,0,0.4);border-radius:3px;box-shadow:inset 0 0 6px rgba(255,255,255,0.35),0 0 8px ${color}88;`;
        wrap.appendChild(b);
    }));
    const outer=document.createElement('div');
    outer.style.cssText='display:flex;align-items:center;justify-content:center;width:100%;height:100%;';
    outer.appendChild(wrap);c.appendChild(outer);
}

function lightenColor(hex,amt){
    const n=parseInt(hex.replace('#',''),16);
    return `rgb(${Math.min(255,(n>>16)+amt)},${Math.min(255,((n>>8)&0xff)+amt)},${Math.min(255,(n&0xff)+amt)})`;
}
function drawBlock(x,y,color,alpha=1){
    if(!ctx)return;ctx.save();ctx.globalAlpha=alpha;
    const g=ctx.createLinearGradient(x*BLOCK_SIZE,y*BLOCK_SIZE,(x+1)*BLOCK_SIZE,(y+1)*BLOCK_SIZE);
    g.addColorStop(0,lightenColor(color,50));g.addColorStop(1,color);
    ctx.shadowColor=color;ctx.shadowBlur=14;ctx.fillStyle=g;
    const p=1;ctx.fillRect(x*BLOCK_SIZE+p,y*BLOCK_SIZE+p,BLOCK_SIZE-p*2,BLOCK_SIZE-p*2);
    ctx.shadowBlur=0;ctx.globalAlpha=alpha*0.35;ctx.fillStyle='rgba(255,255,255,0.7)';
    ctx.fillRect(x*BLOCK_SIZE+p,y*BLOCK_SIZE+p,BLOCK_SIZE-p*2,4);
    ctx.fillRect(x*BLOCK_SIZE+p,y*BLOCK_SIZE+p,4,BLOCK_SIZE-p*2);
    ctx.restore();
}
function drawBoard(){
    if(!ctx)return;ctx.clearRect(0,0,canvas.width,canvas.height);
    for(let y=0;y<ROWS;y++)for(let x=0;x<COLS;x++)if(board[y][x])drawBlock(x,y,board[y][x]);
}
function drawTetromino(){
    if(!currentTetromino||!ctx)return;
    currentTetromino.shape.forEach((row,y)=>row.forEach((cell,x)=>{if(cell)drawBlock(currentPosition.x+x,currentPosition.y+y,currentTetromino.color);}));
}
function drawGhost(){
    if(!currentTetromino||gameOver||!ctx)return;
    let dy=0;while(!hasCollision(0,dy+1))dy++;if(dy===0)return;
    ctx.save();ctx.globalAlpha=0.18;
    currentTetromino.shape.forEach((row,y)=>row.forEach((cell,x)=>{
        if(!cell)return;
        ctx.strokeStyle=currentTetromino.color;ctx.lineWidth=2;
        ctx.strokeRect((currentPosition.x+x)*BLOCK_SIZE+2,(currentPosition.y+dy+y)*BLOCK_SIZE+2,BLOCK_SIZE-4,BLOCK_SIZE-4);
    }));
    ctx.restore();
}

// ── COLLISION ──────────────────────────────────────────────────
function hasCollision(offX,offY){
    return currentTetromino.shape.some((row,y)=>row.some((cell,x)=>{
        if(!cell)return false;
        const nx=currentPosition.x+x+offX,ny=currentPosition.y+y+offY;
        return nx<0||nx>=COLS||ny>=ROWS||(ny>=0&&board[ny][nx]);
    }));
}
function mergeTetromino(){
    currentTetromino.shape.forEach((row,y)=>row.forEach((cell,x)=>{
        if(cell)board[currentPosition.y+y][currentPosition.x+x]=currentTetromino.color;
    }));
}
function removeRow(){
    const cleared=[];
    for(let y=ROWS-1;y>=0;y--)if(board[y].every(c=>c))cleared.push(y);
    const count=cleared.length;
    if(count===0){combo=0;updateComboDisplay(0);return;}
    cleared.sort((a,b)=>b-a);
    cleared.forEach(r=>{board.splice(r,1);board.unshift(Array(COLS).fill(null));});
    const pts=[0,100,300,500,800][count]*level;
    score+=pts;lines+=count;combo+=1;
    if(combo>=2)score+=combo*50;
    const prev=level;level=Math.floor(lines/10)+1;interval=Math.max(80,400-(level-1)*30);
    updateHUD();animateScorePop();updateComboDisplay(combo);
    spawnLineClearParticles(cleared);AudioEngine.lineClear(count);
    triggerBoardFlash(count===4);if(count===4)triggerScreenShake();
    if(level>prev){showLevelUpBanner(level);AudioEngine.levelUp();}
}
function rotateTetromino(){
    const orig=currentTetromino.shape;
    const newS=orig[0].map((_,i)=>orig.map(r=>r[i]).reverse());
    currentTetromino.shape=newS;
    if(hasCollision(0,0))currentTetromino.shape=orig;else AudioEngine.rotate();
}
function moveDown(isAuto=true){
    if(!hasCollision(0,1)){currentPosition.y++;if(!isAuto)AudioEngine.move();}
    else{
        AudioEngine.place();mergeTetromino();removeRow();
        currentTetromino=nextTetromino;nextTetromino=getRandomTetromino();
        updateNextPieceDisplay();currentPosition={x:3,y:0};
        if(hasCollision(0,0))gameOver=true;
    }
}
function move(offX){if(!hasCollision(offX,0)){currentPosition.x+=offX;AudioEngine.move();}}
function hardDrop(){
    if(gameOver)return;
    let d=0;while(!hasCollision(0,1)){currentPosition.y++;d++;}
    score+=d*2;updateHUD();AudioEngine.hardDrop();moveDown(false);
}
function incrementSpeed(){
    const now=Date.now();
    if(now-lastSpeedIncreaseTime>=speedIncreaseInterval){interval=Math.max(80,interval-20);lastSpeedIncreaseTime=now;}
}

// ── GAME OVER MODAL ────────────────────────────────────────────
function showGameOverModal(){
    const ov=document.getElementById('game-over-overlay');
    if(!ov){setTimeout(()=>alert('Game Over! Score: '+score),50);return;}
    const el=ov.querySelector('.game-over-score-num');if(el)el.textContent=score;
    lastFocusedBeforeGameOver=document.activeElement instanceof HTMLElement?document.activeElement:null;
    ov.hidden=false;
    ov.classList.add('show');ov.setAttribute('aria-hidden','false');
    const target=document.getElementById('game-over-restart')||ov.querySelector('button');
    if(target)target.focus({preventScroll:true});
}
function hideGameOverModal(){
    const ov=document.getElementById('game-over-overlay');if(!ov)return;
    ov.classList.remove('show');ov.setAttribute('aria-hidden','true');
    ov.hidden=true;
    const fallback=document.getElementById('tetrisCanvas');
    const target=lastFocusedBeforeGameOver&&document.contains(lastFocusedBeforeGameOver)?lastFocusedBeforeGameOver:fallback;
    if(target&&typeof target.focus==='function')target.focus({preventScroll:true});
}
function isGameOverModalOpen(){
    const ov=document.getElementById('game-over-overlay');
    return !!(ov&&ov.classList.contains('show'));
}
function trapGameOverFocus(e){
    const ov=document.getElementById('game-over-overlay');if(!ov)return;
    const focusable=[...ov.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')].filter(el=>!el.disabled&&el.offsetParent!==null);
    if(focusable.length===0)return;
    const first=focusable[0],last=focusable[focusable.length-1];
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
}

// ── GAME LOOP ──────────────────────────────────────────────────
function gameLoop(t=performance.now()){
    if(!canvas||!ctx)return;
    if(gameOver){
        if(!scoreSent){saveHighScore();scoreSent=true;}
        AudioEngine.gameOver();triggerScreenShake();
        setTimeout(showGameOverModal,700);return;
    }
    if(t-lastMoveTime>=interval){moveDown(true);lastMoveTime=t;}
    drawBoard();drawGhost();drawTetromino();updateParticles();incrementSpeed();
    requestAnimationFrame(gameLoop);
}

// ── INPUT ──────────────────────────────────────────────────────
function handleGameAction(action){
    if(gameOver)return;
    startAudio();
    switch(action){
        case 'left':move(-1);break;
        case 'right':move(1);break;
        case 'down':moveDown(false);break;
        case 'rotate':rotateTetromino();break;
        case 'drop':hardDrop();break;
    }
}
document.addEventListener('keydown',e=>{
    if(gameOver){
        if(e.key==='Escape'&&isGameOverModalOpen()){e.preventDefault();quitGame();return;}
        if(e.key==='Tab'&&isGameOverModalOpen())trapGameOverFocus(e);
        return;
    }
    switch(e.key){
        case 'ArrowLeft': e.preventDefault();handleGameAction('left');break;
        case 'ArrowRight':e.preventDefault();handleGameAction('right');break;
        case 'ArrowDown': e.preventDefault();handleGameAction('down');break;
        case 'ArrowUp':   e.preventDefault();handleGameAction('rotate');break;
        case ' ':         e.preventDefault();handleGameAction('drop');break;
        case 'z':case 'Z':e.preventDefault();handleGameAction('rotate');break;
    }
});
document.querySelectorAll('[data-touch-action]').forEach(btn=>{
    btn.addEventListener('pointerdown',e=>{
        e.preventDefault();
        handleGameAction(btn.dataset.touchAction);
    });
});

// ── RESTART ────────────────────────────────────────────────────
function restartGame(){
    hideGameOverModal();
    if(score>0&&!scoreSent)saveHighScore().finally(performRestart);else performRestart();
}
function performRestart(){
    board=Array.from({length:ROWS},()=>Array(COLS).fill(null));
    pieceBag=[];
    currentTetromino=getRandomTetromino();nextTetromino=getRandomTetromino();
    updateNextPieceDisplay();currentPosition={x:3,y:0};
    score=lines=combo=0;level=1;interval=400;gameOver=false;scoreSent=false;particles=[];
    gameStartedAt=Date.now();
    updateHUD();updateComboDisplay(0);
    lastMoveTime=performance.now();lastSpeedIncreaseTime=Date.now();
    requestAnimationFrame(gameLoop);
}

// ── AUDIO TOGGLE ───────────────────────────────────────────────
window.toggleAudio=function(){
    const isOn=AudioEngine.toggleAudio();
    const btn=document.getElementById('audio-toggle-btn');if(!btn)return;
    btn.classList.toggle('muted',!isOn);btn.title=isOn?'Mute Audio':'Unmute Audio';
    const s=btn.querySelector('.icon-sound'),m=btn.querySelector('.icon-mute');
    if(s)s.style.display=isOn?'':'none';if(m)m.style.display=!isOn?'':'none';
};

// ── BOOT ───────────────────────────────────────────────────────
let audioStarted=false;
function startAudio(){if(audioStarted)return;audioStarted=true;AudioEngine.init();}
['keydown','click','touchstart'].forEach(ev=>document.addEventListener(ev,startAudio,{once:true}));

if(canvas&&ctx){updateHUD();lastMoveTime=performance.now();requestAnimationFrame(gameLoop);}
else console.warn('Tetris game not started — canvas unavailable.');
