# PRD: Multiplayer Battle (Blitz Mode) Logical Error Debugging

## 1. Project Name
Multiplayer Blitz Battle Bug Fix and Stabilization

## 2. Purpose
This PRD defines the requirements for auditing, identifying, and fixing logical errors specifically inside the multiplayer Blitz battle flow of Cosmic Tetris.

The goal is to make every multiplayer interaction — matchmaking, ready-up, countdown, in-match sync, garbage attack, topout, time-up, disconnect, and rematch — behave correctly under both happy-path and edge-case timing, while preserving the existing project structure.

## 3. Problem Statement
The multiplayer Blitz mode involves two clients polling a shared PostgreSQL room over HTTP. Because the server is the source of truth and both clients act independently, the flow is prone to logical errors such as:

- Race conditions between clients (e.g. one player clicks Ready milliseconds before the other).
- State leakage between the two players (one client reads a stale snapshot and overwrites a fresh field).
- Misordered finalization (room marked finished before both scores are recorded).
- Double-recording of leaderboard stats / high scores.
- Garbage-line attacks lost, doubled, or applied in the wrong order.
- Rematch handshake stuck in "Waiting" when both players already agreed.
- Spectator UI freezing (timer, opponent board) after a topout.
- Disconnect detection misfiring on normal high-latency syncs.
- Topout vs time-up tie-break producing the wrong winner.

These are logical bugs — the code runs and returns JSON, but the resulting game state is wrong.

## 4. Objectives
- Understand the intended behavior of every multiplayer action (`create`, `find`, `join`, `poll`, `ready`, `sync`, `end`, `leave`, `rematch_request`, `rematch_accept`, `rematch_decline`).
- Trace each action through the two-client + server triangle, including the polling cadence.
- Identify race conditions, off-by-one timing, missing guards, and state-transition errors.
- Apply the smallest safe fix at the correct layer (client JS vs PHP API vs SQL).
- Preserve the existing file structure under `script/multiplayer.js`, `backEnd/blitz_api.php`, and `dashboard/blitz_room/*`.

## 5. Scope
### Included
- `script/multiplayer.js` — client-side game loop, polling, rematch, garbage, end-game.
- `backEnd/blitz_api.php` — all action handlers + helpers (`fetchRoom`, `roomToResponse`, `scoreWinnerFromRoom`, `cleanStale`, etc.).
- `dashboard/blitz_room.php` and `dashboard/blitz_room/*` phase partials + `boot_script.php` + `bootstrap.php`.
- `dashboard/blitz_join.php` (room-code entry path).
- `dashboard/blitz_leaderboard.php` (post-match stats display).
- `blitz_tables.sql` (only if a schema constraint is the root cause of a logical bug).

### Excluded
- Solo Tetris (`script/script.js`, `dashboard/game.php`) — covered by the general logical-error PRD.
- Visual/CSS polish that isn't a logical bug.
- New features beyond bug fixes (e.g. spectator chat, ranked ladder).

## 6. Inputs Required
For each reported issue, the developer must provide:

1. The action being performed (e.g. "Player B clicked Ready while Player A's pollReady was mid-flight").
2. Expected behavior (e.g. "Both clients show countdown within 500 ms").
3. Actual behavior (e.g. "Player A's screen sat on 'Waiting for opponent' for ~3 s").
4. Any browser console / PHP error log output.
5. Approximate network conditions (LAN, WAN, throttled).
6. Steps to reproduce (which client clicked which button in which order).

## 7. Expected Behavior
After fixes:

- **Matchmaking**: Quick Match pairs two waiting players within one poll cycle. Create and Join produce the same ready phase.
- **Ready phase**: When both `p1_ready` and `p2_ready` are 1, both clients enter the countdown within one poll interval. The 20-second ready timer expires identically on both clients.
- **Countdown**: 5 → 4 → 3 → 2 → 1 → GO! on both clients, off by no more than the network round-trip.
- **In-match sync**: Each client sees the opponent's board, score, and active piece with latency dominated by network round-trip, not by polling cadence.
- **Garbage**: Lines cleared above the GARBAGE_TABLE threshold deliver garbage to the opponent. No garbage is double-applied; none is silently dropped.
- **Topout**: The topped-out player enters spectator mode. Their own canvas freezes; the rival canvas and timer continue. They cannot affect the room state. The surviving player keeps playing for the full timer.
- **Time-up**: When the timer reaches 0:00 for the surviving player, the room finalizes. The winner is the player who is still alive; if both are alive, score breaks the tie; if equal, the room reports a draw.
- **Disconnect**: After ~12 seconds of no `p{N}_updated` heartbeat from one side, the other side is told `opp_disconnected: true` and the room finalizes with the still-connected player as winner. Brief network blips (<12 s) do NOT finalize the room.
- **Score and stats**: A final score is POSTed to `dashboard.php` exactly once per match per client. `blitz_leaderboard` is updated exactly once per finalized room per player. Best score takes max, not last.
- **Rematch**: If both players click Rematch, both proceed to the new room automatically (no Accept popup needed). If only one requests, the other gets an invite with a TTL; auto-decline on timeout. Decline cleans up the orphan new room. The pairing's rematch_count never exceeds REMATCH_LIMIT.

