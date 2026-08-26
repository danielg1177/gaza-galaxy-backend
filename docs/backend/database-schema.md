# Database Schema

MySQL 8+. Run migrations in the order listed below.

---

## `users`

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

| Column | Notes |
|--------|-------|
| `username` | Alphanumeric + underscores only, 3–30 chars. Validated at application layer. Case-sensitive. |
| `password` | bcrypt via `Hash::make()`. Never returned in responses. |
| `expo_push_token` | Format `ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]`. NULL if user has not granted notification permissions. |

---

## `personal_access_tokens` (Sanctum)

Created automatically by Sanctum migration. No modifications needed.

---

## `friendships`

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

| Column | Notes |
|--------|-------|
| `requester_id` | User who sent the friend request. |
| `addressee_id` | User who received it. |
| `status` | `pending` → `accepted` or `declined`. Declined rows are retained (not deleted). |

**Important:** The constraint is directional. To check if two users are friends, query both `(user_a, user_b)` and `(user_b, user_a)`. Only one row per ordered pair.

---

## `games`

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

| Column | Notes |
|--------|-------|
| `status` | `waiting` = pending invites; `active` = in progress; `finished` = ended (win, player-ended via `POST /games/{id}/end`, or cancellation). Player-ended games keep `winner_user_id` null. |
| `play_mode` | Only `async_multiplayer` — pass-and-play is local-only. |
| `map_config_json` | `{ "mapSize": "medium", "mapWidth": 286, "mapHeight": 286, "planetCount": 30, "seed": 1748556123456, "galaxyShape": "scattered" }` |
| `current_user_id` | The human player whose turn it is. NULL only for AI turns, but AI turns are resolved client-side and should never persist. |
| `state_json` | Full serialized `GameState` TypeScript object. Empty string until game starts. Treated as opaque by the backend. |
| `turn_number` | Increments by 1 each time any player submits a turn. |
| `round_number` | Mirrors `GameState.roundNumber`. Increments when all players complete a full cycle. |

---

## `game_players`

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
  is_forfeited TINYINT(1) NOT NULL DEFAULT 0,
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

| Column | Notes |
|--------|-------|
| `turn_order` | 0-based index matching `GameState.players[N]`. `player-0`, `player-1`, etc. |
| `user_id` | NULL for AI players. |
| `home_planet_id` | Format `"p-{index}"` (e.g. `"p-0"`). Set when the game starts via `startGame()`. |
| `is_eliminated` | Updated during turn submission when `resulting_state.players[i].isEliminated = true`. |
| `is_forfeited` | Human is sitting out; AI plays their turns until they rejoin. Does not change `is_ai`. Set by `POST /games/{id}/forfeit`, cleared by `POST /games/{id}/rejoin`. |

---

## `game_invites`

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

| Column | Notes |
|--------|-------|
| `player_slot_index` | Which `game_players.turn_order` slot this invite fills. The `game_players` row is created at game creation time; accepting an invite does not create a new row. |
| `status` | Declining sets `games.status = 'finished'` — the game is cancelled. |

---

## `turns`

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
  events_json LONGTEXT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_turn (game_id, user_id, turn_number, round_number),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_game_turn (game_id, turn_number),
  INDEX idx_user_game (user_id, game_id)
);
```

| Column | Notes |
|--------|-------|
| `submitted_actions_json` | Final `PlayerAction[]` array from the client. Set on submit. |
| `in_progress_actions_json` | Mid-turn save blob: `{ "partial_state_json": "...", "queued_orders": [...] }`. Cleared to NULL on submit. |
| `resulting_state_json` | Full `GameState` after turn resolution. Set on submit. |
| `events_json` | Client-computed `TurnEvent[]` for the submitted turn. Set on submit when the client sends `events`; otherwise NULL. |
| `submitted_at` | NULL while turn is in progress. Set on submit. |

A row may be created on first mid-turn save and updated on submit. Use upsert logic.


### `submitted_actions_json` shape

```json
[
  { "type": "SEND_FLEET", "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 },
  { "type": "BUILD", "planetId": "p-3", "buildingType": "factory" },
  { "type": "SET_PRODUCTION_SLIDER", "planetId": "p-3", "value": 0.7 },
  { "type": "END_TURN" }
]
```

### `in_progress_actions_json` shape

```json
{
  "partial_state_json": "{...full current GameState as JSON string...}",
  "queued_orders": [
    { "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }
  ]
}
```

---

## 2026-08-26 — Account deletion and chat moderation

Live FKs (migrations `2026_08_26_100000` / `100001`):

- `games.created_by_user_id` is nullable, `ON DELETE SET NULL`
- `turns.user_id` is nullable, `ON DELETE SET NULL`
- `game_messages.sender_user_id` is nullable, `ON DELETE SET NULL` (no longer cascade-deletes chat)
- `game_messages.hidden_at` TIMESTAMP NULL — hidden messages are omitted from GET / unread

`message_reports` stores report evidence (content snapshot, reporter, reported user, status `open|actioned|dismissed`). Unique `(reporter_user_id, message_id)`.
