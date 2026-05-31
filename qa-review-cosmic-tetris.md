# QA Review: Cosmic Tetris

**Reviewer:** QA Agent
**Date:** 2026-05-31
**Scope:** Full audit — security, backend (PHP/PostgreSQL), functional logic (solo + multiplayer Blitz), UI/UX, accessibility, and visual design. All source reviewed: `dbConnect/`, `backEnd/`, `dashboard/` (incl. `blitz_room/` partials), `script/`, `css/`, `blitz_tables.sql`.
**Materials:** 28 source files + 2 PRDs + README. No running instance or live two-browser session — multiplayer findings are from code-trace, not live reproduction. Database credentials in the repo were **not** used.

---

## Orientation

Cosmic Tetris is a PHP + vanilla-JS web game on top of a Supabase (PostgreSQL) database, served from XAMPP. It offers a single-player mode with a global high-score leaderboard, and a two-player real-time "Blitz" battle (2-minute timed match, garbage attacks, rematch flow) built on HTTP polling against a shared room row. Auth is email/password with hashed passwords. The intended user is a student/casual player; the product is clearly a course capstone (`ITEC106` docs are in the repo). I reviewed it against that bar — "is this good and safe to put online," not "is this good for a class deadline."

## Verdict

The front-end engineering and visual design are genuinely above average for this category — a coherent neon design system, real `:focus-visible` styles and reduced-motion support in the Blitz arena, careful multiplayer state-machine code with thoughtful comments. But there is **one P0 that makes this unshippable as-is**: live database credentials are committed in plaintext and trivially downloadable. Beyond that, the entire scoring model is client-authoritative, so both leaderboards are forgeable from the browser console, and the Blitz leaderboard miscounts wins/losses on the most common match-ending path. The visual layer also leans on low-contrast text that fails WCAG across nearly every page, and the solo game is unplayable on touch devices. Fix the credential exposure immediately; then treat the score-integrity and leaderboard-recording bugs as the real work.

---

## Security findings

### Hardcoded live database credentials (P0)

`dbConnect/dbconnect.php:3-7` contains the real Supabase host, port, database name, username, and password in plaintext:

```php
private $password = "[REDACTED]";
```

This file sits under the web root (`C:\xampp\htdocs\Tetris`) and a `tetris.zip` snapshot is also in the repo. Anyone who obtains the source — a misconfigured Apache rule that serves `.php` as text, the zip, a screen-share, a pushed GitHub repo — gets full read/write/delete on every user account, password hash, and score in the database. This is account-takeover and total data loss in one line. **Fix:** rotate the Supabase password *now* (assume it is already compromised), move credentials to environment variables or an untracked config file outside the web root, add `dbconnect.php`, `*.zip`, and any config to `.gitignore`, and scrub the secret from git history.

### Scores are entirely client-controlled (P1)

The server never validates that a submitted score is achievable; it stores whatever the client sends. In solo, `script/script.js:188` POSTs `{score}` to `dashboard.php`, which calls `UpdateScore($username, $newscore)` (`backEnd/tetrisgame.php:92`) with no upper bound or replay check. In Blitz, `syncRoom` and `endGame` (`script/multiplayer.js:548, 907`) post `score`, `board`, `alive`, and `garbage` straight into the room row (`backEnd/blitz_api.php:332-359`). A user can open the console and run `score = 999999999; saveHighScore()` — or send a sync with `alive:1` forever and arbitrary garbage to the opponent. The CSRF token on the solo endpoint is correctly checked, but CSRF protection does nothing against a legitimately-logged-in cheater. For a game whose entire point is the leaderboard, this voids it. **Fix:** this is inherent to a client-authoritative design; the realistic mitigation is server-side sanity bounds (max score per elapsed second, max garbage per line-clear event, reject `alive` flips that contradict a topout), and treating both leaderboards as "for fun, not authoritative."

### Login enables user enumeration and unlimited brute force (P2)

`LoginUser` (`backEnd/tetrisgame.php:84-88`) returns "Email not found." for unknown emails and "Incorrect password." for known ones — distinct messages that let an attacker enumerate which emails are registered. There is no rate limiting, lockout, or delay, so an enumerated account can be brute-forced freely. **Fix:** return a single generic "Invalid email or password" for both cases, and add per-IP/per-account attempt throttling.

