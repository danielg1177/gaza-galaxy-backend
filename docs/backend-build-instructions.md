# Strategic Commander — Backend Build Instructions

> **This document is the authoritative specification for the Strategic Commander backend.**
> It is written to be handed directly to an AI model (or developer) responsible for building the backend.
> Read this entire document before writing any code.

---

## 1. Project Overview

Strategic Commander is a turn-based asynchronous space-strategy mobile game for iPhone. This document covers everything needed to build the backend that supports:

- User accounts (username + password, persistent sessions)
- A friends system (search, friend requests, accept/decline)
- Async multiplayer games (invite friends, each player uses their own device)
- Game state persistence (full GameState JSON stored per game)
- Mid-turn save and resume (players can exit mid-turn and pick up where they left off)
- Push notifications (turn alerts, game invites, game outcomes)

**Pass-and-play games are entirely local on the client. They do NOT touch the backend.**

---

## 2. Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Laravel (PHP) — latest stable |
| Authentication | Laravel Sanctum |
| Database | MySQL 8+ |
| HTTP API | REST, JSON responses |
| Push Notifications | Expo Push API (HTTP calls from Laravel) |
| Game Initialization | Node.js CLI script (wraps the TypeScript game engine) |

**Do not use WebSockets, queues, Redis, or Horizon for the initial build.** These are noted as future additions. Keep infrastructure minimal.

---

## 3. Database Schema

Run these migrations in the order listed.

---

### 3.1 `users`

```sql
CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(30) NOT NULL,
  password VARCHAR(255) NOT NULL,
  expo_push_token VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_username (username),
  INDEX idx_username (username)
);
```

**Notes:**
- `username`: alphanumeric + underscores only, 3–30 characters, validated at the application layer
- `password`: bcrypt hashed via Laravel's `Hash::make()`
- `expo_push_token`: Expo push token in format `ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]`; may be null if user has not granted notification permissions

---

### 3.2 `personal_access_tokens` (Sanctum)

Created automatically by `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"` and `php artisan migrate`. No modifications needed.

---

### 3.3 `friendships`

```sql
CREATE TABLE friendships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requester_id BIGINT UNSIGNED NOT NULL,
  addressee_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending', 'accepted', 'declined', 'blocked') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_pair (requester_id, addressee_id),
  FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_addressee_status (addressee_id, status),
  INDEX idx_requester_status (requester_id, status)
);
```

**Notes:**
- Only one row per ordered pair — the constraint is directional (`requester → addressee`)
- To check if two users are friends, query both `(user_a, user_b)` and `(user_b, user_a)`
- Declined requests may be retried: update status back to 'pending' or delete and re-create

---

### 3.4 `games`

```sql
CREATE TABLE games (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  creator_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT 'Untitled Game',
  status ENUM('waiting', 'active', 'finished') NOT NULL DEFAULT 'waiting',
  play_mode ENUM('async_multiplayer') NOT NULL DEFAULT 'async_multiplayer',
  map_config_json TEXT NOT NULL,
  current_user_id BIGINT UNSIGNED NULL,
  turn_number INT UNSIGNED NOT NULL DEFAULT 0,
  round_number INT UNSIGNED NOT NULL DEFAULT 1,
  state_json LONGTEXT NOT NULL DEFAULT '',
  winner_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (creator_id) REFERENCES users(id),
  FOREIGN KEY (current_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (winner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_creator (creator_id),
  INDEX idx_current_user (current_user_id),
  INDEX idx_status (status)
);
```

**Notes:**
- `play_mode`: currently only `async_multiplayer` — pass-and-play is local-only
- `map_config_json`: serialized map config. Structure:
  ```json
  { "mapSize": "medium", "mapWidth": 286, "mapHeight": 286, "planetCount": 30, "seed": 1748556123456, "galaxyShape": "scattered" }
  ```
- `current_user_id`: the human player whose turn it currently is. NULL if it is an AI's turn (but this should never persist — the client resolves all AI turns before submitting)
- `state_json`: the full serialized `GameState` TypeScript object (as JSON). Empty string until the game starts.
- `turn_number`: increments by 1 each time any player's turn is submitted
- `round_number`: matches `GameState.roundNumber` — increments when all players complete a full cycle

---

### 3.5 `game_players`

