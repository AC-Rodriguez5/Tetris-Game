<?php
// Buffer everything so stray PHP warnings/notices don't corrupt JSON output.
ob_start();
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

include '../dbConnect/dbconnect.php';

function jsonOut($payload) {
    if (ob_get_length()) ob_clean();
    echo json_encode($payload);
}

function fail($msg, $http = null) {
    if ($http !== null) http_response_code($http);
    jsonOut(['error' => $msg]);
    exit;
}

function dispatchAction($db, $action, $username, $body) {
    switch ($action) {
        case 'create':      createRoom($db, $username); break;
        case 'find':        quickFind($db, $username); break;
        case 'join':        joinRoom($db, $username, $body['code'] ?? ''); break;
        case 'poll':        pollRoom($db, $username, $body['code'] ?? ''); break;
        case 'ready':       setReady($db, $username, $body['code'] ?? ''); break;
        case 'sync':        syncRoom($db, $username, $body); break;
        case 'end':         endGame($db, $username, $body); break;
        case 'leave':       leaveRoom($db, $username, $body['code'] ?? ''); break;
        case 'rematch':     rematchRoom($db, $username, $body['code'] ?? ''); break;
        case 'leaderboard': getLeaderboard($db); break;
        default:            fail('Unknown action: ' . htmlspecialchars($action));
    }
}

function isTransientDbError(Throwable $e) {
    return isTransientDbMessage($e->getMessage());
}

function isTransientDbMessage($message) {
    $message = strtolower((string)$message);
    foreach ([
        'server closed the connection unexpectedly',
        'connection refused',
        'connection timed out',
        'could not connect to server',
        'terminating connection',
        'ssl connection has been closed',
    ] as $needle) {
        if (strpos($message, $needle) !== false) {
            return true;
        }
    }
    return false;
}

try {

    if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
        fail('Unauthorized. Please log in again.', 401);
    }
    if (empty($_SESSION['username'])) {
        fail('Session is missing a username. Please log in again.', 401);
    }

    $username = $_SESSION['username'];
    $action = $_GET['action'] ?? '';
    $raw    = file_get_contents('php://input');
    $body   = $raw ? (json_decode($raw, true) ?? []) : [];

    $dbcon = new dbcon();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $db = $dbcon->dbconnect();
        if (!$db) {
            if ($attempt === 0 && isTransientDbMessage($dbcon->lastError)) {
                continue;
            }
            fail('Database unavailable: ' . ($dbcon->lastError ?: 'unknown error'));
        }

        try {
            dispatchAction($db, $action, $username, $body);
            break;
        } catch (PDOException $e) {
            if ($attempt === 0 && isTransientDbError($e)) {
                error_log('[blitz_api retry] ' . $e->getMessage());
                continue;
            }
            throw $e;
        }
    }

} catch (Throwable $e) {
    error_log('[blitz_api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Server error: ' . $e->getMessage());
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeCode() {
    // 6 chars from 24-letter set, no ambiguous I/O = ~191M combos
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $len   = strlen($chars);
    $code  = '';
    for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, $len - 1)];
    return $code;
}

function sanitizeCode($code) {
    $code = strtoupper(trim((string)$code));
    return preg_replace('/[^A-Z0-9]/', '', $code);
}

function cleanStale($db) {
    // ~10% of writes trigger cleanup; older than 15 minutes is stale
    try {
        if (random_int(1, 10) === 1) {
            $db->exec("DELETE FROM blitz_rooms WHERE created_at < NOW() - INTERVAL '15 minutes'");
        }
    } catch (Throwable $e) {
        error_log('[blitz_api cleanStale] ' . $e->getMessage());
    }
}

