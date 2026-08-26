# API Contract

All routes are prefixed with `/api/`. Responses are JSON. Auth routes are public; all others require `Authorization: Bearer {token}`.

## Error Envelope

```json
{ "message": "Human-readable error", "errors": { "field": ["validation message"] } }
```

## HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | OK |
| 201 | Created |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 409 | Conflict (stale turn, duplicate state) |
| 422 | Unprocessable Entity (validation failure) |

---

## Authentication

### `POST /api/auth/register`
Public.

**Request:**
```json
{ "username": "string", "password": "string", "password_confirmation": "string" }
```

**Response 201:**
```json
{ "user": { "id": 1, "username": "commander_dan" }, "token": "1|abc123..." }
```

---

### `POST /api/auth/login`
Public.

**Request:**
```json
{ "username": "string", "password": "string" }
```

**Response 200:**
```json
{ "user": { "id": 1, "username": "commander_dan" }, "token": "2|xyz789..." }
```

**Response 401:**
```json
{ "message": "Invalid credentials" }
```

---

### `POST /api/auth/logout`
Auth required.

**Response 200:**
```json
{ "message": "Logged out" }
```

---

### `GET /api/auth/me`
Auth required.

**Response 200:**
```json
{ "id": 1, "username": "commander_dan" }
```

---

### `PATCH /api/auth/username`
Auth required.

**Request:**
```json
{ "username": "string" }
```

**Validation:** `username` required | string | min:3 | max:32 | regex:`/^[a-zA-Z0-9_]+$/` | unique in `users` except the current user.

**Response 200:**
```json
{ "id": 1, "username": "new_name" }
```

Does not rewrite `game_players` commander names or `state_json` player names.

---

### `PATCH /api/auth/password`
Auth required.

**Request:**
```json
{ "current_password": "string", "password": "string", "password_confirmation": "string" }
```

**Validation:** `current_password` required; `password` min 6, confirmed.

**Response 200:**
```json
{ "message": "Password updated" }
```

**Response 422:**
```json
{ "message": "Current password is incorrect", "errors": { "current_password": ["Current password is incorrect"] } }
```

Wrong current password is 422, not 401. Current Sanctum token stays valid.

---

### `DELETE /api/auth/account`
Auth required.

**Request:**
```json
{ "current_password": "string" }
```

**Validation:** `current_password` required.

**Logic:** Verify password (422 if wrong). Permanently leave live games (forfeit + anonymize commander as "Former Commander"; skip the action phase if it is their turn; finish the match if they are the last playing human). Cancel waiting lobbies they host. Release open-lobby seats they joined. Decline pending invites they received (cancels those games). Transfer `created_by_user_id` to the next remaining human. Revoke all Sanctum tokens. Delete the user row.

**Response 200:**
```json
{ "message": "Account deleted" }
```

**Response 422:**
```json
{ "message": "Current password is incorrect", "errors": { "current_password": ["Current password is incorrect"] } }
```

---

## Push Token

### `POST /api/push-token`
Auth required.

**Request:**
```json
{ "token": "ExponentPushToken[...]" }
```

**Response 200:**
```json
{ "saved": true }
```

---

## Friends

### `GET /api/friends`
Auth required. Returns accepted friends.

**Response 200:**
```json
{
  "friends": [
    { "friendship_id": 3, "user": { "id": 5, "username": "battlestar" } }
  ]
}
```

---

### `GET /api/friends/requests`
Auth required. Returns incoming pending friend requests.

**Response 200:**
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

### `POST /api/friends/request`
Auth required.

**Request:**
```json
{ "username": "string" }
```

**Response 201:**
```json
{ "friendship_id": 8, "status": "pending" }
```

**Errors:**
- `404` — user not found
- `422` — targeting yourself, or friendship already exists (any status, either direction)

---

### `POST /api/friends/requests/{friendship_id}/accept`
Auth required. Must be the addressee of the pending request.

**Response 200:**
```json
{ "friendship_id": 8, "status": "accepted" }
```

---

### `POST /api/friends/requests/{friendship_id}/decline`
Auth required. Must be the addressee of the pending request.

**Response 200:**
```json
{ "message": "Declined" }
```

---

### `DELETE /api/friends/{friendship_id}`
Auth required. Must be either party in the friendship. Cannot be used to remove a `blocked` row.

**Response 200:**
```json
{ "message": "Friend removed" }
```

---