```sql
CREATE TABLE game_players (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  turn_order INT UNSIGNED NOT NULL,
  in_game_name VARCHAR(100) NOT NULL,
  is_ai TINYINT(1) NOT NULL DEFAULT 0,
  ai_difficulty ENUM('easy', 'normal', 'hard') NULL,
  is_eliminated TINYINT(1) NOT NULL DEFAULT 0,
  home_planet_id VARCHAR(50) NOT NULL DEFAULT '',
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_turn_order (game_id, turn_order),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_id (user_id),
  INDEX idx_game_id (game_id)
);
```

**Notes:**
- `turn_order`: 0-based index matching the player's position in the `GameState.players` array
- `user_id`: NULL for AI players; set to the user's ID for human players
- `home_planet_id`: matches `Planet.id` in the `state_json`. Format: `"p-{index}"` (e.g. `"p-0"`, `"p-3"`). Set when the game starts.
- `is_eliminated`: updated when turn submissions result in player elimination

---

### 3.6 `game_invites`

```sql
CREATE TABLE game_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  inviter_id BIGINT UNSIGNED NOT NULL,
  invitee_id BIGINT UNSIGNED NOT NULL,
  player_slot_index INT UNSIGNED NOT NULL,
  status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_invitee_status (invitee_id, status),
  INDEX idx_game_id (game_id)
);
```

**Notes:**
- `player_slot_index`: which slot in `game_players` (by `turn_order`) this invite fills
- When an invite is accepted, the matching `game_players` row already exists (created at game creation time); no new row is needed

---

### 3.7 `turns`

```sql
CREATE TABLE turns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  turn_number INT UNSIGNED NOT NULL,
  round_number INT UNSIGNED NOT NULL,
  submitted_actions_json LONGTEXT NULL,
  in_progress_actions_json LONGTEXT NULL,
  resulting_state_json LONGTEXT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_turn (game_id, user_id, turn_number, round_number),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_game_turn (game_id, turn_number),
  INDEX idx_user_game (user_id, game_id)
);
```

**Notes:**
- `submitted_actions_json`: the final `PlayerAction[]` array from the client. Structure:
  ```json
  [
    { "type": "SEND_FLEET", "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 },
    { "type": "BUILD", "planetId": "p-3", "buildingType": "factory" },
    { "type": "SET_PRODUCTION_SLIDER", "planetId": "p-3", "value": 0.7 },
    { "type": "END_TURN" }
  ]
  ```
- `in_progress_actions_json`: mid-turn save blob. Structure:
  ```json
  {
    "partial_state_json": "{...full current GameState as JSON string...}",
    "queued_orders": [
      { "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }
    ]
  }
  ```
- `resulting_state_json`: the full `GameState` after turn resolution. Set on submit.
- A row may be created on first save and updated on submit. Use upsert logic.

---

## 4. API Routes

**Authentication:** All routes except `/api/auth/register` and `/api/auth/login` require a valid Sanctum token via `Authorization: Bearer {token}` header.

**Error envelope:**
```json
{ "message": "Human-readable error", "errors": { "field": ["validation message"] } }
```

**Success responses** use the shapes defined below. HTTP status codes: 200 OK, 201 Created, 422 Unprocessable Entity, 401 Unauthorized, 403 Forbidden, 404 Not Found, 409 Conflict.

---

### 4.1 Authentication Routes

#### `POST /api/auth/register`
No auth required.

**Request body:**
```json
{ "username": "string", "password": "string", "password_confirmation": "string" }
```

**Validation rules:**
- `username`: required | string | min:3 | max:30 | regex:/^[a-zA-Z0-9_]+$/ | unique:users,username
- `password`: required | string | min:6 | confirmed

**Logic:**
1. Create `users` row: `username`, `password = Hash::make($request->password)`.
2. Create a Sanctum personal access token: `$user->createToken('mobile')->plainTextToken`.
3. Return the plain-text token (it is never retrievable again after this response).

**Response (201):**
```json
{ "user": { "id": 1, "username": "commander_dan" }, "token": "1|abc123..." }
```

---

#### `POST /api/auth/login`
No auth required.

**Request body:** `{ "username": "string", "password": "string" }`

**Logic:**
1. Find user by `username` (exact match, case-sensitive).
2. `Hash::check($request->password, $user->password)` — return 401 if false.
3. Delete all existing tokens for this user (enforce single active session per device — optional but recommended).
4. Create new Sanctum token.

**Response (200):**
```json
{ "user": { "id": 1, "username": "commander_dan" }, "token": "2|xyz789..." }
```

**Error (401):**
```json
{ "message": "Invalid credentials" }
```

---

#### `POST /api/auth/logout`
Auth required.

