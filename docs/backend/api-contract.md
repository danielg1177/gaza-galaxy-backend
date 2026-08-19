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
Auth required. Must be either party in the friendship.

**Response 200:**
```json
{ "message": "Friend removed" }
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

**Response 201:**
```json
{ "game": { "id": 1, "name": "The Final War", "status": "in_progress" }, "state_json": "{...full GameState JSON string...}", "invites_sent": [5] }
```

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
