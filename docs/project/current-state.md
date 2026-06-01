# Current State

_Last updated: 2026-05-31_

---

## What Works

- Laravel project scaffolded and running
- MySQL database connected
- `.env` configured
- Sanctum configured (`statefulApi()` in `bootstrap/app.php`, tokens never expire)
- CORS configured (`config/cors.php` — all origins/methods/headers, `supports_credentials: true`)
- All 7 database migrations run: `users`, `friendships`, `games`, `game_players` (+ `name` column), `game_invites`, `turns`, `personal_access_tokens`
- All 6 Eloquent models with correct `$fillable`, `$casts`, and relationships
- `NotificationService` — fire-and-forget Expo push HTTP, wired into all relevant controllers
- `GameService` — `startGame()` engine bridge via `Process::run(['node', 'engine/init-game.js', ...])`
- All API endpoints implemented and matching `docs/backend/api-contract.md`

## What Is Partially Built

Nothing.

## What Is Not Started

- `engine/init-game.js` — must be compiled from the frontend repo and placed at `{backend}/engine/init-game.js`

## Current API Endpoints

All routes are under `/api/`. Public routes have no middleware. All others require `auth:sanctum`.

**Auth (public)**
- `POST /auth/register` — 201 `{ user, token }`
- `POST /auth/login` — 200 `{ user, token }`

**Auth (protected)**
- `POST /auth/logout` — 200 `{ message }`
- `GET /auth/me` — 200 `{ id, username }`

**Push token**
- `POST /push-token` — 200 `{ saved: true }` — field: `token`

**Friends**
- `GET /friends` — `{ friends: [{ friendship_id, user }] }`
- `GET /friends/requests` — `{ requests: [{ friendship_id, from_user, created_at }] }`
- `POST /friends/request` — 201 `{ friendship_id, status }` — field: `username`
- `POST /friends/requests/{friendship}/accept` — 200 `{ friendship_id, status }`
- `POST /friends/requests/{friendship}/decline` — 200 `{ message }`
- `DELETE /friends/{friendship}` — 200 `{ message }`

**Users**
- `GET /users/search?q=` — `{ users: [{ id, username, friendship_status }] }`

**Games**
- `GET /games` — `{ games: [{ id, name, status, play_mode, alert_state, is_my_turn, has_in_progress_actions, winner_user_id, players, current_player_name, round_number, turn_number, created_at }] }`
- `POST /games` — 201 — fields: `name`, `map_config` (object), `player_slots` (with `name`, `type`, `user_id`)
- `GET /games/{game}` — members only (403 otherwise) — `{ game, state_json, is_my_turn, alert_state, in_progress_actions, latest_events }` — `latest_events` is the decoded `events_json` from the most recently submitted turn, or `[]`
- `DELETE /games/{game}` — creator only, any status — 200 `{ message }`

**Turns**
- `POST /games/{game}/turn/save` — current player only — field: `in_progress_actions` (object)
- `POST /games/{game}/turn/submit` — current player only — fields: `actions` (array), `resulting_state` (object), `turn_number`, `round_number`, optional `events` (array, stored in `turns.events_json`)
- `POST /games/{game}/turn/abandon` — current player only — clears mid-turn save only

**Invites**
- `GET /invites` — pending invites where invitee = me
- `POST /invites/{invite}/accept` — invitee only
- `POST /invites/{invite}/decline` — invitee only, cancels game

## Current Database Tables

`users`, `friendships`, `games`, `game_players`, `game_invites`, `turns`, `personal_access_tokens`, `cache`, `jobs`, `migrations`

## Current Game-Engine Status

`engine/init-game.js` does not exist. `GameService::startGame()` is implemented and will call it via `Process::run()`, but will throw a `RuntimeException` (caught as 500) until the script is present.

## Current Blockers

1. **Engine script not present** — `engine/init-game.js` must be built from `src/game/` in the frontend repo and placed at `{backend}/engine/init-game.js`.

## Known Bugs

_None._

### ~~`TurnController::submit()` — `games.turn_number` out of sync when AI players are present~~ (resolved 2026-05-31)

`submit()` advances `games.turn_number` by `+1` per human submit. But the frontend's `GameState.turnNumber` increments by `+1` per `resolveTurn` call (human + every AI player). The stored `state_json` after a submit has `turnNumber = previous + 1 + N_ai`. The next human sends that value as `turn_number`; the backend has `previous + 1` → 409 Stale turn data → "Submit Failed" on every turn in games with AI players.

**Fix: Task 161** — advance `games.turn_number` to `$request->resulting_state['turnNumber']` instead of `+1`. See `docs/development/known-issues.md` for full details.

## Last Completed Task

**All API contract corrections** (2026-05-31) — 13 discrepancies between implementation and `docs/backend/api-contract.md` resolved across 11 correction prompts: route paths, response shapes, missing endpoints, `abandon` behavior, privacy rules, and query optimizations.

## Pending Tasks

Work through these in order. Each task should be implemented, tested, and marked complete before moving to the next.

1. ~~**Task 155 — Fix unauthenticated API requests returning 500 instead of 401**~~ — Complete (2026-05-31). Added `$middleware->redirectGuestsTo(fn () => null)` to `bootstrap/app.php`.

---

2. ~~**Place `engine/init-game.js`**~~ — Not needed. `GameService::startGame()` skips the engine script when `state_json` is already populated (Phase 18, Task 152). The client generates the initial state and passes it as `state_json` on game creation.

3. ~~**Phase 18 — Play with Friends: Creator-First Start**~~ — Complete (2026-05-31). Tasks 151–154 done.

4. ~~**Allow creator to delete a game regardless of status**~~ — Complete (2026-05-31).