**Logic:** `$request->user()->currentAccessToken()->delete()`.

**Response (200):** `{ "message": "Logged out" }`

---

#### `GET /api/auth/me`
Auth required.

**Response (200):** `{ "id": 1, "username": "commander_dan" }`

---

### 4.2 Push Token

#### `POST /api/push-token`
Auth required.

**Request body:** `{ "token": "ExponentPushToken[...]" }`

**Validation:** `token`: required | string | starts_with:ExponentPushToken (or allow bare device token format for broader compatibility).

**Logic:** `$request->user()->update(['expo_push_token' => $request->token])`.

**Response (200):** `{ "saved": true }`

---

### 4.3 Friends

#### `GET /api/friends`
Auth required.

**Logic:**
```sql
SELECT f.id as friendship_id,
  CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END as other_user_id
FROM friendships f
WHERE (f.requester_id = ? OR f.addressee_id = ?)
  AND f.status = 'accepted'
```
Join to `users` to get `other_user_id`'s username.

**Response (200):**
```json
{
  "friends": [
    { "friendship_id": 3, "user": { "id": 5, "username": "battlestar" } }
  ]
}
```

---

#### `GET /api/friends/requests`
Auth required.

**Logic:** `SELECT * FROM friendships WHERE addressee_id = ? AND status = 'pending'` + join users.

**Response (200):**
```json
{
  "requests": [
    {
      "friendship_id": 7,
      "from_user": { "id": 2, "username": "nova" },
      "created_at": "2026-05-29T12:00:00.000Z"
    }
  ]
}
```

---

#### `POST /api/friends/request`
Auth required.

**Request body:** `{ "username": "string" }`

**Logic:**
1. Find target user by exact `username` match. 404 if not found.
2. 422 if target user is the authenticated user themselves.
3. Check for an existing friendship row in either direction (any status):
   ```sql
   SELECT id FROM friendships
   WHERE (requester_id = me AND addressee_id = target)
      OR (requester_id = target AND addressee_id = me)
   LIMIT 1
   ```
   If found: 422 `{ "message": "Friend request already exists or you are already friends" }`.
4. Create `friendships` row: `requester_id = me`, `addressee_id = target`, `status = 'pending'`.

**Response (201):**
```json
{ "friendship_id": 8, "status": "pending" }
```

---

#### `POST /api/friends/requests/{friendship_id}/accept`
Auth required.

**Logic:**
1. Find friendship: `id = {friendship_id} AND addressee_id = me AND status = 'pending'`. 404 if not found.
2. Update: `status = 'accepted'`.

**Response (200):**
```json
{ "friendship_id": 8, "status": "accepted" }
```

---

#### `POST /api/friends/requests/{friendship_id}/decline`
Auth required.

**Logic:**
1. Find friendship: `id = {friendship_id} AND addressee_id = me AND status = 'pending'`. 404 if not found.
2. Update: `status = 'declined'` (retain row for audit purposes; client ignores declined rows).

**Response (200):** `{ "message": "Declined" }`

---

#### `DELETE /api/friends/{friendship_id}`
Auth required.

**Logic:**
1. Find friendship: `id = {friendship_id} AND (requester_id = me OR addressee_id = me)`. 404 if not found.
2. Delete the row.

**Response (200):** `{ "message": "Friend removed" }`

---

#### `GET /api/users/search`
Auth required.

**Query string:** `?q={search_term}` (required, min 1 char)

**Logic:**
1. `SELECT id, username FROM users WHERE username LIKE '%{q}%' AND id != me LIMIT 20`
2. For each result, determine `friendship_status`:
   - Query friendships table for a row involving both `me` and this user (either direction)
   - `none`: no row exists
   - `pending_sent`: row exists with `requester_id = me AND status = 'pending'`
   - `pending_received`: row exists with `addressee_id = me AND status = 'pending'`
   - `accepted`: row exists with `status = 'accepted'`

**Response (200):**
```json
{
  "users": [
    { "id": 4, "username": "warlord99", "friendship_status": "none" },
    { "id": 5, "username": "nova", "friendship_status": "accepted" }
  ]
}
```

---

### 4.4 Games

#### `GET /api/games`
Auth required.

**Logic:**
1. Join `game_players` (where `user_id = me`) → `games`.
2. For each game, compute `alert_state` (see logic below).
3. Return summaries (no `state_json`).