## 8. Debugging Process
The debugging process must follow this sequence for every reported bug:

1. **Read the action handler** in `blitz_api.php` end-to-end, including the SQL it runs.
2. **Read the client caller** in `multiplayer.js` and identify the polling/event that triggers it.
3. **Build a timeline** of what each of the two clients and the server do, second by second, for the failure scenario.
4. **Identify the logical mismatch** — a missing guard, a wrong column, a stale read-before-write, a race window between two polls, a comparison that doesn't account for NULL, etc.
5. **Explain why the current logic fails** in plain English.
6. **Apply the minimal fix** at the correct layer:
   - Client-side flag/guard if the bug is a duplicate request or stale state in JS.
   - PHP transaction / conditional UPDATE if the bug is a write race.
   - SQL constraint or default only if a schema-level invariant is missing.
7. **Verify the fix** with a two-browser reproduction of the original failure scenario AND the happy path (to confirm no regression).
8. **Suggest improvements** (e.g. tighter poll intervals, idempotency keys) only if they directly reduce the bug class.

## 9. Functional Requirements

### FR-001: Audit every action handler
For each case in `dispatchAction` (`create`, `find`, `join`, `poll`, `ready`, `sync`, `end`, `leave`, `rematch_request`, `rematch_accept`, `rematch_decline`, `leaderboard`), confirm:
- Input is sanitized (`sanitizeCode`, integer casts, alive flag clamped to 0/1).
- Player slot is correctly resolved (`p1_username === $username` → 1, else 2, else fail).
- All writes use parameterized prepared statements.
- All conditional finalizations check `status !== 'finished'` first to avoid overwriting a settled room.

### FR-002: Identify logical errors in multiplayer
Specifically search for:
- Race between `pollReady` and `sendReady` that leaves one client stuck before countdown.
- `syncRoom` being skipped indefinitely due to `syncInFlight` never being cleared on certain error paths.
- Garbage queue applied without cancellation logic (if the design called for cancellation) OR cancelled incorrectly.
- `endGame` paths that don't guard against `gameOver === true` re-entry.
- `finishLocal`, `finishByReason`, and `reportEnd` saving the score multiple times.
- `clearLines` off-by-one when an entire stack of lines is cleared in one lock.
- Timer drift between clients (each runs its own `BLITZ_SECS` countdown; if they desync, results can diverge).
- Spectator UI elements (timer, rival board) freezing after topout instead of continuing to update.
- Rematch `is_host` flag not honored by the client (request that was auto-routed to accept must navigate).
- Decline cleanup leaving the new room around with `p1_username` set but no `p2`.
- Leaderboard double-insert when `endGame` is called twice (e.g. topout then time-up on the same client).
- `rematch_requested_at` TTL evaluation using server time vs client time inconsistently.
- `joinRoom` allowing the same authenticated user into both slots if they open two tabs.

### FR-003: Explain the cause
Each fix must include a one-paragraph explanation that names:
- Which client/server action triggered the bug.
- Which line(s) of which file contained the wrong logic.
- Why the wrong logic produced the wrong state.

### FR-004: Provide a minimal fix
Patches must:
- Touch only the code paths involved in the bug.
- Not rename, restructure, or reformat unrelated code.
- Not introduce new global state unless strictly required.
- Keep the JSON shape of every API response backward-compatible.

### FR-005: Preserve existing structure
- Do NOT split `blitz_api.php` into multiple files.
- Do NOT replace polling with WebSockets (out of scope).
- Do NOT change DB column names or SQL types.
- Do NOT add new dependencies (npm/composer).

### FR-006: Provide verification steps
Every fix must ship with a two-browser reproduction recipe, including:
- The exact button-click order on each client.
- What each client should display at each step.
- What to inspect in DevTools Network and the PHP error log to confirm the bug is gone.

## 10. Non-Functional Requirements
- Code must remain readable and follow the existing style (PSR-12-ish PHP, plain ES2017 JS).
- Polling cadence changes must stay within reason (≥ 80 ms intervals; the Supabase pooler is not infinite).
- No `setTimeout` chains used as substitutes for proper state machines.
- All fixes must work on both LAN and a 200 ms-latency WAN connection.