### `GET /api/friends/blocked`
Auth required. Users the caller blocked (`requester_id = me`, `status = blocked`).

**Response 200:**
```json
{
  "blocked": [
    { "friendship_id": 9, "user": { "id": 4, "username": "warlord99" } }
  ]
}
```

---

### `POST /api/friends/block`
Auth required.

**Request:**
```json
{ "user_id": 4 }
```

**Logic:** Delete pending/accepted rows between the pair. Insert `(me, user_id, blocked)`. Does not delete a block the other user already placed. Cancels pending game invites either direction (same as decline: waiting friend-invite games finish). Shared in-progress games continue. Chat between the pair is hidden.

**Response 200:**
```json
{ "friendship_id": 9, "status": "blocked" }
```

---

### `DELETE /api/friends/blocked/{friendship_id}`
Auth required. Must be the blocker (`requester_id = me`, `status = blocked`).

**Response 200:**
```json
{ "message": "Unblocked" }
```

---

## User Search

### `GET /api/users/search?q={term}`
Auth required. Min 1 char. Returns up to 20 users.

**Response 200:**
```json
{
  "users": [
    { "id": 4, "username": "warlord99", "friendship_status": "none" },
    { "id": 5, "username": "nova", "friendship_status": "accepted" }
  ]
}
```

`friendship_status` values: `none` | `pending_sent` | `pending_received` | `accepted`

Blocked users (either direction) are omitted from results. A friend request to a blocked user returns `404`.

---

## Games

### `GET /api/games`
Auth required. Returns all games the authenticated user is a player in. No `state_json` in list view.