**`alert_state` computation:**
```
IF games.status = 'waiting'         → 'waiting_for_players'
IF games.status = 'finished'        → 'finished'
IF games.status = 'active':
  IF games.current_user_id != me    → 'waiting'
  IF games.current_user_id = me:
    Lookup: SELECT * FROM turns
      WHERE game_id = games.id
        AND user_id = me
        AND turn_number = games.turn_number
        AND round_number = games.round_number
        AND submitted_at IS NULL
      LIMIT 1
    IF no row found                          → 'your_turn'
    IF row.in_progress_actions_json IS NULL  → 'your_turn'
    IF row.in_progress_actions_json IS NOT NULL → 'in_progress'
```

**Response (200):**
```json
{
  "games": [
    {
      "id": 1,
      "name": "The Final War",
      "status": "active",
      "play_mode": "async_multiplayer",
      "alert_state": "your_turn",
      "is_my_turn": true,
      "has_in_progress_actions": false,
      "winner_user_id": null,
      "players": [
        { "in_game_name": "Dan", "is_ai": false, "is_eliminated": false, "user_id": 1 },
        { "in_game_name": "Nova", "is_ai": false, "is_eliminated": false, "user_id": 5 },
        { "in_game_name": "Zorg", "is_ai": true, "is_eliminated": false, "user_id": null }
      ],
      "current_player_name": "Dan",
      "round_number": 3,
      "turn_number": 12,
      "created_at": "2026-05-29T12:00:00.000Z"
    }
  ]
}
```

---

#### `POST /api/games`
Auth required.

**Request body:**
```json
{
  "name": "The Final War",
  "map_config": {
    "mapSize": "medium",
    "mapWidth": 286,
    "mapHeight": 286,
    "planetCount": 30,
    "seed": 1748556123456,
    "galaxyShape": "scattered"
  },
  "player_slots": [
    { "type": "human", "user_id": null, "name": "Dan" },
    { "type": "human", "user_id": 5, "name": "Nova" },
    { "type": "ai", "difficulty": "normal", "name": "Zorg" }
  ]
}
```

`user_id: null` in the first (slot 0) human entry means the authenticated creator fills that slot.

**Validation:**
- `name`: required | string | max:100
- `map_config`: required object with `mapSize` (small|medium|large), `mapWidth` (int > 0), `mapHeight` (int > 0), `planetCount` (int 2–100), `seed` (int), `galaxyShape` (scattered|arms|dense_core|ring)
- `player_slots`: required array, length 2–8
- Each slot: `type` (human|ai), `user_id` (nullable int for humans), `name` (string max 50), `difficulty` (easy|normal|hard, only for ai type)
- All human `user_id`s (non-null, non-creator) must be IDs of users who are **accepted friends** of the authenticated creator

**Logic:**
1. Validate all inputs (above).
2. Verify friendship for each non-creator human slot: query `friendships` for `accepted` status between creator and each `user_id`. 422 if any are not friends.
3. Create `games` row: `status = 'waiting'`, `state_json = ''`.
4. Create `game_players` rows for each slot:
   - Slot 0 (creator): `user_id = creator.id`, `in_game_name = request.player_slots[0].name`
   - Other human slots: `user_id = slot.user_id`, `in_game_name = slot.name`
   - AI slots: `user_id = NULL`, `is_ai = true`, `ai_difficulty = slot.difficulty`, `in_game_name = slot.name`
5. For each non-creator human slot: create `game_invites` row: `game_id`, `inviter_id = creator`, `invitee_id = slot.user_id`, `player_slot_index = slot index`, `status = 'pending'`.
6. **If there are no pending invites** (all human slots are the creator, i.e., there is only one human and no invited friends): immediately start the game (call `startGame($game)` — see Section 5).
7. Send push notification to each invited user: `"Game Invite"` / `"{creator_username} invited you to play Strategic Commander"`.

**Response (201):**
```json
{
  "game": { "id": 1, "name": "The Final War", "status": "waiting" },
  "invites_sent": [5]
}
```

---

#### `GET /api/games/{id}`
Auth required. User must be a member of this game (check `game_players`).

**Logic:**
1. Load game. Verify requesting user is in `game_players`. 403 if not.
2. Load `game_players` for this game.
3. Determine `is_my_turn = (games.current_user_id = me)`.
4. If `is_my_turn`: look up any unsubmitted turn record for this `(game_id, user_id, turn_number, round_number)`.
5. Build `in_progress_actions`:
   - If turn record exists and `in_progress_actions_json` is not null: return parsed JSON
   - Otherwise: null
