//define canvas and context for drawing the game
const canvas = document.getElementById('tetrisCanvas');
if (!canvas) {
    console.warn('Tetris canvas not found; game script will not run.');
}
const ctx = canvas ? canvas.getContext('2d') : null;
// Set canvas dimensions based on Tetris grid size and block size
const ROWS = 15;  // Adjusted to fit screen height
const COLS = 10;
const BLOCK_SIZE = 50;  // Increased from 30 for bigger blocks
if (canvas && ctx) {
    canvas.width = COLS * BLOCK_SIZE;  // 500px wide
    canvas.height = ROWS * BLOCK_SIZE; // 750px tall
}

// Define Tetris shapes and colors
const TETROMINOES = [
    { color: 'cyan', shape:[[1, 1, 1, 1]] },
    { color: 'blue', shape:[[1, 1], [1, 1]] },
    { color: 'orange', shape:[[1, 1, 1],[1, 0, 0]] },
    { color: 'yellow', shape:[[1, 1, 1],[0, 0, 1]] },
    { color: 'green', shape:[[1, 1, 0], [0, 1, 1]] },
    { color: 'red', shape:[[0, 1, 1], [1, 1, 0]] },
    { color: 'purple', shape:[[0, 1, 0], [1, 1, 1]] },
]

// Game state variables 
let board = Array.from({ length: ROWS }, () => Array(COLS).fill(null)); 
let currentTetromino = getRandomTeromino();
let currentPosition = { x: 3, y: 0 }; // Start near the top center
let score = 0;
let intervarl = 400;  // Starting interval
let gameOver = false;
let speedIncreaseInterval = 5000;  // Increase speed every 5 seconds (was 10000)
let lastSpeedIncreaseTime = Date.now();
let lastMoveTime = 0;  // For smooth animation tracking
let scoreSent = false;// Flag to ensure score is sent only once


function saveHighScore() {
    // Send the final score to the server using a POST request and return the Promise.
    return fetch("../dashboard/dashboard.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({ score: score })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log("Score saved successfully. New high score:", data.score);
            return data.score;
        }
        console.error("Failed to save score");
        return null;
    })
    .catch(error => {
        console.error("Error saving score:", error);
        return null;
    });
}

function quitGame() {
    if (score > 0) {
        saveHighScore().finally(() => {
            window.location.href = 'dashboard.php';
        });
        return;
    }
    window.location.href = 'dashboard.php';
}

function getRandomTeromino() {
    // Randomly select a tetromino from the predefined list and return it to be used as the current piece in the game.
    return TETROMINOES[Math.floor(Math.random() * TETROMINOES.length)];
}

function drawBlock(x, y, color) {
    // Draw a single block at the specified grid coordinates with the given color and a white border for better visibility.
    ctx.fillStyle = color;
    ctx.fillRect(x * BLOCK_SIZE, y * BLOCK_SIZE, BLOCK_SIZE, BLOCK_SIZE);
    ctx.strokeStyle = 'white';
    ctx.strokeRect(x * BLOCK_SIZE, y * BLOCK_SIZE, BLOCK_SIZE, BLOCK_SIZE);
}

function drawBoard() {
    // Clear the entire canvas and redraw the current state of the board and the active tetromino.
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    for (let y = 0; y < ROWS; y++) {
        for (let x = 0; x < COLS; x++) {
            if (board[y][x]) {
                drawBlock(x, y, board[y][x]);
            }
        }
    }

}

function drawTetromino() {
// Draw the current active tetromino on the canvas based on its shape and color, using the current 
// position to determine where it should be rendered.
    const shape = currentTetromino.shape;
    const color = currentTetromino.color;
    for(let y = 0; y < shape.length; y++) {
        for(let x = 0; x < shape[y].length; x++) {
            if(shape[y][x]) {
                drawBlock(currentPosition.x + x, currentPosition.y + y, color);
            }
        }
    }
}


function hasCollision(offsetX , offsetY ) {
    // Check if the current tetromino, when moved by the specified offsets, would collide with the walls of the board or 
    // with already placed blocks, returning true if a collision is detected and false otherwise.
    const shape = currentTetromino.shape;
    for(let y = 0; y < shape.length; y++) {
        for(let x = 0; x < shape[y].length; x++) {
            if(shape[y][x] && (currentPosition.x + x + offsetX < 0 || 
                currentPosition.x + x + offsetX >= COLS || 
                currentPosition.y + y + offsetY >= ROWS || 
                board[currentPosition.y + y + offsetY]
                [currentPosition.x + x + offsetX])) {
                    return true;
                }
        }
    }
    return false;
}

function mergeTetromino() {
// When a tetromino can no longer move down, this function merges it into the board by updating the board array with 
// the color of the tetromino's blocks at their final position. 
 
    const shape = currentTetromino.shape;
    const color = currentTetromino.color;
    for(let y = 0; y < shape.length; y++) {
        for(let x = 0; x < shape[y].length; x++) {
            if(shape[y][x]) {
                board[currentPosition.y + y][currentPosition.x + x] = color;
            }
        }
    }
}

