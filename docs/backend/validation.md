# Validation

Use Laravel Form Requests for all validation. This keeps controllers thin and makes rules testable in isolation.

---

## Auth

### `POST /api/auth/register`

| Field | Rules |
|-------|-------|
| `username` | required \| string \| min:3 \| max:30 \| regex:`/^[a-zA-Z0-9_]+$/` \| unique:users,username |
| `password` | required \| string \| min:6 \| confirmed |

### `POST /api/auth/login`

| Field | Rules |
|-------|-------|
| `username` | required \| string |
| `password` | required \| string |

---

## Push Token

### `POST /api/push-token`

| Field | Rules |
|-------|-------|
| `token` | required \| string \| starts_with:ExponentPushToken |

---

## Friends

### `POST /api/friends/request`

| Field | Rules |
|-------|-------|
| `username` | required \| string \| exists:users,username |

**Additional checks (in controller/service, not Form Request):**
- Target user is not the authenticated user → `422`
- A friendship row already exists in either direction (any status) → `422 "Friend request already exists or you are already friends"`

---

## Games

### `POST /api/games`

| Field | Rules |
|-------|-------|
| `name` | required \| string \| max:100 |
| `map_config` | required \| array |
| `map_config.mapSize` | required \| in:small,medium,large |
| `map_config.mapWidth` | required \| integer \| min:1 |
| `map_config.mapHeight` | required \| integer \| min:1 |
| `map_config.planetCount` | required \| integer \| min:2 \| max:100 |
| `map_config.seed` | required \| integer |
| `map_config.galaxyShape` | required \| in:scattered,dense_core,ring,cluster,spiral,crescent,binary,ribbon,halo,broken_ring,crossroads,clover |
| `player_slots` | required \| array \| min:2 \| max:8 |
| `player_slots.*.type` | required \| in:human,ai |
| `player_slots.*.name` | required \| string \| max:50 |
| `player_slots.*.user_id` | nullable \| integer \| exists:users,id (for human type) |
| `player_slots.*.difficulty` | required_if:type,ai \| in:easy,normal,hard |

**Additional checks (in service):**
- Invited human `user_id` values must be accepted friends of the creator → `422` if any are not
- Open human seats (`user_id` null after slot 0) skip the friend check and skip `startGame`
- Mixing invited friend IDs and open seats in one request → `422`

### `POST /api/games/{id}/start`

| Field | Rules |
|-------|-------|
| `state_json` | required \| string |

Lobby must be `waiting_for_players`, every human seat filled, caller a member.

---

## Turns

### `POST /api/games/{id}/turn/save`

| Field | Rules |
|-------|-------|
| `in_progress_actions` | required \| array |
| `in_progress_actions.partial_state_json` | required \| string |
| `in_progress_actions.queued_orders` | present \| array |

**Authorization check:** `games.current_user_id = me` → `403` if not.

### `POST /api/games/{id}/turn/submit`

| Field | Rules |
|-------|-------|
| `actions` | required \| array |
| `resulting_state` | required \| array |
| `resulting_state.map` | required \| array |
| `resulting_state.players` | required \| array |
| `resulting_state.fleets` | required \| array |
| `resulting_state.currentPlayerId` | required \| string |
| `resulting_state.status` | required \| string \| in:active,finished |
| `resulting_state.roundNumber` | required \| integer \| min:1 |
| `turn_number` | required \| integer |
| `round_number` | required \| integer |

**Additional checks (in controller):**
- `games.current_user_id = me` → `403`
- `turn_number = games.turn_number` → `409 "Stale submission — game state has advanced. Please reload."`
- `round_number = games.round_number` → `409` same message

---

## Validation Principles

- Use Form Requests (`php artisan make:request`) for all input validation.
- Authorization checks that require database queries belong in the controller or a service, not `authorize()`.
- Return `422` for validation failures, `403` for authorization failures, `409` for state conflicts.
- Never trust client-submitted game rule correctness — only validate structure and identity.