### No session hardening or regeneration (P2)

`session_start()` is called everywhere but the session ID is never regenerated after a successful login (`LoginUser`, `tetrisgame.php:73-76`), leaving the app open to session fixation. Cookies are not configured `HttpOnly`/`Secure`/`SameSite`. **Fix:** call `session_regenerate_id(true)` immediately after authenticating, and set `session.cookie_httponly`, `cookie_secure`, and `cookie_samesite` (Lax) in a bootstrap include.

### Blitz API has no CSRF protection (P3)

`backEnd/blitz_api.php` authenticates by session only and reads the action from `$_GET` + a JSON body; unlike the solo score endpoint it never checks a CSRF token. Impact is low (the actions only mutate ephemeral game rooms), but it is an inconsistency worth closing. **Fix:** require the same CSRF token the rest of the app already issues.

### Connection errors leak internal paths (P3)

On DB failure the app prints `friendlyError()` text to the user that names internal files (`dbConnect\dbconnect.php`, `C:\xampp\apache\logs\error.log`) — `dbconnect.php:36-65`, surfaced via `die()` in `tetrisgame.php:20` and the Blitz error phase. Useful in dev, but it discloses server layout in production. **Fix:** log the detailed message; show the user a generic "service temporarily unavailable."

## Functional bugs

### The topped-out loser's Blitz stats are never recorded (P2, borderline P1)

This is the most consequential logic bug. In the standard match ending — one player tops out, the other plays the clock out — the loser's row in `blitz_leaderboard` is never written. Trace: the topped-out player calls `endGame('TOPPED_OUT')` → `reportEnd('topped_out')` → `blitz_api.php endGame()`. Because the opponent is still alive, `$shouldFinalize` stays `false` (`blitz_api.php:424-437`), so the leaderboard `INSERT` at line 458 is skipped for that player. The room is later finalized by the *survivor's* `time_up` call, which inserts only the survivor's row (`username` = the caller). The loser therefore gets no loss, no `total_games` increment, and no `best_score` consideration. In the double-topout-via-sync path (`syncRoom` finalizes the room at `blitz_api.php:362-366` without any leaderboard insert), *neither* player may be recorded. This directly violates PRD acceptance criterion "`blitz_leaderboard` updated exactly once per finalized room per player." **Fix:** record each player's own result on their own `end` call regardless of who finalizes the room — e.g., write a per-player "result recorded" guard column and insert that player's win/loss when their side ends, rather than only when the room flips to finished.

### Leaderboard `INSERT` isn't guarded by room status, and the whole handler retries (P2)

The win/loss `INSERT ... ON CONFLICT` (`blitz_api.php:458-469`) is gated on `$shouldFinalize && $opp` but **not** on `status !== 'finished'`. Separately, `blitz_api.php:89-108` wraps the *entire* dispatch in a retry loop that re-runs the whole action on a transient PDO error. If a multi-write action like `endGame` succeeds at the `INSERT` and then hits a transient error before responding, the retry re-executes and double-counts that player's win/loss. **Fix:** make finalization idempotent — guard the leaderboard insert behind a conditional `UPDATE ... WHERE status <> 'finished'` that returns rowcount, and only insert when that update actually transitioned the room.

### A loss can be converted into a self-awarded win (P2)

`endGame` with `reason === 'disconnected'` sets `winner = $username` (the caller) unconditionally as long as the room isn't already finished (`blitz_api.php:421-423`). The client decides to send this (`finishByReason(..., 'disconnected')`) based on its own heartbeat check, so a crafted client can POST `end {reason:'disconnected'}` at any moment and instantly win and bank the win, while the opponent — who never calls `end` — banks nothing. **Fix:** verify the disconnect server-side (the opponent's `p{o}_updated` heartbeat is genuinely stale) before honoring a `disconnected` win, rather than trusting the caller's word.

### No fair shared piece sequence in Blitz; no 7-bag anywhere (P3)