**Response 200:**
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
        { "in_game_name": "Dan", "is_ai": false, "is_eliminated": false, "is_forfeited": false, "user_id": 1 },
        { "in_game_name": "Nova", "is_ai": false, "is_eliminated": false, "is_forfeited": false, "user_id": 5 },
        { "in_game_name": "Zorg", "is_ai": true, "is_eliminated": false, "is_forfeited": false, "user_id": null }
      ],
      "current_player_name": "Dan",
      "round_number": 3,
      "turn_number": 12,
      "created_at": "2026-05-29T12:00:00.000Z"
    }
  ]
}
```

`alert_state` values: `waiting_for_players` | `waiting` | `your_turn` | `in_progress` | `finished`

Each player object includes `is_forfeited`. For a sitting-out member, `is_my_turn` is `false` and `alert_state` is `waiting` even if `current_user_id` still points at them.

`is_open_lobby` is `true` when `status` is `waiting_for_players` and `state_json` is empty (matchmaking lobby). Friend-invite games populate `state_json` at create, so they are not open lobbies. Command Center should hide open lobbies; they belong on Find Game → Pending.

`map_config` is the decoded `map_config_json` object.

---

### `POST /api/games`
Auth required.

**Request:**
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

`user_id: null` in the first human slot means the authenticated creator fills it. All other human `user_id` values must be accepted friends of the creator.

**Open lobby (matchmaking):** extra human slots may omit `user_id` (or send `null`). Do not mix invited friend IDs and open seats in one request (`422`). Do not send `state_json` — the game stays `waiting_for_players` until `POST /games/{id}/start`. No `game_invites` rows are created for open seats.

**Invite path:** every extra human has a friend `user_id`. Client sends `state_json`; `startGame()` runs immediately (existing creator-first behavior).

**Response 201:**
```json
{ "game": { "id": 1, "name": "The Final War", "status": "in_progress" }, "state_json": "{...full GameState JSON string...}", "invites_sent": [5] }
```

Open lobby 201 keeps `"status": "waiting_for_players"`, `"is_open_lobby": true`, and `"state_json": null`.

---

### `GET /api/games/{id}`
Auth required. User must be a member (`game_players`).

**Response 200:**
```json
{
  "game": {
    "id": 1,
    "name": "The Final War",
    "status": "active",
    "play_mode": "async_multiplayer",
    "round_number": 3,
    "turn_number": 12,
    "players": [
      { "in_game_name": "Dan", "is_ai": false, "is_eliminated": false, "is_forfeited": false, "user_id": 1 },
      { "in_game_name": "Nova", "is_ai": false, "is_eliminated": false, "is_forfeited": false, "user_id": 5 }
    ]
  },
  "state_json": "{\"map\":{...},\"players\":[...],\"fleets\":[...],\"currentPlayerId\":\"player-0\",...}",
  "is_my_turn": true,
  "alert_state": "in_progress",
  "in_progress_actions": {
    "partial_state_json": "{...full current GameState...}",
    "queued_orders": [{ "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }]
  },
  "latest_events": [
    { "type": "combat", "planetId": "p-7", "attackerId": "player-0", "defenderId": "player-1" }
  ],
  "final_state_json": null
}
```

`in_progress_actions` is `null` (or absent) when there is no mid-turn save, and is **never returned to a player who is not the current player** (see `privacy-rules.md`).

`latest_events` is an array of `TurnEvent` objects from the most recently submitted turn for this game (the `turns` row with non-null `resulting_state_json`, highest `turn_number` then `round_number`). Empty array `[]` when no submitted turn exists or that turn has no stored events.

`final_state_json` is the decoded `GameState` from the last submitted turn (`resulting_state_json` on the `turns` row with highest `turn_number` then `round_number`) when `game.status` is `finished`; `null` for in-progress or waiting games, or when no submitted turn exists.

`game.is_open_lobby` and `game.map_config` are included so a member can start a full matchmaking lobby via `POST /games/{id}/start` if the last joiner dropped.

---

### `GET /api/games/open`
Auth required. Public waiting matchmaking lobbies the caller is **not** already in. Never returns `state_json`.

**Response 200:**
```json
{
  "count": 1,
  "games": [
    {
      "id": 12,
      "name": "Dan's Campaign",
      "status": "waiting_for_players",
      "host": { "id": 1, "username": "commander_dan" },
      "map_config": { "mapSize": "medium", "mapWidth": 286, "mapHeight": 286, "planetCount": 30, "seed": 1748556123456 },
      "human_filled": 1,
      "human_total": 3,
      "ai_count": 1,
      "is_open_lobby": true,
      "players": [
        { "in_game_name": "Dan", "is_ai": false, "user_id": 1 },
        { "in_game_name": "Open", "is_ai": false, "user_id": null }
      ],
      "created_at": "2026-08-25T12:00:00.000Z"
    }
  ]
}
```

Seat counts include the creator. A fresh 3-human lobby is always `1/3`.

---

### `POST /api/games/{id}/join`
Auth required. Claims the next empty human seat (`is_ai = false`, `user_id` null) on an open lobby. Friendship is not required. Row-locked to prevent double-fill.

**Response 200:**
```json
{ "joined": true, "should_start": false, "game": { "...same shape as GET /games/open card..." } }
```

`should_start` is `true` when this join filled the last human seat. The client then generates `state_json` and calls `POST /games/{id}/start`.

**Errors:**
- `422` — not an open lobby, already a member, or game already started
- `409` — game is full

---

### `POST /api/games/{id}/leave`
Auth required. Releases your waiting matchmaking seat. Creator must delete instead.

**Response 200:** `{ "left": true, "game": { "...open lobby card..." } }`

**Errors:**
- `403` — not a member
- `422` — creator, or game already started

---

### `POST /api/games/{id}/start`
Auth required. Member of a full matchmaking lobby (`waiting_for_players`, every human `user_id` filled, empty `state_json`).

**Request:**
```json
{ "state_json": "{...full GameState JSON string...}" }
```

Runs `startGame()`. Notifies the first human (host) that it is their turn; notifies every other human that the game started. Idempotent if already `in_progress`.

**Response 200:**
```json
{ "started": true, "game": { "...list-shaped game...", "is_my_turn": true }, "state_json": "{...}" }
```

**Errors:**
- `403` — not a member
- `422` — lobby not full, or game cannot be started

---

### `PATCH /api/games/{id}`
Auth required. Must be the game creator.

**Request:**
```json
{ "name": "string" }
```

**Response 200:**
```json
{ "game": { "id": 1, "name": "Updated Game Name" } }
```

**Errors:**
- `403` — not the creator
- `422` — validation failure (name empty or too long)

---

### `DELETE /api/games/{id}`
Auth required. Must be the game creator. Can be deleted regardless of game status.

**Response 200:**
```json
{ "message": "Game deleted" }
```

---

### `POST /api/games/{id}/forfeit`
Auth required. User must be a human member of an in-progress game, not eliminated, and not already sitting out.

Sets `game_players.is_forfeited = true`. Does not advance the turn pointer — the client must still submit an AI-resolved turn if it is currently that player's turn. Clears any unsubmitted mid-turn save for the caller.

**Response 200:**
```json
{ "forfeited": true }
```

**Errors:**
- `403` — not a member
- `422` — game not in progress, already sitting out, eliminated, or AI slot

---

### `POST /api/games/{id}/rejoin`
Auth required. User must be a human member who is currently sitting out and not eliminated.

Clears `is_forfeited`. Does not interrupt another player's in-progress turn; the rejoining player takes over the next time the turn order reaches them.

**Response 200:**
```json
{ "rejoined": true }
```

**Errors:**
- `403` — not a member
- `422` — game not in progress, not sitting out, or eliminated

---

### `POST /api/games/{id}/end`
Auth required. Any human member of an in-progress game (not just the creator). Distinct from forfeit (sit-out) and from delete (creator-only row removal).

Sets `games.status = 'finished'`, `current_user_id = null`, and `winner_user_id = null`. Patches `state_json` so `status` is `"finished"` and `winnerId` is `null`. Notifies all other human players.

**Response 200:**
```json
{ "ended": true }
```

**Errors:**
- `403` — not a member
- `422` — game not in progress, or caller is an AI slot

---

## Turns

### `POST /api/games/{id}/turn/save`
Auth required. Must be the current player (`games.current_user_id = me`).

**Request:**
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

`partial_state_json` is the full `GameState` at the moment of exit including all mutations from this turn. `queued_orders` are staged fleet dispatches not yet committed.

**Response 200:**
```json
{ "saved": true }
```

---

### `POST /api/games/{id}/turn/submit`
Auth required. Must be the current player.

**Request:**
```json
{
  "actions": [
    { "type": "SEND_FLEET", "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 },
    { "type": "BUILD", "planetId": "p-0", "buildingType": "factory" },
    { "type": "SET_PRODUCTION_SLIDER", "planetId": "p-0", "value": 0.7 },
    { "type": "END_TURN" }
  ],
  "resulting_state": { "...full GameState object..." },
  "turn_number": 12,
  "round_number": 3
}
```

`turn_number` and `round_number` must match `games.turn_number` / `games.round_number`. If not: `409 { "message": "Stale submission — game state has advanced. Please reload." }`.

If `resulting_state.currentPlayerId` maps to a created AI (`is_ai` and no `user_id`) or a sitting-out human (`is_forfeited`): `422 { "message": "Turn must advance to a playing human before submitting" }` (AI slots use the existing human-player message).

`is_my_turn` is false and `alert_state` is `waiting` for a sitting-out member even if `games.current_user_id` still points at them.

**Response 200:**
```json
{ "success": true }
```

---

### `POST /api/games/{id}/turn/abandon`
Auth required. Must be the current player.

Clears any mid-turn save without ending the turn.

**Response 200:**
```json
{ "abandoned": true }
```

---

## Invites

### `GET /api/invites`
Auth required. Returns pending game invites addressed to the authenticated user.

**Response 200:**
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

### `POST /api/invites/{id}/accept`
Auth required. Must be the invitee.

If the game was waiting for this player's turn, the game transitions back to `in_progress` and a "your turn" notification is sent to the accepter.

**Response 200:**
```json
{ "accepted": true, "game_started": true }
```

---

### `POST /api/invites/{id}/decline`
Auth required. Must be the invitee.

Declining cancels the entire game — `games.status` is set to `finished` and the creator is notified.

**Response 200:**
```json
{ "declined": true }
```

---

## Messages

### `GET /api/games/{id}/messages`
Auth required. Members only. Omits `hidden_at` messages and messages from users in a block with the caller. Marks the caller's last-read id.

**Response 200:**
```json
{
  "messages": [
    { "id": 1, "senderUserId": 5, "senderName": "Nova", "content": "gg", "createdAt": "2026-08-26T12:00:00.000Z" }
  ]
}
```

`senderUserId` is `null` when the sender's account was deleted (`senderName` is then `"Former Commander"`).

---

### `POST /api/games/{id}/messages`
Auth required. Members only. `content` max 500. `422` if every other remaining human is in a block with the sender. Push is not sent to blocked counterparts.

---

### `POST /api/games/{id}/messages/{message}/report`
Auth required. Members only. Cannot report your own message.

**Request (optional):**
```json
{ "reason": "string" }
```

Stores a `message_reports` row (content snapshot) and emails `MODERATION_EMAIL`. Duplicate reports return `200 { "reported": true }` without a second email.

**Response 201:**
```json
{ "reported": true }
```

Moderation commands: `php artisan moderation:hide-message {id}` and `php artisan moderation:delete-account {id} --force`.
