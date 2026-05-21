# Blitz Mode Update Requirements

## Blitz Mode Menu
When the user selects **Blitz Mode**, the following options must be displayed:
- Quick Match
- Create Room
- Join Room

---

# Quick Match

## Matchmaking Flow
1. When a player clicks **Quick Match**, they are added to the matchmaking queue.
2. The system searches for another active player in the Quick Match queue.
3. Once two players are found:
   - Player 1 (P1) and Player 2 (P2) are paired.
   - A confirmation container/modal is displayed with a **Ready** button.

## Ready System
- Both players have **20 seconds** to click **Ready**.
- If a player does not click Ready within 20 seconds:
  - That player receives a **15-second cooldown** before they can queue again.
- If one player clicks Ready but the other does not:
  - The ready player is automatically returned to the matchmaking queue.
  - The unready player receives the cooldown penalty.

## Match Start
Once both players click Ready:
1. Both players are redirected to the match page.
2. The match page displays:
   - P1 game table
   - P2 game table
3. Before gameplay starts, a countdown animation is shown:
   - “3”
   - “2”
   - “1”
   - “START!”
4. Blocks begin falling only after “START!” is displayed.

## Match Rules
- Match duration is fixed at **2 minutes**.
- The game ends when:
  - The timer reaches 2 minutes.
  - Or both players finish earlier.
- The winner is determined by the **highest score**.

## Post-Match Options
After the match ends, players can choose:
- **Rematch**
- **Find Another Match** (returns player to queue)
- **Exit** (returns player to main dashboard)

---

# Create Room

## Room Creation
1. When the player clicks **Create Room**:
   - A unique room code is generated.
   - The room code is displayed on screen.
2. The creator waits for another player to join.

## Room Joining
- Once another player joins using the room code:
  - Both players are placed into the same match room.
  - Both players must click **Ready** before the game starts.

## Gameplay
- Gameplay flow is identical to Quick Match:
  - Ready system
  - Countdown
  - 2-minute match
  - Winner determined by highest score
  - Post-match options

## Purpose
- Create Room is intended for private matches with friends using a generated room code.

---

# Join Room

## Join Flow
1. When the player clicks **Join Room**:
   - An input field for the room code is displayed.
2. After entering a valid room code:
   - The player joins the creator’s room.
   - Both players are assigned as:
     - P1 = Room Creator
     - P2 = Joining Player
3. Both players proceed to the Ready phase and then start the match.

---

# Important Constraints

- Existing working systems and features must NOT be modified or broken.
- Only implement the features described above.
- Preserve all existing game mechanics unless explicitly stated otherwise.
- Ensure matchmaking, room creation, and gameplay synchronization are stable and real-time.
- Prevent duplicate queue entries for the same player.
- Handle player disconnects gracefully during queue, ready state, and gameplay.