Both modes pick pieces with `PIECES[Math.floor(Math.random()*…)]` (`script.js:199`, `multiplayer.js:114`). There is no 7-bag, so droughts and floods of the same piece happen often — substandard modern Tetris feel. In Blitz it's worse than cosmetic: each client RNGs its own sequence with no shared seed, so a "head-to-head battle" gives the two players different piece luck. **Fix:** implement a 7-bag randomizer; for Blitz, seed it from the room code so both clients draw the same bag.

### Garbage can bury the active piece without a topout check (P3)

`addGarbage` (`multiplayer.js:838-852`) shifts the board up and pushes garbage rows at the bottom on the next lock, but doesn't re-check whether the active piece now overlaps occupied cells; the topout test only runs when the *next* piece spawns. A player can briefly have a piece intersecting garbage. **Fix:** after applying garbage, run a collision check and resolve (nudge up or trigger topout) before continuing.

### Independent client timers can disagree on the winner edge (P3)

Each client runs its own `BLITZ_SECS` countdown started after a local 5-second countdown (`multiplayer.js:434-516`); start times can differ by a poll interval plus local timing, so the two clocks can drift ~1s. The winner-by-"still alive at 0:00" decision uses whichever client's clock expires first. Rare, but it can produce a result that feels wrong to the other player. **Fix:** derive remaining time from a server-authoritative match-start timestamp rather than two independent client intervals.

## UI/UX issues

### Solo game is unplayable on touch devices (P2)

`dashboard/game.php` wires controls to keyboard only (`script.js:331-341`); there are no on-screen buttons. Blitz has a proper `.blitz-mobile-controls` touch pad, but solo has none, so on a phone or tablet the single-player game cannot be played at all — pieces just fall. Given the responsive CSS clearly targets phones, this is a broken primary flow on a supported viewport. **Fix:** reuse the Blitz touch-button pattern (left/rotate/drop/right) on the solo board, shown via `d-md-none`.

### Register form loses input and only validates after a round-trip (P2)