function fetchRoom($db, $code) {
    $s = $db->prepare("SELECT * FROM blitz_rooms WHERE room_code = ?");
    $s->execute([$code]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

function roomToResponse($room, $player) {
    $o = 3 - $player;
    $oppBoardRaw = $room["p{$o}_board"] ?? '[]';
    $oppBoard    = json_decode($oppBoardRaw ?: '[]', true);
    if (!is_array($oppBoard)) $oppBoard = [];
    return [
        'status'      => $room['status'],
        'winner'      => $room['winner'],
        'p1_username' => $room['p1_username'],
        'p2_username' => $room['p2_username'],
        'p1_ready'    => (int)$room['p1_ready'],
        'p2_ready'    => (int)$room['p2_ready'],
        'my_player'   => $player,
        'opp_name'    => $room["p{$o}_username"] ?? null,
        'opp_board'   => $oppBoard,
        'opp_score'   => (int)($room["p{$o}_score"] ?? 0),
        'opp_alive'   => (int)($room["p{$o}_alive"] ?? 1),
        'opp_garbage' => (int)($room["p{$o}_garbage"] ?? 0),
    ];
}

function scoreWinnerFromRoom($room) {
    $p1Score = (int)($room['p1_score'] ?? 0);
    $p2Score = (int)($room['p2_score'] ?? 0);
    if ($p1Score > $p2Score) return $room['p1_username'];
    if ($p2Score > $p1Score) return $room['p2_username'];
    return null;
}

// ─── Actions ─────────────────────────────────────────────────────────────────

function createRoom($db, $username) {
    cleanStale($db);
    // Clear any orphan waiting rooms by this user (refresh / abandoned session)
    $db->prepare("DELETE FROM blitz_rooms
                  WHERE p1_username = ? AND p2_username IS NULL AND status = 'waiting'")
       ->execute([$username]);
    // Tiny retry loop in case of an astronomically unlikely PK collision
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $code = makeCode();
        try {
            $db->prepare("INSERT INTO blitz_rooms (room_code, p1_username) VALUES (?, ?)")
               ->execute([$code, $username]);
            jsonOut(['success' => true, 'code' => $code, 'player' => 1]);
            return;
        } catch (PDOException $e) {
            // 23505 = unique_violation
            if ($e->getCode() !== '23505') throw $e;
        }
    }
    fail('Could not allocate a room code. Try again.');
}

function quickFind($db, $username) {
    cleanStale($db);
    // Match against rooms whose host pinged in the last 60 seconds
    $s = $db->prepare("SELECT room_code FROM blitz_rooms
        WHERE status = 'waiting'
          AND p1_username <> ?
          AND p2_username IS NULL
          AND created_at > NOW() - INTERVAL '5 minutes'
          AND p1_updated > NOW() - INTERVAL '60 seconds'
        ORDER BY created_at ASC LIMIT 1");
    $s->execute([$username]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        joinRoom($db, $username, $row['room_code']);
    } else {
        createRoom($db, $username);
    }
}

function joinRoom($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No room code provided.'); }

    $room = fetchRoom($db, $code);
    if (!$room) { fail('Room not found. Check the code.'); }

    // Re-joining own room (e.g. page refresh) — host
    if ($room['p1_username'] === $username) {
        if (empty($room['p2_username'])) {
            fail('You are already hosting this room. Join from a different logged-in account.');
        }
        jsonOut(['success' => true, 'code' => $code, 'player' => 1,
                 'opp_name' => $room['p2_username']] + roomToResponse($room, 1));
        return;
    }
    // Re-joining as guest
    if ($room['p2_username'] === $username) {
        jsonOut(['success' => true, 'code' => $code, 'player' => 2,
                 'opp_name' => $room['p1_username']] + roomToResponse($room, 2));
        return;
    }
    if (!empty($room['p2_username'])) { fail('Room is full.'); }
    if (in_array($room['status'], ['playing', 'finished'], true)) {
        fail('Game already in progress.');
    }
    $up = $db->prepare("UPDATE blitz_rooms
                          SET p2_username = ?, status = 'ready', p2_updated = NOW()
                          WHERE room_code = ? AND p2_username IS NULL");
    $up->execute([$username, $code]);
    if ($up->rowCount() === 0) {
        // Someone else beat us to it
        fail('Room just filled up. Try another.');
    }
    jsonOut(['success' => true, 'code' => $code, 'player' => 2,
             'opp_name' => $room['p1_username']]);
}

function pollRoom($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No code'); }
    $room = fetchRoom($db, $code);
    if (!$room) { fail('Room no longer exists.'); }

    $player = ($room['p1_username'] === $username) ? 1 :
              (($room['p2_username'] === $username) ? 2 : 0);
    if ($player === 0) { fail('You are not in this room.'); }

    $col = "p{$player}_updated";
    $db->prepare("UPDATE blitz_rooms SET {$col} = NOW() WHERE room_code = ?")->execute([$code]);

    jsonOut(['success' => true] + roomToResponse($room, $player));
}

function setReady($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No code'); }
    $room = fetchRoom($db, $code);
    if (!$room) { fail('Room not found'); }

    $p = ($room['p1_username'] === $username) ? 1 :
         (($room['p2_username'] === $username) ? 2 : 0);
    if ($p === 0) { fail('You are not in this room.'); }

    $readyCol = "p{$p}_ready";
    $upCol    = "p{$p}_updated";
    $db->prepare("UPDATE blitz_rooms SET {$readyCol} = 1, {$upCol} = NOW() WHERE room_code = ?")
       ->execute([$code]);

    // Re-read for fresh state, then decide if both are ready
    $room = fetchRoom($db, $code);
    $both = (int)$room['p1_ready'] === 1 && (int)$room['p2_ready'] === 1
            && !empty($room['p2_username']);
    if ($both && $room['status'] !== 'playing') {
        $db->prepare("UPDATE blitz_rooms SET status = 'playing' WHERE room_code = ?")
           ->execute([$code]);
    }
    jsonOut(['success' => true, 'both_ready' => $both]);
}

function syncRoom($db, $username, $body) {
    $code = sanitizeCode($body['code'] ?? '');
    if ($code === '') { fail('No code'); }

    $board      = json_encode($body['board'] ?? []);
    $score      = intval($body['score'] ?? 0);
    $alive      = empty($body['alive']) ? 0 : 1;
    $newGarbage = max(0, intval($body['garbage'] ?? 0));

    $room = fetchRoom($db, $code);
    if (!$room) { fail('Room not found'); }

    $p = ($room['p1_username'] === $username) ? 1 :
         (($room['p2_username'] === $username) ? 2 : 0);
    if ($p === 0) { fail('You are not in this room.'); }
    $o = 3 - $p;

    $boardCol  = "p{$p}_board";
    $scoreCol  = "p{$p}_score";
    $aliveCol  = "p{$p}_alive";
    $garbCol   = "p{$p}_garbage";
    $upCol     = "p{$p}_updated";

    $db->prepare("UPDATE blitz_rooms
                    SET {$boardCol}=?, {$scoreCol}=?, {$aliveCol}=?,
                        {$garbCol}={$garbCol}+?, {$upCol}=NOW()
                    WHERE room_code=?")
       ->execute([$board, $score, $alive, $newGarbage, $code]);

    $r = fetchRoom($db, $code);
    if ($r && $r['status'] === 'playing'
        && (int)$r['p1_alive'] === 0 && (int)$r['p2_alive'] === 0) {
        $winner = scoreWinnerFromRoom($r);
        $db->prepare("UPDATE blitz_rooms SET status='finished', winner=? WHERE room_code=?")
           ->execute([$winner, $code]);
        $r = fetchRoom($db, $code);
    }

    $oppDisconnected = false;
    if ($r && $r['status'] === 'playing') {
        $oppUpdated = $r["p{$o}_updated"];
        if ($oppUpdated && (time() - strtotime($oppUpdated)) > 12) {
            $oppDisconnected = true;
        }
    }

    $oppBoardRaw = $r["p{$o}_board"] ?? '[]';
    $oppBoard    = json_decode($oppBoardRaw ?: '[]', true);
    if (!is_array($oppBoard)) $oppBoard = [];

    jsonOut([
        'success'          => true,
        'status'           => $r['status'],
        'winner'           => $r['winner'],
        'opp_name'         => $r["p{$o}_username"],
        'opp_board'        => $oppBoard,
        'opp_score'        => (int)$r["p{$o}_score"],
        'opp_alive'        => (int)$r["p{$o}_alive"],
        'opp_garbage'      => (int)$r["p{$o}_garbage"],
        'opp_disconnected' => $oppDisconnected,
    ]);
}

function endGame($db, $username, $body) {
    $code   = sanitizeCode($body['code'] ?? '');
    $score  = intval($body['score'] ?? 0);
    $reason = $body['reason'] ?? 'time_up';
    if ($code === '') { jsonOut(['success' => true]); return; }

    $room = fetchRoom($db, $code);
    if (!$room) { jsonOut(['success' => true]); return; }

    $p = ($room['p1_username'] === $username) ? 1 :
         (($room['p2_username'] === $username) ? 2 : 0);
    if ($p === 0) { jsonOut(['success' => true]); return; }
    $o   = 3 - $p;

    $scoreCol = "p{$p}_score";
    $aliveCol = "p{$p}_alive";
    if (in_array($reason, ['topped_out', 'both_topped'], true)) {
        $db->prepare("UPDATE blitz_rooms SET {$scoreCol}=?, {$aliveCol}=0 WHERE room_code=?")
           ->execute([$score, $code]);
    } else {
        $db->prepare("UPDATE blitz_rooms SET {$scoreCol}=? WHERE room_code=?")
           ->execute([$score, $code]);
    }

    $room = fetchRoom($db, $code);
    $opp = $room["p{$o}_username"];

    if ($room['status'] === 'finished') {
        $winner = $room['winner'];
    } elseif ($reason === 'disconnected') {
        $winner = $username;
    } elseif ($reason === 'topped_out') {
        $winner = $opp ?: null;
    } else {
        $winner = scoreWinnerFromRoom($room);
    }

    if ($room['status'] !== 'finished') {
        $db->prepare("UPDATE blitz_rooms SET status='finished', winner=? WHERE room_code=?")
           ->execute([$winner, $code]);
    }

    // Record to blitz leaderboard only when a real opponent was present
    if ($opp) {
        $win  = ($winner === $username) ? 1 : 0;
        $loss = ($winner !== null && $winner !== $username) ? 1 : 0;
        $db->prepare("INSERT INTO blitz_leaderboard (username, wins, losses, best_score, total_games)
            VALUES (?, ?, ?, ?, 1)
            ON CONFLICT (username) DO UPDATE SET
                wins        = blitz_leaderboard.wins + EXCLUDED.wins,
                losses      = blitz_leaderboard.losses + EXCLUDED.losses,
                best_score  = GREATEST(blitz_leaderboard.best_score, EXCLUDED.best_score),
                total_games = blitz_leaderboard.total_games + 1,
                updated_at  = NOW()")
           ->execute([$username, $win, $loss, $score]);
    }

    jsonOut(['success' => true, 'winner' => $winner]);
}

function leaveRoom($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { jsonOut(['success' => true]); return; }
    $room = fetchRoom($db, $code);
    if (!$room) { jsonOut(['success' => true]); return; }

    // If host leaves while still waiting, delete the room
    if ($room['p1_username'] === $username && empty($room['p2_username'])
        && $room['status'] === 'waiting') {
        $db->prepare("DELETE FROM blitz_rooms WHERE room_code = ?")->execute([$code]);
    }
    // If guest leaves before game starts, kick them out of the slot
    elseif ($room['p2_username'] === $username && $room['status'] === 'ready') {
        $db->prepare("UPDATE blitz_rooms
                        SET p2_username=NULL, p2_ready=0, status='waiting'
                        WHERE room_code=?")->execute([$code]);
    }
    jsonOut(['success' => true]);
}

function rematchRoom($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No code'); }
    $old = fetchRoom($db, $code);
    if (!$old) { fail('Old room not found'); }

    $p = ($old['p1_username'] === $username) ? 1 :
         (($old['p2_username'] === $username) ? 2 : 0);
    if (!$p) { fail('Not in this room'); }
    $o = 3 - $p;
    $oppUsername = $old["p{$o}_username"];

    // Another player already created a rematch room — join it
    if (!empty($old['rematch_code'])) {
        $newCode = $old['rematch_code'];
        $newRoom = fetchRoom($db, $newCode);
        if ($newRoom && empty($newRoom['p2_username'])) {
            $db->prepare("UPDATE blitz_rooms SET p2_username=?, status='ready', p2_updated=NOW()
                          WHERE room_code=? AND p2_username IS NULL")
               ->execute([$username, $newCode]);
        }
        $host = $newRoom ? $newRoom['p1_username'] : ($oppUsername ?? '');
        jsonOut(['success' => true, 'rematch_code' => $newCode, 'is_host' => false, 'opp_name' => $host]);
        return;
    }

    // Create new rematch room
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $newCode = makeCode();
        try {
            $db->prepare("INSERT INTO blitz_rooms (room_code, p1_username) VALUES (?,?)")
               ->execute([$newCode, $username]);
            $upOld = $db->prepare("UPDATE blitz_rooms SET rematch_code=? WHERE room_code=? AND rematch_code IS NULL");
            $upOld->execute([$newCode, $code]);
            if ($upOld->rowCount() === 0) {
                // Race: other player set it first — delete our room and join theirs
                $db->prepare("DELETE FROM blitz_rooms WHERE room_code=?")->execute([$newCode]);
                $fresh = fetchRoom($db, $code);
                $theirCode = $fresh['rematch_code'] ?? null;
                if ($theirCode) {
                    $theirRoom = fetchRoom($db, $theirCode);
                    if ($theirRoom && empty($theirRoom['p2_username'])) {
                        $db->prepare("UPDATE blitz_rooms SET p2_username=?, status='ready', p2_updated=NOW()
                                      WHERE room_code=? AND p2_username IS NULL")
                           ->execute([$username, $theirCode]);
                    }
                    $host = $theirRoom ? $theirRoom['p1_username'] : ($oppUsername ?? '');
                    jsonOut(['success'=>true,'rematch_code'=>$theirCode,'is_host'=>false,'opp_name'=>$host]);
                    return;
                }
                fail('Could not create rematch room. Try again.');
            }
            jsonOut(['success'=>true,'rematch_code'=>$newCode,'is_host'=>true,'opp_name'=>$oppUsername]);
            return;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23505') throw $e;
        }
    }
    fail('Could not allocate a rematch room code. Try again.');
}

function getLeaderboard($db) {
    $s = $db->query("SELECT username, wins, losses, best_score, total_games
                     FROM blitz_leaderboard
                     ORDER BY wins DESC, best_score DESC
                     LIMIT 15");
    jsonOut(['success' => true, 'leaderboard' => $s->fetchAll(PDO::FETCH_ASSOC)]);
}
