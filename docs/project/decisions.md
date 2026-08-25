# Architecture Decisions

Decisions that shaped the backend design and the reasoning behind them.

---

## 1. Client Computes All Game State

**Decision:** The client submits `resulting_state` (the full post-turn `GameState`) rather than just actions. The backend stores it as-is.

**Rationale:**
- The game engine is written in TypeScript. Running it server-side would require either a PHP port (expensive, fragile) or always calling the Node.js CLI on every turn (latency, complexity).
- The initial build prioritises simplicity over server-side validation of game rules.
- The client is trusted for game state correctness. Identity and turn ownership are validated server-side.

**Trade-off:** A malicious client could submit a manipulated game state. Acceptable for a friend-group game; revisit if cheating becomes a concern.

---

## 2. AI Turns Are Client-Side

**Decision:** The client runs all AI turns before submitting. `currentPlayerId` in the submitted state always points to a human.

**Rationale:** Same as above — the AI logic is in TypeScript. Running AI server-side would require the Node.js bridge for every turn, adding latency.

**Trade-off:** Clients could skip AI turns or give AI players unfair advantages. Acceptable for the current use case.

---

## 3. No WebSockets / No Real-Time

**Decision:** Pure request/response. No WebSockets, no SSE, no broadcasting.

**Rationale:**
- Async multiplayer by design — players take turns minutes or hours apart.
- Push notifications handle the "it's your turn" alert adequately.
- WebSockets add infrastructure complexity (long-running connections, scaling concerns) that is not justified.

**Future:** If live game lobbies or spectator mode are added, revisit.

---

## 4. No Queues (Initial Build)

**Decision:** Notifications and game init run synchronously in the request cycle.

**Rationale:** Expo push calls are fast (< 200ms). Node.js map init is fast (< 1s). With low initial traffic, synchronous execution is simpler to develop and debug.

**Future:** Add a database-driver queue when reliability or latency issues appear.

---

## 5. Sanctum Over JWT

**Decision:** Laravel Sanctum personal access tokens rather than JWT.

**Rationale:**
- Sanctum is the Laravel-native solution, requiring no additional packages.
- Tokens are invalidated server-side on logout (revocable, unlike JWT).
- No expiration keeps the mobile UX smooth (no token refresh flow).

---

## 6. Username-Only Registration (No Email)

**Decision:** Accounts require only a username and password.

**Rationale:**
- Lower friction for a game-first experience.
- No email verification complexity.
- No GDPR-sensitive data to manage.

**Trade-off:** No password reset self-service. Resets require admin intervention.

---

## 7. Friends Required for Game Invites

**Decision:** You must be accepted friends before you can invite someone to a game.

**Rationale:** Prevents spam invites from strangers. Keeps the social graph intentional.

**2026-08-25:** Open-lobby matchmaking is a second fill model. Extra human seats with `user_id` null do not require friendship. Invite games still require accepted friends. One game cannot mix both models.

---

## 8. Invite Decline Cancels the Game

**Decision:** If any invitee declines, the game is immediately set to `finished` and cannot proceed.

**Rationale:** A game with missing players cannot be balanced or played. Cancellation is cleaner than trying to fill the slot or allow the game to start with fewer players than created.

---

## 9. Game State Is Opaque to the Backend

**Decision:** The backend never parses or mutates fields within `state_json`. It reads only a small set of top-level fields during turn submission (`currentPlayerId`, `status`, `roundNumber`, `players`).

**Rationale:** The `GameState` structure is owned by the TypeScript game engine. Coupling the backend to internal game state fields would require coordinated changes across two codebases whenever the game engine evolves.

---

## 10. Node.js Engine for Game Initialization Only

**Decision:** The Node.js CLI is used only for `startGame()` (initial map generation). It is not called on every turn.

**Rationale:** Map generation is deterministic and only needs to happen once per game. Calling Node.js on every turn would be a significant performance and reliability concern.
