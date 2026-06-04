# Game State

## Overview

`GameState` is the authoritative representation of a game's current situation. It is a TypeScript object defined in the frontend codebase and shared across client and server as a JSON blob.

The backend treats `state_json` as an **opaque string** — it stores and returns it as-is, never mutates individual fields within it, and only reads top-level fields during turn submission for validation and pointer advancement.

---

## Storage

`state_json` lives on the `games` table as a `LONGTEXT` column. It is:

- Empty string (`''`) while the game is in `waiting` status
- A full serialized `GameState` JSON string once the game transitions to `active` (set by `startGame()`)
- Updated atomically on each turn submission (replaced in full, never partially patched)

---

## GameState Top-Level Fields (Required for Validation)

When a client submits a turn, the backend checks that `resulting_state` contains these fields:

| Field | Type | Notes |
|-------|------|-------|
| `map` | object | Contains `planets` array |
| `players` | array | One entry per player slot |
| `fleets` | array | In-flight fleets |
| `currentPlayerId` | string | e.g. `"player-0"`, `"player-2"` — the next player to act |
| `status` | string | `"active"` or `"finished"` |
| `roundNumber` | number | Current round |

The backend does not validate game rule correctness — only structure.

---

## `currentPlayerId` Convention

- Format: `"player-{N}"` where N is the 0-based `turn_order` index
- The client submits a state where `currentPlayerId` is the **next human player** (all AI turns have been resolved client-side)
- The backend extracts `N` via regex `player-(\d+)` and looks up the corresponding `game_players` row to set `games.current_user_id`

---

## `players` Array

Each entry in `resulting_state.players` corresponds to `game_players.turn_order = i` (0-indexed). The backend reads `isEliminated` from each entry to update `game_players.is_eliminated`.

---

## `map.planets` Array

Used only during `startGame()` to extract `home_planet_id` for each player:

```json
{
  "id": "p-3",
  "owner": "player-1",
  "isHomePlanet": true,
  ...
}
```

The backend matches `owner = "player-N"` to `game_players.turn_order = N` and sets `home_planet_id = planet.id`.

---

## `state_json` in API Responses

- Returned as a JSON string (not parsed) in `GET /api/games/{id}`
- **Never returned** in `GET /api/games` (list endpoint)
- The client parses it on receipt as a `GameState` TypeScript object

---

## `in_progress_actions_json` Shape

Stored in `turns.in_progress_actions_json`. Returned (when appropriate) as `in_progress_actions` in `GET /api/games/{id}`.

```json
{
  "partial_state_json": "{...full current GameState as JSON string...}",
  "queued_orders": [
    { "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }
  ]
}
```

`partial_state_json` is the full `GameState` at the moment the player exited mid-turn — it includes all mutations from this turn (builds, slider changes) but fleet orders in `queued_orders` have not yet been applied to the state.

---

## `map_config_json` Shape

Stored on `games.map_config_json`. Passed to the Node.js engine at game start.

```json
{
  "mapSize": "medium",
  "mapWidth": 286,
  "mapHeight": 286,
  "planetCount": 30,
  "seed": 1748556123456,
  "galaxyShape": "scattered"
}
```

Valid `mapSize` values: `small` | `medium` | `large`
Valid `galaxyShape` values: `scattered` | `dense_core` | `ring` | `cluster` | `spiral`