function removeRow(){
// Check each row of the board from the bottom up to see if it is completely filled with blocks. If a full row is found,

    let linesRemoved = 0;
    for(let y = ROWS - 1; y >= 0; y--) {
        if(board[y].every(cell => cell)) {
            board.splice(y, 1);
            board.unshift(Array(COLS).fill(null));
            linesRemoved++;
            y++; 
        }
    }
    score += linesRemoved * 100;
    const scoreElement = document.getElementById('score');
    if (scoreElement) {
        scoreElement.textContent = `Score: ${score}`;
    }
}

function rotateTetromino() {
// Rotate the current tetromino 90 degrees clockwise by transposing its shape matrix and then reversing the order of the rows.
// After rotating, it checks for collisions at the new orientation. If a collision is detected, it reverts the tetromino back to its original shape.
    const shape = currentTetromino.shape;
    const newShape = shape[0].map((_, i) => shape.map(row => row[i]).reverse());
    const originalShape = currentTetromino.shape;
    currentTetromino.shape = newShape;
    if (hasCollision(0, 0)) {
        currentTetromino.shape = originalShape;
    }
}

function moveDown(){
// Attempt to move the current tetromino down by one row. If there is no collision, it updates the position. 
// If a collision occurs, it merges the tetromino into the board, checks for completed rows, and spawns a new tetromino at the top. 
// If the new tetromino immediately collides, it sets the game over state and alerts the player with their final score.

    if(!hasCollision(0, 1)) {
        currentPosition.y++;
    } else {
        mergeTetromino();
        removeRow();
        currentTetromino = getRandomTeromino();
        currentPosition = { x: 3, y: 0 };
        if(hasCollision(0, 0)) {
            gameOver = true;
            alert('Game Over! Your score: ' + score);
        }
    }

}

function move(offsetX) {
    // Attempt to move the current tetromino left or right based on the offset. If there is no collision in the desired direction, 
    // it updates the position accordingly.
    if(!hasCollision(offsetX, 0)) {
        currentPosition.x += offsetX;
    }
}

function incrementSpeed() {
    // Gradually increase the speed of the game by decreasing the interval at which the tetrominoes fall. 
    // This function checks if a certain amount of time has passed since the last speed increase, and if so, 
    // it reduces the interval (down to a minimum threshold) and updates the last speed increase time.
    const now = Date.now();
    if (now - lastSpeedIncreaseTime >= speedIncreaseInterval) {
        intervarl = Math.max(500, intervarl - 20);
        lastSpeedIncreaseTime = now;
    }
}

function gameLoop(currentTime = performance.now()) {
    // The main game loop that continuously updates the game state and renders the board and current tetromino. It checks for game over conditions,
    // handles the timing for moving the tetromino down, and calls itself using requestAnimationFrame for smooth animation. If the game is over,
    //  it simply returns without updating.

    if (!canvas || !ctx) return;
    if (gameOver) {
        if (!scoreSent) {
            saveHighScore();
            scoreSent = true;
        }
        return;
    }
    
    // Only move down at the specified interval for smooth animation
    if (currentTime - lastMoveTime >= intervarl) {
        moveDown();
        lastMoveTime = currentTime;
    }
    
    drawBoard();
    drawTetromino();
    incrementSpeed();
    // Use requestAnimationFrame for 60fps smooth animation
    requestAnimationFrame(gameLoop);
}

document.addEventListener('keydown', (e) => {
    // Listen for arrow key presses to move or rotate the current tetromino. If the game is over, it ignores input. Otherwise, 
    // it checks which key was pressed and calls the appropriate function to move left, right, down, or rotate the piece.
    if (gameOver) return;
    switch(e.key) {
        case 'ArrowLeft':
            move(-1);
            break;
        case 'ArrowRight':
            move(1);
            break;
        case 'ArrowDown':
            moveDown();
            break;
        case 'ArrowUp':
            rotateTetromino();
            break;
    }
});


function restartGame() {
    // Reload the current page to restart this mock game view.
    board = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
    currentTetromino = getRandomTeromino();
    currentPosition = { x: 3, y: 0 };
    score = 0;
    intervarl = 400;  // Reset to starting speed
    gameOver = false;
    scoreSent= false;

    const scoreElement = document.getElementById('score');
    if (scoreElement) {
        scoreElement.textContent = `Score: ${score}`;
    }
    lastMoveTime = performance.now();
    lastSpeedIncreaseTime = Date.now();
    gameLoop();
}

if (canvas && ctx) {
    lastMoveTime = performance.now();
    requestAnimationFrame(gameLoop);
} else {
    console.warn('Tetris game not started because canvas or context is unavailable.');
}
              