Registration errors (email exists, username taken, passwords don't match) are only determined server-side, then the user is redirected back to a fresh `GET` (`tetrisgame.php:31-58`), so every typed field is wiped and they start over. There is no inline validation. Losing a half-filled form is one of the most frustrating UX failures. **Fix:** validate inline on the client (password match, length) before submit, and on server rejection re-render the form with the previously entered email/username preserved.

### Placeholder-only labels (P2)

Login and register inputs use `placeholder` as the only label (`login.php:43-48`, `register.php:42-55`). Placeholders vanish on focus/typing, hurting recall, accessibility, and error recovery. **Fix:** add visible `<label>` elements above each field (visually-hidden if the design truly can't spare the space, but visible is better here).

## Accessibility findings

### Pervasive low-contrast text — fails WCAG 1.4.3 (P2)

Across `style.css` pages, secondary text is rendered at `rgba(255,255,255,0.3)`–`0.35` and Bootstrap `text-white-50`: the login/register subtitles and "No account?/Already have an account?" links, dashboard "Welcome, Commander", placeholder text (`.glass-input::placeholder` at 0.3), and helper copy. At ~30–50% white on the dark galaxy background these land around 2–3:1, below the 4.5:1 minimum for body text. **Fix:** raise secondary text to at least `rgba(255,255,255,0.7)` (≈ 4.5:1 here) and audit placeholders specifically.

### No visible focus style on primary buttons in the non-arena pages (P2)

The Blitz arena correctly defines `:focus-visible` outlines (`blitz_room.css:676-680`), but `.neon-btn` in `style.css` has hover/active states and no focus style, so keyboard users on login, register, dashboard, leaderboard, and the solo game rely on the default browser outline — inconsistent and easy to lose against the busy background. **Fix:** add a `.neon-btn:focus-visible` outline mirroring the arena's `outline: 3px solid` treatment.

### Solo game-over modal isn't a real dialog (P2)

`#game-over-overlay` (`game.php:47`) is a plain `div` shown via a class. It has no `role="dialog"`/`aria-modal`, doesn't trap focus, doesn't move focus to itself on open or return it on close, and `Escape` doesn't dismiss it (input is hard-blocked by `if(gameOver)return` in the keydown handler). A keyboard or screen-reader user is left stranded. **Fix:** give it `role="dialog" aria-modal="true"`, focus the "Play Again" button on open, trap Tab within the card, and support Escape → Exit.

### Decorative medals and constant motion (P3)

Leaderboard ranks 1–3 are emoji medals with no text rank for assistive tech in those rows (`leaderboard.php:51`); and several always-on animations (CRT flicker at 0.15s, star drift, title glitch, holo-shimmer) run perpetually. The flicker is subtle and `prefers-reduced-motion` is respected, so this is minor, but the simultaneous motion competes for attention. **Fix:** keep the numeric rank as visually-hidden text alongside the medal; consider easing off two or three of the perpetual effects.

## Design critique

The design is trying to evoke an arcade/synthwave "cosmic" fantasy, and it largely succeeds. This is not a default-driven Bootstrap skin: there's a real token system (`:root` neon variables, a shared glass-card treatment, consistent radii and shadows), an intentional Orbitron/Rajdhani type pairing doing actual hierarchical work, and — notably — the Blitz arena CSS is responsive down to 320px with `grid-template-areas`, safe-area insets, `:focus-visible`, and a `prefers-reduced-motion` block. That is more care than most projects at this level show, and it deserves to be said plainly.

What's working: the cohesion. Buttons, cards, score plates, and code displays all feel like they belong to one product. The neon-on-near-black palette is restrained to a small set of signature hues used consistently (cyan = system, orange = Blitz). The motion vocabulary, when it's event-driven (line-clear flash, level-up burst, score pop), genuinely reinforces gameplay.

What isn't working sits at two levels. First, **everything glows and moves at once** — drifting stars, twinkle, nebula breathe, CRT flicker, holo-shimmer on every card, glitching titles, pulsing score boxes — so the always-on motion has no hierarchy. When the whole screen is alive, nothing the player actually needs to notice (an incoming garbage warning, the ticking timer) gets to be the loud thing. Second, the **low-contrast secondary text** is a self-inflicted wound: the same design that nails the headline treatment renders its supporting copy at 30% opacity, which reads as "unfinished" to a discerning eye and "illegible" to anyone with low vision.

**Verdict:** This is genuinely sharp visual work with a clear point of view, undercut by two fixable things — overuse of ambient motion that flattens hierarchy, and secondary text contrast that fails accessibility. These are *substantive* refinements, not a redirection. The design doesn't need a rethink; it needs restraint and a contrast pass.

## What I covered, what I didn't

I read every PHP, JS, CSS, and SQL file end-to-end and traced the major flows: auth, solo game loop and scoring, and the full Blitz lifecycle (matchmaking, ready, countdown, sync, garbage, topout, time-up, disconnect, rematch). I did **not** run the app or do the two-browser live reproductions the multiplayer PRD calls for, so the timing/race findings are reasoned from the code, not observed — they should be confirmed with the PRD's reproduction recipes. I did not measure contrast ratios with a tool (values above are estimated from the color/opacity math) or test with an actual screen reader. The `.docx`/`.zip` artifacts in the repo were not opened beyond noting their presence as a disclosure risk. `php -l` could not run in this environment; both JS files pass `node --check`.

---

## Bug table

| ID  | Severity | Category | Area / Component | Issue | Evidence / Location | Recommended fix |
|-----|----------|----------|------------------|-------|---------------------|-----------------|
| 001 | P0 | Functional/Security | DB connection | Live Supabase credentials committed in plaintext under web root (+ in `tetris.zip`) | `dbConnect/dbconnect.php:3-7` | Rotate the password now; move secrets to env/untracked config outside web root; gitignore + scrub history |
| 002 | P1 | Security | Scoring (solo + Blitz) | Scores/board/alive/garbage are client-supplied and stored unvalidated — leaderboards forgeable from console | `script.js:188`; `multiplayer.js:548,907`; `blitz_api.php:332-359`; `tetrisgame.php:92` | Add server-side sanity bounds; reject impossible scores/garbage/alive transitions |
| 003 | P2 | Functional | Blitz leaderboard | Topped-out loser's win/loss/total_games is never recorded; double-topout-via-sync may record neither player | `blitz_api.php:418-470`; `multiplayer.js:875-905` | Record each player's result on their own `end` call, not only when the room finalizes |
| 004 | P2 | Functional | Blitz finalize | Leaderboard INSERT not guarded by `status<>'finished'`; whole-handler transient retry can double-count | `blitz_api.php:89-108, 451-469` | Make finalize idempotent via conditional UPDATE rowcount; insert only on the transition |
| 005 | P2 | Security | Blitz endGame | `reason:'disconnected'` sets winner = caller unconditionally → self-awarded win | `blitz_api.php:421-423`; `multiplayer.js:949` | Verify opponent heartbeat is genuinely stale server-side before honoring a disconnect win |
| 006 | P2 | Security | Login | Distinct "email not found" vs "incorrect password" + no rate limit → enumeration & brute force | `tetrisgame.php:79-88` | Single generic error message; add attempt throttling/lockout |
| 007 | P2 | Security | Sessions | No `session_regenerate_id` on login; no HttpOnly/Secure/SameSite cookie flags | `tetrisgame.php:63-76` | Regenerate session id on auth; set cookie security flags |
| 008 | P2 | UX | Solo game (mobile) | No touch controls — single-player is unplayable on phones/tablets | `dashboard/game.php`; `script.js:331-341` | Add on-screen control pad like Blitz's `.blitz-mobile-controls` |
| 009 | P2 | UX | Register form | Server-only validation + redirect wipes all typed input; no inline checks | `tetrisgame.php:31-58`; `register.php` | Inline client validation; re-render with preserved email/username on rejection |
| 010 | P2 | A11y | Login/Register inputs | Placeholder-only labels vanish on focus | `login.php:43-48`; `register.php:42-55` | Add visible `<label>` elements above inputs |
| 011 | P2 | A11y | Global text | Secondary text at 30–50% white fails WCAG 1.4.3 (~2–3:1) | `style.css:104,148`-style usages; `text-white-50` across pages | Raise to ≥ `rgba(255,255,255,0.7)`; audit placeholders |
| 012 | P2 | A11y | Buttons (non-arena) | `.neon-btn` has no focus-visible style; keyboard focus inconsistent | `style.css:121-134` (cf. `blitz_room.css:676-680`) | Add `.neon-btn:focus-visible` outline matching the arena |
| 013 | P2 | A11y | Solo game-over modal | Not a dialog: no role/aria-modal, no focus trap/return, Escape doesn't close | `game.php:47-59`; `script.js:331-341` | Add `role="dialog" aria-modal`, focus management, Escape handler |
| 014 | P3 | Security | Blitz API | No CSRF check (unlike solo score endpoint) | `blitz_api.php:66-99` | Require the existing CSRF token on state-changing actions |
| 015 | P3 | Security | Error handling | DB error messages disclose internal file paths to users | `dbconnect.php:36-65`; `tetrisgame.php:20` | Log details; show generic user-facing message |
| 016 | P3 | Functional | Randomizer | No 7-bag; Blitz clients use unseeded independent RNG → unfair piece luck | `script.js:199`; `multiplayer.js:114` | Add 7-bag; seed Blitz from room code for a shared sequence |
| 017 | P3 | Functional | Blitz garbage | Garbage can bury active piece with no immediate topout/collision check | `multiplayer.js:838-852` | Re-check collision after applying garbage |
| 018 | P3 | Functional | Blitz timers | Two independent client clocks can drift ~1s and flip edge-case winner | `multiplayer.js:434-516` | Derive remaining time from a server match-start timestamp |
| 019 | P3 | A11y | Leaderboard | Emoji medals replace numeric rank with no text alternative for ranks 1–3 | `leaderboard.php:51`; `blitz_leaderboard.php:73` | Keep visually-hidden numeric rank alongside the medal |
| 020 | Substantive | Design | Global motion | Constant ambient animation (CRT, drift, glitch, shimmer, pulses) flattens visual hierarchy | `style.css` keyframes; `galaxy-bg` layers | Reserve always-on motion; let event-driven motion be the loud thing |
| 021 | Polish | Design | Secondary text | Same low-contrast copy reads as "unfinished" alongside polished headlines | `style.css` opacity usages | Contrast pass on all supporting text (ties to #011) |
| 022 | P3 | Security | Repo hygiene | `tetris.zip`, PRDs, and docs (incl. credentials in the zip) committed under web root | repo root | Remove archives/docs from the served directory; gitignore |
