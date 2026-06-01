# Backend Architecture

## Overview

Strategic Commander's backend is a stateless Laravel REST API. It persists game state, manages user accounts and friendships, orchestrates async multiplayer turns, and delivers push notifications via Expo. There is no real-time layer; all communication is request/response.

Pass-and-play games are entirely client-side and never touch the backend.

---

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Laravel 11 (PHP 8.2+) |
| Authentication | Laravel Sanctum (token-based) |
| Database | MySQL 8+ |
| HTTP API | REST, JSON |
| Push Notifications | Expo Push API (direct HTTP from Laravel) |
| Game Initialization | Node.js CLI script (wraps the TypeScript game engine) |

**Not used in the initial build:** WebSockets, queues, Redis, Horizon, broadcasting. These are future additions.

---

## Request / Response Contract

- All routes prefixed with `/api/`
- All responses are JSON
- Auth routes (`/api/auth/register`, `/api/auth/login`) are public; all others require `Authorization: Bearer {token}`
- HTTP status codes: `200`, `201`, `401`, `403`, `404`, `409`, `422`
- Error envelope: `{ "message": "...", "errors": { "field": ["..."] } }`

---

## Application Structure

```
app/
  Http/
    Controllers/
      AuthController.php         — register, login, logout, me
      FriendController.php       — friends list, requests, accept/decline/remove
      GameController.php         — game CRUD, list
      InviteController.php       — list, accept, decline invites
      PushTokenController.php    — save Expo push token
      TurnController.php         — save, submit, abandon turn
      UserController.php         — user search
  Models/
    User.php
    Friendship.php
    Game.php
    GamePlayer.php
    GameInvite.php
    Turn.php
  Services/
    GameService.php              — startGame(), computeAlertState()
    NotificationService.php      — sendPushNotification()
    FriendService.php            — getFriendshipStatus()
engine/
  init-game.js                   — compiled Node.js game initialization CLI
database/
  migrations/
    create_users_table.php
    create_friendships_table.php
    create_games_table.php
    create_game_players_table.php
    create_game_invites_table.php
    create_turns_table.php
routes/
  api.php
```

---

## Sanctum Configuration

- Tokens do **not** expire (`'expiration' => null` in `config/sanctum.php`)
- Single active session per device enforced at login (all prior tokens deleted)
- Token stored in client AsyncStorage; explicitly invalidated only on logout
- Middleware group `auth:sanctum` applied to all protected routes

---

## CORS

Mobile clients connect from native apps, so CORS is open for the initial build:

```php
'paths' => ['api/*'],
'allowed_origins' => ['*'],   // tighten in production
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
```

---

## Game Initialization (Node.js Engine Bridge)

When a game starts, the initial `GameState` must be deterministically generated using the same TypeScript game engine used by the client. Laravel shells out to a compiled Node.js CLI:

```
engine/init-game.js
```

- **Input (stdin):** `{ mapConfig, playerSlots }`
- **Output (stdout):** full initial `GameState` JSON
- Laravel uses `Process::run(...)` to call it synchronously
- The Node.js runtime must be installed on the server
- The script is built from `src/game/` in the frontend repository

See `turn-engine.md` for the full `startGame()` logic.

---

## Key Architectural Decisions

- **Client computes all game state.** The backend stores, but never re-computes, the `GameState`. Turn submissions include the full resulting state; the backend validates structure and identity only.
- **AI turns are client-side.** The client runs all AI turns before submitting; `currentPlayerId` in the submitted state always points to the next human.
- **No server-side game logic.** Game rules, combat, production, and fleet movement are all resolved on the client.
- **State is opaque to the backend.** `state_json` is stored and returned as-is; the server never mutates fields within it.

See `docs/project/decisions.md` for rationale.