6. Compute `alert_state` (same logic as in `GET /api/games`).

**Response (200):**
```json
{
  "game": {
    "id": 1,
    "name": "The Final War",
    "status": "active",
    "play_mode": "async_multiplayer",
    "round_number": 3,
    "turn_number": 12
  },
  "state_json": "{\"map\":{...},\"players\":[...],\"fleets\":[...],\"currentPlayerId\":\"player-0\",...}",
  "is_my_turn": true,
  "alert_state": "in_progress",
  "in_progress_actions": {
    "partial_state_json": "{...full current GameState...}",
    "queued_orders": [{ "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }]
  }
}
```

**Privacy enforcement:** `in_progress_actions` is ONLY included when `is_my_turn = true`. Never return another player's in-progress state.

---

#### `DELETE /api/games/{id}`
Auth required. Must be the creator. Only when `status = 'waiting'`.

**Logic:**
1. Verify `games.creator_id = me`. 403 if not.
2. Verify `games.status = 'waiting'`. 422 if not.
3. Delete game (cascades to `game_players`, `game_invites`, `turns`).

**Response (200):** `{ "message": "Game deleted" }`

---

### 4.5 Turns

#### `POST /api/games/{id}/turn/save`
Auth required. Must be the current player (`games.current_user_id = me`).

**Request body:**
```json
{
  "in_progress_actions": {
    "partial_state_json": "{...full current GameState...}",
    "queued_orders": [
      { "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }
    ]
  }
}
```

**`partial_state_json`** is the full `GameState` as it exists in the client's memory at the moment of exit. It includes all mutations from this turn (builds placed, production sliders changed) with any previously queued fleet orders NOT yet applied. **`queued_orders`** are the staged (but not committed) fleet dispatches.

