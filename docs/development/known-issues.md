# Known Issues

## Open Issues

_None._

## Resolved Issues

### ~~Async turn events not persisted — waiting player has no battle report~~ (2026-06-01, resolved 2026-06-01)

**Symptom:** Opponent's async turn changed map state but the waiting player had no battle report.

**Fix (Phase 35, Tasks 188–191):** `turns.events_json` stores submitted events; `GET /api/games/{id}` returns `latest_events`; frontend `submitTurn` sends `events` and `loadAsyncGame` restores `turnReport` from `detail.latestEvents`.

### `TurnController::submit()` stale check fails when AI players are present (2026-05-31, resolved 2026-05-31)

**Symptom:** In a multiplayer game that includes AI players, every human End Turn returns 409 Stale turn data. The frontend shows "Submit Failed" to the user.

**Root cause:** `games.turn_number` is incremented by `+1` on each human submit (line 169 of `TurnController.php`). However, the frontend's `GameState.turnNumber` increments by `+1` per `resolveTurn` call — once for the submitting human, then once per AI player during `runAiTurnsUntilHuman`. The `resulting_state` sent by the frontend has `turnNumber = game.turn_number + 1 + N_ai`. This is stored as `state_json`. When the next human loads the game, their pre-resolution `preTurnNumber` equals this inflated value. Their submit sends `turn_number = game.turn_number + 1 + N_ai` while the backend has `game.turn_number + 1` → mismatch → 409.

**Impact:** Any multiplayer game with at least one AI slot fails on every End Turn. Pure 2-human (no AI) games are unaffected.

**Fix (Task 161):** In `TurnController::submit()`, replace the hardcoded `+1` advancement with the `turnNumber` value from `$request->resulting_state`. See `frontend/docs/tasks/backlog.md` Task 161 for full specification.

## Resolved Issues

### `auth:sanctum` returns 500 instead of JSON 401 for unauthenticated requests (2026-05-31)

**Symptom:** Any API request that reaches a Sanctum-protected route without a valid token produces a 500 error response body (HTML or JSON with a stack trace) instead of `{"message":"Unauthenticated."}` with HTTP 401. Visible in `storage/logs/laravel.log` as `Route [login] not defined`.

**Root cause:** `bootstrap/app.php` calls `withMiddleware()` without overriding the guest-redirect closure. The default `ApplicationBuilder` closure calls `route('login')` for unauthenticated requests. No `login` named route is registered in this API-only app, so `Illuminate\Routing\UrlGenerator` throws a `RouteNotFoundException`. This exception happens inside the middleware pipeline before `shouldRenderJsonWhen` can catch `AuthenticationException`, so it surfaces as a 500.

**Impact:** Any client-side token expiry or missing-header scenario returns 500 (not 401), preventing the frontend `setOnUnauthorized` callback from triggering the auto-logout flow.

**Fix:** Added `$middleware->redirectGuestsTo(fn () => null)` to `withMiddleware` in `bootstrap/app.php` (Task 155). Returning `null` lets `AuthenticationException` propagate cleanly; `shouldRenderJsonWhen` renders it as JSON 401.

### Multiple backend bugs found during multiplayer bug investigation (2026-05-31)

**`GameController::store()` wrong response format:**
- Returned `{ "data": {...} }` but frontend `createGame()` reads `data.game` → was always `undefined`. Fixed: response now returns `{ "game": ApiGameRaw }` with all required fields.

**`GameController::store()` did not accept `state_json`:**
- `POST /api/games` never stored client-provided state; `startGame()` always ran `engine/init-game.js` which doesn't exist → 500 for any game without pending invites. Fixed: `store()` accepts optional `state_json`; `startGame()` skips engine script when `$game->state_json` is already populated.

**`InviteController::index()` wrong response key:**
- Returned `{ "data": [...] }` but frontend expected `{ "invites": [...] }`. Fixed.

**`InviteController::accept()` wrong response shape:**
- Returned `{ "data": { id, status, game_id } }` but frontend expected `{ "accepted": true, "game_started": bool }`. Fixed.
