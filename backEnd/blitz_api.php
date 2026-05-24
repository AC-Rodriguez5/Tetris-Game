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
        case 'create':          createRoom($db, $username); break;
        case 'find':            quickFind($db, $username); break;
        case 'join':            joinRoom($db, $username, $body['code'] ?? ''); break;
        case 'poll':            pollRoom($db, $username, $body['code'] ?? ''); break;
        case 'ready':           setReady($db, $username, $body['code'] ?? ''); break;
        case 'sync':            syncRoom($db, $username, $body); break;
        case 'end':             endGame($db, $username, $body); break;
        case 'leave':           leaveRoom($db, $username, $body['code'] ?? ''); break;
        case 'rematch':         // back-compat alias for rematch_request
        case 'rematch_request': rematchRequest($db, $username, $body['code'] ?? ''); break;
        case 'rematch_accept':  rematchAccept($db, $username, $body['code'] ?? ''); break;
        case 'rematch_decline': rematchDecline($db, $username, $body['code'] ?? ''); break;
        case 'leaderboard':     getLeaderboard($db); break;
        default:                fail('Unknown action: ' . htmlspecialchars($action));
    }
}

// Max number of rematches allowed between the same pair (after the original).
const REMATCH_LIMIT = 2;
// Seconds before a pending rematch invite auto-expires to "declined".
const REMATCH_INVITE_TTL = 15;

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

    // Release the session file lock immediately. PHP's default file-based
    // session handler holds an exclusive flock for the duration of the
    // request, which serializes all in-flight blitz_api calls from the
    // same user (browser cookies → same SID → same file). At 10 syncs/sec
    // this is the throughput ceiling. Nothing below mutates $_SESSION.
    session_write_close();

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

    // Rematch invite state, with TTL-based auto-expiry surfaced to the client.
    // The new room (rematch_code) is allocated at request time, so don't treat
    // its presence as "already accepted" — only the timestamp and decline flag
    // matter here. Acceptance is signaled by the new room having p2 (computed
    // in pollRoom).
    $requestedBy = $room['rematch_requested_by'] ?? null;
    $requestedAt = $room['rematch_requested_at'] ?? null;
    $declined    = (int)($room['rematch_declined'] ?? 0);
    $expired     = false;
    if ($requestedBy && $requestedAt && $declined === 0) {
        $age = time() - strtotime($requestedAt);
        if ($age >= REMATCH_INVITE_TTL) {
            $expired = true;
        }
    }

    return [
        'status'                => $room['status'],
        'winner'                => $room['winner'],
        'p1_username'           => $room['p1_username'],
        'p2_username'           => $room['p2_username'],
        'p1_ready'              => (int)$room['p1_ready'],
        'p2_ready'              => (int)$room['p2_ready'],
        'my_player'             => $player,
        'opp_name'              => $room["p{$o}_username"] ?? null,
        'opp_board'             => $oppBoard,
        'opp_score'             => (int)($room["p{$o}_score"] ?? 0),
        'opp_alive'             => (int)($room["p{$o}_alive"] ?? 1),
        'opp_garbage'           => (int)($room["p{$o}_garbage"] ?? 0),
        'rematch_code'          => $room['rematch_code'] ?? null,
        'rematch_count'         => (int)($room['rematch_count'] ?? 0),
        'rematch_limit'         => REMATCH_LIMIT,
        'rematch_requested_by'  => $requestedBy,
        'rematch_declined'      => ($declined === 1) ? 1 : 0,
        'rematch_expired'       => $expired,
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

    $resp = roomToResponse($room, $player);
    // For the rematch-invite flow, the requester needs to know when the
    // other player accepted — that's signaled by the *new* room having p2.
    if (!empty($room['rematch_code'])) {
        $new = fetchRoom($db, $room['rematch_code']);
        $resp['rematch_accepted'] = ($new && !empty($new['p2_username']));
    } else {
        $resp['rematch_accepted'] = false;
    }
    jsonOut(['success' => true] + $resp);
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

    jsonOut(['success' => true,
             'opp_disconnected' => $oppDisconnected]
            + roomToResponse($r, $p));
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

    $room     = fetchRoom($db, $code);
    $opp      = $room["p{$o}_username"];
    $meAlive  = (int)$room["p{$p}_alive"];
    $oppAlive = (int)$room["p{$o}_alive"];

    // Decide whether the *room* should be finalized now. A single topped_out
    // when the opponent is still alive must NOT end the room — the other
    // player gets to play out the full 2 minutes.
    $shouldFinalize = false;
    $winner = null;

    if ($room['status'] === 'finished') {
        $shouldFinalize = false;
        $winner = $room['winner'];
    } elseif ($reason === 'disconnected') {
        $shouldFinalize = true;
        $winner = $username;
    } elseif ($reason === 'topped_out') {
        // Only finalize if there's no real opponent, or the opponent is also dead.
        if (!$opp || $oppAlive === 0) {
            $shouldFinalize = true;
            if (!$opp) {
                $winner = null;
            } elseif ($meAlive === 0 && $oppAlive === 0) {
                $winner = scoreWinnerFromRoom($room);
            } else {
                // I topped out, opp survives — opp wins.
                $winner = $opp;
            }
        }
        // else: leave room playing; the surviving opponent will end via time_up.
    } else { // time_up (or anything else)
        $shouldFinalize = true;
        if (!$opp) {
            $winner = $username;
        } elseif ($meAlive === 1 && $oppAlive === 0) {
            $winner = $username;
        } elseif ($meAlive === 0 && $oppAlive === 1) {
            $winner = $opp;
        } else {
            $winner = scoreWinnerFromRoom($room);
        }
    }

    if ($shouldFinalize && $room['status'] !== 'finished') {
        $db->prepare("UPDATE blitz_rooms SET status='finished', winner=? WHERE room_code=?")
           ->execute([$winner, $code]);
    }

    // Record to blitz leaderboard only when the room is actually finalized
    // with a real opponent — otherwise a single topped_out would double-record.
    if ($shouldFinalize && $opp) {
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

    jsonOut([
        'success'    => true,
        'winner'     => $winner,
        'finalized'  => $shouldFinalize,
        'status'     => $shouldFinalize ? 'finished' : $room['status'],
        'opp_alive'  => $oppAlive,
        'me_alive'   => $meAlive,
    ]);
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

// Helper: load and re-check stale-invite expiry inside transactions.
function rematchInviteIsActive($room) {
    if (empty($room['rematch_requested_by'])) return false;
    if ((int)($room['rematch_declined'] ?? 0) === 1) return false;
    if (empty($room['rematch_requested_at'])) return true;
    $age = time() - strtotime($room['rematch_requested_at']);
    return $age < REMATCH_INVITE_TTL;
}

// Player A clicks "Rematch" first. We record the invite on the old room and
// allocate a new room — but A is NOT yet joined to anyone there; the opponent
// must accept first.
function rematchRequest($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No code'); }
    $old = fetchRoom($db, $code);
    if (!$old) { fail('Old room not found'); }

    $p = ($old['p1_username'] === $username) ? 1 :
         (($old['p2_username'] === $username) ? 2 : 0);
    if (!$p) { fail('Not in this room'); }
    $o = 3 - $p;
    $oppUsername = $old["p{$o}_username"];

    if ((int)($old['rematch_count'] ?? 0) >= REMATCH_LIMIT) {
        fail('Rematch limit reached for this pairing.');
    }

    // If opponent already requested and the invite hasn't expired/declined,
    // funnel this into an accept rather than re-requesting.
    if (rematchInviteIsActive($old) && $old['rematch_requested_by'] !== $username) {
        rematchAccept($db, $username, $code);
        return;
    }

    // Same user re-requesting: refresh the invite timestamp so the TTL window
    // resets, and clear any previous decline. The allocated new room is reused.
    if (!empty($old['rematch_code']) && $old['rematch_requested_by'] === $username) {
        $db->prepare("UPDATE blitz_rooms
                        SET rematch_requested_at = NOW(),
                            rematch_declined = 0
                      WHERE room_code = ?")
           ->execute([$code]);
        jsonOut(['success' => true,
                 'rematch_code' => $old['rematch_code'],
                 'is_host' => true,
                 'opp_name' => $oppUsername,
                 'rematch_count' => (int)($old['rematch_count'] ?? 0) + 1]);
        return;
    }

    // Otherwise: create new room and mark the invite.
    $newCount = (int)($old['rematch_count'] ?? 0) + 1;
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $newCode = makeCode();
        try {
            $db->prepare("INSERT INTO blitz_rooms (room_code, p1_username, rematch_count)
                          VALUES (?, ?, ?)")
               ->execute([$newCode, $username, $newCount]);

            $up = $db->prepare("UPDATE blitz_rooms
                                  SET rematch_code = ?,
                                      rematch_requested_by = ?,
                                      rematch_requested_at = NOW(),
                                      rematch_declined = 0
                                WHERE room_code = ?
                                  AND (rematch_code IS NULL OR rematch_code = ?)");
            $up->execute([$newCode, $username, $code, $newCode]);
            if ($up->rowCount() === 0) {
                // Someone else set rematch_code in the meantime — clean up.
                $db->prepare("DELETE FROM blitz_rooms WHERE room_code=?")->execute([$newCode]);
                $fresh = fetchRoom($db, $code);
                $theirCode = $fresh['rematch_code'] ?? null;
                if ($theirCode) {
                    jsonOut(['success' => true,
                             'rematch_code' => $theirCode,
                             'is_host' => false,
                             'opp_name' => $oppUsername,
                             'rematch_count' => (int)($fresh['rematch_count'] ?? 0)]);
                    return;
                }
                fail('Could not create rematch room. Try again.');
            }
            jsonOut(['success' => true,
                     'rematch_code' => $newCode,
                     'is_host' => true,
                     'opp_name' => $oppUsername,
                     'rematch_count' => $newCount]);
            return;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23505') throw $e;
        }
    }
    fail('Could not allocate a rematch room code. Try again.');
}

// Player B accepts A's pending rematch invite: join the new room as p2.
function rematchAccept($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No code'); }
    $old = fetchRoom($db, $code);
    if (!$old) { fail('Old room not found'); }

    $p = ($old['p1_username'] === $username) ? 1 :
         (($old['p2_username'] === $username) ? 2 : 0);
    if (!$p) { fail('Not in this room'); }
    $o = 3 - $p;
    $oppUsername = $old["p{$o}_username"];

    if ((int)($old['rematch_count'] ?? 0) >= REMATCH_LIMIT) {
        fail('Rematch limit reached for this pairing.');
    }

    $newCode = $old['rematch_code'] ?? null;
    if (!$newCode) { fail('No rematch invite to accept.'); }
    if (!rematchInviteIsActive($old)) { fail('Rematch invite expired.'); }
    if ($old['rematch_requested_by'] === $username) {
        // We were the requester — just return our pending code.
        jsonOut(['success' => true,
                 'rematch_code' => $newCode,
                 'is_host' => true,
                 'opp_name' => $oppUsername,
                 'rematch_count' => (int)($old['rematch_count'] ?? 0) + 1]);
        return;
    }

    $newRoom = fetchRoom($db, $newCode);
    if (!$newRoom) {
        // The new room got cleaned up (e.g., older decline). Force the
        // invite to look declined so the requester re-requests cleanly.
        $db->prepare("UPDATE blitz_rooms
                        SET rematch_declined = 1,
                            rematch_code = NULL,
                            rematch_requested_by = NULL,
                            rematch_requested_at = NULL
                      WHERE room_code = ?")
           ->execute([$code]);
        fail('Rematch invite is no longer valid. Ask for a new rematch.');
    }
    if (empty($newRoom['p2_username'])) {
        $db->prepare("UPDATE blitz_rooms
                        SET p2_username = ?, status = 'ready', p2_updated = NOW()
                        WHERE room_code = ? AND p2_username IS NULL")
           ->execute([$username, $newCode]);
    }
    jsonOut(['success' => true,
             'rematch_code' => $newCode,
             'is_host' => false,
             'opp_name' => $newRoom['p1_username'],
             'rematch_count' => (int)($old['rematch_count'] ?? 0) + 1]);
}

// Player B declines A's invite: mark old room declined and clean up the orphan
// new room (only if no one else has joined it yet).
function rematchDecline($db, $username, $code) {
    $code = sanitizeCode($code);
    if ($code === '') { fail('No code'); }
    $old = fetchRoom($db, $code);
    if (!$old) { jsonOut(['success' => true]); return; }

    $p = ($old['p1_username'] === $username) ? 1 :
         (($old['p2_username'] === $username) ? 2 : 0);
    if (!$p) { jsonOut(['success' => true]); return; }

    if (empty($old['rematch_requested_by']) || $old['rematch_requested_by'] === $username) {
        // Nothing to decline, or I was the requester.
        jsonOut(['success' => true]);
        return;
    }

    $newCode = $old['rematch_code'] ?? null;
    // Mark declined AND clear the invite pointers so a future re-request
    // starts fresh (instead of reusing a stale rematch_code).
    $db->prepare("UPDATE blitz_rooms
                    SET rematch_declined = 1,
                        rematch_code = NULL,
                        rematch_requested_by = NULL,
                        rematch_requested_at = NULL
                  WHERE room_code = ?")
       ->execute([$code]);

    if ($newCode) {
        // Remove the orphan new room created for this invite, but only if
        // nobody else joined it yet.
        $db->prepare("DELETE FROM blitz_rooms
                       WHERE room_code = ?
                         AND p2_username IS NULL
                         AND status IN ('waiting','ready')")
           ->execute([$newCode]);
    }
    jsonOut(['success' => true]);
}

function getLeaderboard($db) {
    $s = $db->query("SELECT username, wins, losses, best_score, total_games
                     FROM blitz_leaderboard
                     ORDER BY wins DESC, best_score DESC
                     LIMIT 15");
    jsonOut(['success' => true, 'leaderboard' => $s->fetchAll(PDO::FETCH_ASSOC)]);
}