**Logic:**
1. Verify `games.current_user_id = $me->id`. 403 if not (or 409 if it is no longer this player's turn).
2. Upsert `turns` row:
   ```
   game_id = {id}, user_id = me,
   turn_number = games.turn_number, round_number = games.round_number
   ```
   Set: `in_progress_actions_json = JSON(request.in_progress_actions)`, `started_at = NOW()` (only if null).

**Response (200):** `{ "saved": true }`

---

#### `POST /api/games/{id}/turn/submit`
Auth required. Must be the current player.

**Request body:**
```json
{
  "actions": [
    { "type": "SEND_FLEET", "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 },
    { "type": "BUILD", "planetId": "p-0", "buildingType": "factory" },
    { "type": "SET_PRODUCTION_SLIDER", "planetId": "p-0", "value": 0.7 },
    { "type": "END_TURN" }
  ],
  "resulting_state": { ...full GameState object... },
  "turn_number": 12,
  "round_number": 3
}
```

**Validation:**
- `actions`: required array
- `resulting_state`: required object; must contain `map`, `players`, `fleets`, `currentPlayerId`, `status`, `roundNumber` (structural check only)
- `turn_number` must equal `games.turn_number`. If not: 409 `{ "message": "Stale submission — game state has advanced. Please reload." }`
- `round_number` must equal `games.round_number`. If not: 409 same message.

**Logic:**
1. Verify `games.current_user_id = me`. 403 if not.
2. Validate `turn_number` and `round_number` match (see above).
3. Validate `resulting_state` has the required top-level fields.
4. Determine the **next human player**:
   - Read `resulting_state.currentPlayerId` (e.g. `"player-1"`)
   - The client has already run all AI turns — `currentPlayerId` in the submitted state should be the next human player's engine ID (e.g. `"player-0"`, `"player-2"`, etc.)
   - Find the `game_players` row with `turn_order = {index extracted from currentPlayerId}` (parse the integer from `"player-N"`)
   - That row's `user_id` is the next human player's `user_id`
5. Update `games`:
   - `state_json = JSON(resulting_state)`
   - `current_user_id = next_human_user_id` (or NULL if the game format somehow has no next human — defensive case)
   - `turn_number = turn_number + 1`
   - `round_number = resulting_state.roundNumber`
   - If `resulting_state.status = 'finished'`:
     - `status = 'finished'`
     - Determine `winner_user_id`: find the non-eliminated player from `resulting_state.players` → look up their `user_id` from `game_players` via `turn_order` matching the player's ID index
6. Update `game_players.is_eliminated` for any player where `resulting_state.players[i].isEliminated = true`. Match by `turn_order = i`.
7. Upsert `turns` row: set `submitted_actions_json = JSON(actions)`, `resulting_state_json = JSON(resulting_state)`, `submitted_at = NOW()`, `in_progress_actions_json = NULL`, `started_at = COALESCE(started_at, NOW())`.
8. **Send push notifications:**
   - If `status = 'active'`: send "Your Turn!" to the next human player
   - If `status = 'finished'`: send "Victory!" to the winner and "Game Over" to all other human players

**Response (200):** `{ "success": true }`

---

#### `POST /api/games/{id}/turn/abandon`
Auth required. Must be the current player.

**Logic:** Find `turns` row for `(game_id, user_id, turn_number, round_number, submitted_at IS NULL)`. If found: set `in_progress_actions_json = NULL`.

**Response (200):** `{ "abandoned": true }`

---

### 4.6 Invites

#### `GET /api/invites`
Auth required.

**Logic:** `SELECT * FROM game_invites WHERE invitee_id = me AND status = 'pending'`. Join `games` and `users` (inviter).

**Response (200):**
```json
{
  "invites": [
    {
      "id": 3,
      "game": { "id": 1, "name": "The Final War" },
      "inviter": { "id": 1, "username": "commander_dan" },
      "player_count": 3,
      "created_at": "2026-05-29T12:00:00.000Z"
    }
  ]
}
```

---

#### `POST /api/invites/{id}/accept`
Auth required.

**Logic:**
1. Find invite: `id = {id} AND invitee_id = me AND status = 'pending'`. 404 if not found.
2. Set invite `status = 'accepted'`.
3. Check if ALL `game_invites` for this game are now accepted:
   ```sql
   SELECT COUNT(*) FROM game_invites
   WHERE game_id = {game_id} AND status != 'accepted'
   ```
   If count = 0: call `startGame($game)` (see Section 5).
4. Notify the game creator: `"Invite Accepted"` / `"{username} accepted your game invite"`.

**Response (200):**
```json
{ "accepted": true, "game_started": true }
```

---

#### `POST /api/invites/{id}/decline`
Auth required.

**Logic:**
1. Find invite: `id = {id} AND invitee_id = me AND status = 'pending'`. 404 if not found.
2. Set invite `status = 'declined'`.
3. Set `games.status = 'finished'` for the associated game (the game cannot proceed without all players).
4. Notify the creator: `"Invite Declined"` / `"{username} declined — the game has been cancelled"`.

**Response (200):** `{ "declined": true }`

---

## 5. Game Initialization (`startGame`)

When all invites are accepted (or when a game is created with only the creator as human player), the initial `GameState` must be generated. This requires running the TypeScript game engine, which must happen server-side.

### 5.1 Node.js CLI Script

Include a compiled Node.js CLI script in the backend repository at `engine/init-game.js`.

**What the script does:**
- Reads from stdin: a JSON object `{ mapConfig, playerSlots }`
- Runs `generateMap(mapConfig)` using the seeded RNG from `src/game/mapGenerator.ts`
- Runs `placeSpawns(map, playerSlots)` from `src/game/spawnPlacer.ts`
- Builds the initial `GameState` (matching the structure that `gameStore.startNewGame` creates in the client)
- Writes to stdout: the full initial `GameState` JSON

**The frontend repository must provide this compiled script.** The backend repository should include it (or reference where to get it). The Node.js runtime must be available on the server.

### 5.2 Laravel calls the CLI

```php
function startGame(Game $game): void {
    $mapConfig = json_decode($game->map_config_json, true);
    $playerSlots = GamePlayer::where('game_id', $game->id)
        ->orderBy('turn_order')
        ->get()
        ->map(fn($p) => [
            'type' => $p->is_ai ? 'ai' : 'human',
            'name' => $p->in_game_name,
            'difficulty' => $p->ai_difficulty,
        ])
        ->toArray();

    $input = json_encode(['mapConfig' => $mapConfig, 'playerSlots' => $playerSlots]);

    $result = Process::run("echo " . escapeshellarg($input) . " | node " . base_path('engine/init-game.js'));

    if ($result->failed()) {
        throw new \RuntimeException("Game engine failed: " . $result->errorOutput());
    }

    $initialState = json_decode($result->output(), true);

    // Determine the first human player's user_id
    $firstPlayerIndex = 0; // player-0 always goes first
    $firstPlayer = GamePlayer::where('game_id', $game->id)
        ->where('turn_order', $firstPlayerIndex)
        ->first();
    $firstHumanUserId = $firstPlayer->is_ai ? null : $firstPlayer->user_id;

    // Update game_players with home_planet_id
    // The initial state has planets with owner = 'player-N' and isHomePlanet = true
    foreach ($initialState['map']['planets'] as $planet) {
        if ($planet['isHomePlanet'] && $planet['owner'] !== 'neutral') {
            preg_match('/player-(\d+)/', $planet['owner'], $matches);
            $index = (int) $matches[1];
            GamePlayer::where('game_id', $game->id)
                ->where('turn_order', $index)
                ->update(['home_planet_id' => $planet['id']]);
        }
    }

    $game->update([
        'status' => 'active',
        'state_json' => json_encode($initialState),
        'current_user_id' => $firstHumanUserId,
        'turn_number' => 1,
        'round_number' => 1,
    ]);

    // Notify first player
    if ($firstHumanUserId) {
        $firstUser = User::find($firstHumanUserId);
        if ($firstUser->expo_push_token) {
            sendPushNotification($firstUser->expo_push_token, "Game Started!", "Your game '{$game->name}' has started — it's your first turn!", ['game_id' => $game->id, 'event' => 'game_started']);
        }
    }
}
```

---

## 6. Push Notifications

Use the **Expo Push API** directly from Laravel. No Firebase, no APNs setup needed — Expo handles that.

### 6.1 Helper Function

```php
function sendPushNotification(string $expoPushToken, string $title, string $body, array $data = []): void {
    if (empty($expoPushToken)) return;

    Http::post('https://exp.host/--/api/v2/push/send', [
        'to'    => $expoPushToken,
        'title' => $title,
        'body'  => $body,
        'data'  => $data,
        'sound' => 'default',
    ]);
}
```

For multiple recipients: pass an array of tokens to `to` (Expo supports batching up to 100 per request).

### 6.2 When to Send

| Event | Trigger | Recipients | Title | Body | Data |
|-------|---------|-----------|-------|------|------|
| Turn submitted | `POST /turn/submit` (active game) | Next human player | "Your Turn!" | "It's your turn in {game.name}" | `{ game_id, event: "your_turn" }` |
| Game started | `startGame()` | First human player | "Game Started!" | "'{game.name}' has started — it's your first turn!" | `{ game_id, event: "game_started" }` |
| Game invite | `POST /api/games` | Each invited user | "Game Invite" | "{creator_username} invited you to play" | `{ game_id, event: "invite_received" }` |
| Invite accepted | `POST /invites/{id}/accept` | Game creator | "Invite Accepted" | "{username} accepted your invite" | `{ game_id, event: "invite_accepted" }` |
| Invite declined | `POST /invites/{id}/decline` | Game creator | "Invite Declined" | "{username} declined — game cancelled" | `{ game_id, event: "game_cancelled" }` |
| Game finished (winner) | `POST /turn/submit` (finished game) | Winner user | "Victory!" | "You won '{game.name}'!" | `{ game_id, event: "game_finished" }` |
| Game finished (losers) | `POST /turn/submit` (finished game) | All other human players | "Game Over" | "'{game.name}' has ended" | `{ game_id, event: "game_finished" }` |

---

## 7. Laravel Project Setup

### 7.1 New Project
```bash
composer create-project laravel/laravel strategic-commander-api
cd strategic-commander-api
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### 7.2 Sanctum Configuration
In `config/sanctum.php`, set token expiration to `null` (tokens never expire — the app handles logout explicitly):
```php
'expiration' => null,
```

In `app/Http/Kernel.php` (or `bootstrap/app.php` for Laravel 11+), ensure Sanctum middleware is enabled for the `api` guard.

### 7.3 CORS
Enable CORS for mobile clients. In `config/cors.php`:
```php
'paths' => ['api/*'],
'allowed_origins' => ['*'],  // Tighten in production
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
```

### 7.4 API Routes
All routes go in `routes/api.php`. Group auth routes without middleware:

```php
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('push-token', [PushTokenController::class, 'update']);

    // Friends
    Route::get('friends', [FriendController::class, 'index']);
    Route::get('friends/requests', [FriendController::class, 'requests']);
    Route::post('friends/request', [FriendController::class, 'sendRequest']);
    Route::post('friends/requests/{friendship}/accept', [FriendController::class, 'acceptRequest']);
    Route::post('friends/requests/{friendship}/decline', [FriendController::class, 'declineRequest']);
    Route::delete('friends/{friendship}', [FriendController::class, 'removeFriend']);

    // User search
    Route::get('users/search', [UserController::class, 'search']);

    // Games
    Route::get('games', [GameController::class, 'index']);
    Route::post('games', [GameController::class, 'store']);
    Route::get('games/{game}', [GameController::class, 'show']);
    Route::delete('games/{game}', [GameController::class, 'destroy']);

    // Turns
    Route::post('games/{game}/turn/save', [TurnController::class, 'save']);
    Route::post('games/{game}/turn/submit', [TurnController::class, 'submit']);
    Route::post('games/{game}/turn/abandon', [TurnController::class, 'abandon']);

    // Invites
    Route::get('invites', [InviteController::class, 'index']);
    Route::post('invites/{invite}/accept', [InviteController::class, 'accept']);
    Route::post('invites/{invite}/decline', [InviteController::class, 'decline']);
});
```

### 7.5 Models
Create Eloquent models for: `User`, `Friendship`, `Game`, `GamePlayer`, `GameInvite`, `Turn`.

On the `User` model:
- Use `HasApiTokens` trait (from Sanctum)
- `$fillable = ['username', 'password', 'expo_push_token']`
- Cast `password` as hashed in `$casts`

### 7.6 Node.js Prerequisite
Node.js must be installed on the server. The `engine/init-game.js` script (built from the frontend repo's `src/game/` TypeScript) must be present in the backend root. See Section 5 for how Laravel calls it.

---

## 8. Key Business Rules

1. **Pass-and-play is client-only.** No backend endpoints handle pass-and-play games. The `play_mode` column only stores `async_multiplayer`.

2. **The client computes all game state.** The backend does NOT re-run the game engine on turn submission. It validates identity, turn numbers, and state structure only. The submitted `resulting_state` is stored as-is.

3. **AI turns are resolved client-side.** The client runs AI turns to completion before submitting. The `resulting_state` contains the fully-advanced state, with `currentPlayerId` pointing to the next human.

4. **Persistent login.** Sanctum tokens do not expire. The client stores the token in AsyncStorage and uses it indefinitely. The only way to invalidate a session is explicit logout.

5. **Friends required for game invites.** You cannot invite a user to a game unless they are an accepted friend.

6. **Username-only registration.** No email. No password reset mechanism (admin reset only).

7. **Turn privacy.** `in_progress_actions` (mid-turn save) is ONLY returned to the player whose turn it is. Never expose it to other players.

8. **Game init runs server-side.** The initial `GameState` is generated by the Node.js engine CLI when a game starts. This ensures all clients get the same deterministic map.

9. **Invite decline cancels the game.** If any invited player declines, the game moves to `finished` status and cannot proceed. The creator is notified.

10. **`state_json` is a full GameState.** It must be parseable by the client's TypeScript code as a `GameState` object. The server treats it as an opaque string — never mutates individual fields within it.

---

## 9. Testing Checklist

After building the backend, verify these scenarios work end-to-end:

- [ ] Register two users; verify usernames are unique
- [ ] Login returns a token; re-login returns a new token
- [ ] Calling `GET /api/auth/me` with a valid token returns user info; with no token returns 401
- [ ] Send friend request between two users; verify pending state
- [ ] Accept friend request; verify accepted state on both users' `GET /api/friends`
- [ ] Try to invite a non-friend to a game; verify 422 error
- [ ] Create a game with one invited friend; verify invite appears in friend's `GET /api/invites`
- [ ] Friend accepts invite; verify game starts (state_json populated, status = active)
- [ ] Creator fetches `GET /api/games/{id}`; state_json returned; no in_progress_actions (fresh turn)
- [ ] Creator submits a mid-turn save; fetches game again; in_progress_actions returned
- [ ] Other player fetches same game; verifies in_progress_actions NOT returned (privacy)
- [ ] Submit a completed turn; verify state advances, turn_number increments, push notification sent (check logs)
- [ ] Friend declines invite; verify game cancelled; creator notified

---

## 10. File / Controller Structure Suggestion

```
app/
  Http/
    Controllers/
      AuthController.php
      FriendController.php
      GameController.php
      InviteController.php
      PushTokenController.php
      TurnController.php
      UserController.php
  Models/
    User.php
    Friendship.php
    Game.php
    GamePlayer.php
    GameInvite.php
    Turn.php
  Services/
    GameService.php    — startGame(), computeAlertState(), sendPushNotification()
    FriendService.php  — getFriendshipStatus()
engine/
  init-game.js        — compiled Node.js game initialization CLI
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

*End of backend build instructions.*