## 11. Acceptance Criteria
A bug is considered fixed when:

- The original failure scenario, reproduced in two browsers, no longer occurs.
- The happy-path scenario still works end-to-end.
- The PHP error log shows no new warnings or notices during the scenario.
- The browser console shows no new errors.
- `blitz_leaderboard` and `"TetrisGame"` reflect exactly one record/update per match per player.
- No HTTP 4xx/5xx responses appear in the Network tab during the scenario.

## 12. Claude Code Prompt

Use this prompt in Claude Code (or another agent) to audit and fix every multiplayer Blitz logical bug in one pass:

```text
Act as a senior multiplayer game backend engineer. The codebase is a PHP +
plain-JS Tetris game with a 2-player Blitz mode. The two clients talk to
backEnd/blitz_api.php over HTTP polling against a PostgreSQL room row.

I want you to audit and fix EVERY logical bug in the multiplayer Blitz
battle flow. Follow this process strictly:

1. Read these files end-to-end before changing anything:
   - script/multiplayer.js
   - backEnd/blitz_api.php
   - dashboard/blitz_room.php
   - dashboard/blitz_room/bootstrap.php
   - dashboard/blitz_room/boot_script.php
   - dashboard/blitz_room/phase_*.php
   - dashboard/blitz_join.php
   - dashboard/blitz_leaderboard.php
   - blitz_tables.sql

2. Build a written timeline for each of these scenarios, naming the file:line
   of every read and write that participates in it:
   a. Quick Match: two players hit "Quick Match" within 200 ms of each other.
   b. Create + Join: one creates, one joins via the 6-letter code.
   c. Ready race: both click Ready in the same poll cycle.
   d. Mid-match sync: one player's syncRoom request takes 1.5 s.
   e. Garbage attack: player A clears 4 lines, B clears 2 lines simultaneously.
   f. Single topout: A tops out at 1:30; B keeps playing to time-up.
   g. Double topout: both top out within 200 ms.
   h. Time-up tie: both alive at 0:00 with equal scores.
   i. Disconnect: B closes the tab mid-match.
   j. Rematch both: both click Rematch within 200 ms of each other.
   k. Rematch one, accept: A clicks Rematch, B accepts via the invite popup.
   l. Rematch one, decline: A clicks Rematch, B clicks Decline.
   m. Rematch one, expire: A clicks Rematch, B does nothing for 15 s.
   n. Refresh: A refreshes the blitz_room.php tab mid-match.
   o. Two tabs: same authenticated user opens two tabs into the same room.

3. For every scenario above, identify the logical errors. Look explicitly for:
   - Race windows between two clients writing the same room column.
   - Missing guards on gameOver, resultShown, scoreSaved, rematchHandled.
   - Off-by-one in clearLines / garbage queue.
   - Wrong winner computation when scores tie or one player is dead.
   - Stale snapshots between fetchRoom() and the subsequent UPDATE.
   - JSON-shape drift between the action's response and the client's expectations.
   - Polling intervals that produce visible UI freeze for the user.
   - NULL handling in SQL comparisons (score, p2_updated, rematch_requested_at).
   - Cleanup paths that leave orphan rows in blitz_rooms.
   - Idempotency: any action that, if retried, would double-apply state.

4. For each bug found, output:
   - File:line of the bug.
   - One-paragraph plain-English cause.
   - Minimal patch (use Edit, not full rewrites).
   - Two-browser reproduction recipe to verify before AND after the fix.

5. Do NOT:
   - Restructure files or rename functions.
   - Add new dependencies.
   - Switch to WebSockets.
   - Change DB column names.
   - Touch solo-mode code (script/script.js, dashboard/game.php) unless a
     shared helper is the root cause.

6. After all fixes:
   - Re-run node --check on the modified JS.
   - Re-run php -l on the modified PHP files.
   - Confirm mcp__ide__getDiagnostics shows no new diagnostics.
   - Produce a final summary table: scenario → bug → fix → verification step.

Begin with step 1. Print the timelines from step 2 before writing any code.
```

## 13. Notes
- Multiplayer bugs almost always show up as TIMING bugs. Reading the code top-to-bottom is rarely enough — the timeline-per-scenario discipline in step 2 of the prompt is the most important step.
- When in doubt, prefer a server-side conditional UPDATE (`UPDATE ... WHERE current_value = expected`) over a client-side flag. The two clients can lie or retry; the database row is the single source of truth.
- Keep all client-side state-reset logic centralized in `resetMatchState()`. New flags must be reset there too, or they'll leak across rematches.
- Any new logging should go through `error_log` on the server and `console.error` on the client — both are already wired into the existing flow.
