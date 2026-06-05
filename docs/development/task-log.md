# Task Log

## 2026-05-29 — Sanctum & CORS Configuration

**Status:** Complete

- Created `config/cors.php` with `paths: ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins: ['*']`, `supports_credentials: true`
- Updated `bootstrap/app.php`: added `$middleware->statefulApi()` and JSON exception handler for `api/*`
- Added `HasApiTokens` trait to `app/Models/User.php`

**Note:** `User` model `$fillable` still uses Laravel defaults — will be corrected in the models prompt.

---

## 2026-05-29 — Database Migrations

**Status:** Complete

- Created 6 migration files in dependency order: `users`, `friendships`, `games`, `game_players`, `game_invites`, `turns`
- All columns, types, ENUMs, nullable flags, defaults, unique constraints, and FK cascade behaviors match `docs/backend/database-schema.md`
- `php artisan migrate` ran successfully

---

## 2026-05-29 — Eloquent Models

**Status:** Complete

- Updated `User`: `$fillable = ['username', 'password', 'expo_push_token']`, `$hidden = ['password']`, removed default email cast, added friendship and game relationships
- Created `Friendship`: fillable, string cast on status, requester/addressee belongsTo
- Created `Game`: fillable, integer casts, players/invites/turns hasMany, createdBy/currentUser belongsTo
- Created `GamePlayer`: fillable, boolean/integer casts, game/user belongsTo
- Created `GameInvite`: fillable, integer cast on slot index, game/inviter/invitee belongsTo
- Created `Turn`: fillable, integer casts, game/user belongsTo

---

## 2026-05-29 — Auth Endpoints

**Status:** Complete

- Created `RegisterRequest` (username regex + unique, password min 6)
- Created `LoginRequest` (username + password required)
- Created `AuthController`: register (201), login (200, rotates tokens), logout (204, deletes current token only)
- Registered routes in `routes/api.php`

---

## 2026-05-31 — Push Token Endpoint

**Status:** Complete

- Created `PushTokenController` with inline `starts_with:ExponentPushToken` validation
- Route `PUT /api/user/push-token` added inside `auth:sanctum` group in `routes/api.php`

---

## 2026-05-31 — Friends System

**Status:** Complete

- Created `FriendController` with 5 endpoints
- `index`: bidirectional accepted-friendship query, maps to the other user's id/username
- `request`: self-add (400), bidirectional duplicate check (409), creates pending friendship (201)
- `accept`: scoped to addressee only (403), pending-only guard (422), updates to accepted (200)
- `destroy`: scoped to either party (403), deletes record (204)
- `search`: LIKE query excluding self, limit 20
- Routes added to `auth:sanctum` group in `routes/api.php`

---

## 2026-05-31 — Game Creation & Game List

**Status:** Complete

- Created `GameController` with `index` and `store`
- `index`: queries GamePlayer for current user, eager loads game, returns fields excluding `state_json`
- `store`: inline validation, slot-0-is-creator guard (422), `isAcceptedFriend` helper for bidirectional check, DB::transaction wrapping all creation, AI/human slot branching, GameInvite for non-creator human slots, immediate-start shortcut when no pending invites
- Routes `GET /api/games` and `POST /api/games` added to `auth:sanctum` group

---

## 2026-05-31 — Invite Management

**Status:** Complete

- Created `InviteController` with `index`, `accept`, `decline`
- `index`: pending invites for me as invitee, eager loads game + inviter
- `accept`: 403 if not invitee, 422 if not pending, transaction creates GamePlayer + checks all-accepted → sets game in_progress with creator as current_user_id
- `decline`: 403 if not invitee, 422 if not pending, transaction sets game to finished + bulk-declines all remaining pending invites
- Routes added to `auth:sanctum` group

---

## 2026-05-31 — Game Detail & Delete

**Status:** Complete

- Added `show` to `GameController`: membership 403 guard, eager loads players + invites, returns full game including `state_json` and all invite statuses
- Added `destroy` to `GameController`: membership check (403), creator check (403), status check (422 if not waiting_for_players), deletes record (cascade handles children)
- Routes `GET /api/games/{game}` and `DELETE /api/games/{game}` added to `auth:sanctum` group

---

## 2026-05-31 — Turn Submit

**Status:** Complete

- Added `submit` to `TurnController`
- Guards: membership (403), in_progress (422), current player (403)
- Validates turn_number, round_number, actions_json, resulting_state_json
- Stale check: 409 if turn_number or round_number mismatch
- Validates required state keys (status, currentPlayerId, roundNumber, players); 422 if missing
- DB::transaction: upserts Turn, detects game end (winner from first non-eliminated player-N), advances current_user_id + round/turn counters, syncs eliminations via private `updateEliminations` helper
- Route `POST /api/games/{game}/turn/submit` added to `auth:sanctum` group

---

## 2026-05-31 — Push Notifications

**Status:** Complete

- Created `app/Services/NotificationService.php`: `sendPushNotification()` using Laravel `Http` facade, `blank()` guard, `\Throwable` catch, fire-and-forget
- `TurnController`: constructor injection, "it's your turn" after submit/abandon, winner/loser notifications after game end
- `InviteController`: "invite received" on accept (for inviter), "game started" on last accept (for creator), "cancelled" on decline (for creator)
- `GameController`: "you've been invited" notification per invite after `store` transaction
- All notification calls placed outside `DB::transaction` closures

---

## 2026-05-31 — Turn Save & Abandon

**Status:** Complete

- Created `TurnController` with `save` and `abandon`
- `save`: membership (403), status (422), turn ownership (403), `updateOrCreate` upsert on in_progress_actions_json
- `abandon` (original): WRONG — was eliminating player. Corrected in next entry.
- Routes added to `auth:sanctum` group

---

## 2026-05-31 — Correction: Fix `abandon` + Rename Turn Routes

**Status:** Complete

- `abandon` rewritten: now only deletes the Turn record for current game/user/turn/round. No player elimination, no game state changes, returns `{ "abandoned": true }`
- Turn routes renamed to match api-contract.md: `turn/save`, `turn/submit`, `turn/abandon` (singular, POST for all three)

---

## 2026-05-31 — Correction: Auth Route Paths + `GET /auth/me`

**Status:** Complete

- Auth routes moved to `/auth/*` prefix: `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`
- `logout` and `me` moved inside the `auth:sanctum` group
- `logout` response changed from 204 to 200 `{ "message": "Logged out" }`
- `me` method added to `AuthController`
- Old `/register`, `/login`, `/logout`, `/user` routes removed

---

## 2026-05-31 — Correction: Push Token Route & Request Shape

**Status:** Complete

- Route changed from `PUT /user/push-token` to `POST /push-token`
- Request field renamed from `push_token` to `token`
- Response changed from `{ "message": "Push token updated" }` to `{ "saved": true }`

---

## 2026-05-31 — Correction: Turn Save Request Shape

**Status:** Complete

- `save` now validates `in_progress_actions` as object with `partial_state_json` (string) and `queued_orders` (array)
- Stores `json_encode($request->in_progress_actions)` into `in_progress_actions_json`
- Response changed from `{ "message": "Turn saved" }` to `{ "saved": true }`

---

## 2026-05-31 — Correction: Turn Submit Request Shape

**Status:** Complete

- `submit` now validates `actions` (array) and `resulting_state` (array) instead of JSON strings
- `$state` assigned directly from `$request->resulting_state` — no `json_decode` needed
- Turn upsert stores `json_encode($request->actions)` and `json_encode($request->resulting_state)`
- All game state advancement, elimination, and notification logic unchanged

---

## 2026-05-31 — Correction: Friends Response Shapes + Missing Endpoints

**Status:** Complete

- `index`: response changed to `{ "friends": [{ "friendship_id": ..., "user": {...} }] }`
- `request`: self-add and duplicate both changed to 422; response changed to `{ "friendship_id": ..., "status": "pending" }`
- `accept`: response changed to `{ "friendship_id": ..., "status": "accepted" }`; route moved to `POST /friends/requests/{friendship}/accept`
- Added `requests` method: incoming pending requests for me as addressee, returns `{ "requests": [...] }` with ISO 8601 `created_at`
- Added `decline` method: scoped to addressee, deletes record, returns `{ "message": "Declined" }`
- `destroy`: response changed from 204 to 200 `{ "message": "Friend removed" }`
- Routes updated in `routes/api.php`

---

## 2026-05-31 — Add `name` to `game_players`

**Status:** Complete

- Migration `add_name_to_game_players_table` created: `name` nullable string(50) after `turn_order`
- `GamePlayer::$fillable` updated to include `name`

---

## 2026-05-31 — Correction: Game Creation Request Shape

**Status:** Complete

- Validation changed: `map_config` (array), `player_slots.*.user_id` (nullable), `player_slots.*.name` (required string max 50)
- Slot 0 guard now accepts `user_id: null` (means creator) or `user_id == $me->id`
- Null substitution applied to slot 0 before friend check loop
- `map_config_json` stored as `json_encode($validated['map_config'])`
- `name` stored on all GamePlayer records (AI and human)

---

## 2026-05-31 — Correction: Game List Response + Delete Response

**Status:** Complete

- `index` rewritten: queries Game via GamePlayer id list, eager loads players, computes `is_my_turn`, `has_in_progress_actions`, `alert_state` (5-case match), `current_player_name`, maps players to `in_game_name` shape
- Response changed from `{ "data": [...] }` to `{ "games": [...] }` with all required fields
- `destroy` response changed from 204 to 200 `{ "message": "Game deleted" }`

---

## 2026-05-31 — Correction: Game Detail Response

**Status:** Complete

- `show` rewritten: computes `is_my_turn`, `has_in_progress_actions`, `alert_state` (same 5-case match as index)
- `in_progress_actions` only returned to current player (privacy rule); decoded from JSON if present, else null
- Response changed from `{ "data": {...} }` to `{ "game": {...}, "state_json": ..., "is_my_turn": ..., "alert_state": ..., "in_progress_actions": ... }`
- Bug fix: removed erroneous `->whereNull('submitted_at')` clause (column does not exist)

---

## 2026-05-31 — Bug Fix: Game Model Integer Casts

**Status:** Complete

- Added integer casts to `Game::$casts` for `current_user_id`, `created_by_user_id`, and `winner_user_id`
- Without these casts, PHP PDO returns all numeric DB columns as strings; the strict `===` comparison `$game->current_user_id === $me->id` (int) always returned `false`, so `is_my_turn` was always false and `alert_state` was always `'waiting'` even when it was the creator's turn

---

## 2026-05-31 — Bug Fix: Game Creation + Invite Response Corrections

**Status:** Complete

- `GameController::store()`: accepts optional `state_json` field (nullable string); stores it on `Game` row at creation so `startGame()` can use it without the engine script; calls `$game->refresh()` after `startGame()`; response changed from `{ "data": {...} }` to `{ "game": ApiGameRaw }` with all required fields (`alert_state`, `is_my_turn`, `has_in_progress_actions`, `play_mode`, `players`, `current_player_name`, timestamps)
- `GameService::startGame()`: if `$game->state_json` is already populated, parses it to find `currentPlayerId`, sets `status = in_progress` and `current_user_id` directly — skips `engine/init-game.js` entirely; existing engine-script path retained as fallback
- `InviteController::index()`: response key changed from `"data"` to `"invites"` to match frontend `InvitesListResponse`
- `InviteController::accept()`: response changed from `{ "data": { id, status, game_id } }` to `{ "accepted": true, "game_started": bool }` to match frontend `AcceptInviteResponse`

---

## 2026-05-31 — Correction: User Search `friendship_status`

**Status:** Complete

- `search` method updated: loads all relevant friendships in one query (no N+1), computes `friendship_status` per result via in-memory collection lookup
- `friendship_status` values: `none | pending_sent | pending_received | accepted`
- Response key changed from `"data"` to `"users"`

---

## 2026-05-31 — Task 151: Create all GamePlayer rows at game creation

**Status:** Complete

- `GameController::store()`: creates a `game_players` row for every slot (creator, AI, and all invited humans) during the game creation transaction; invited human slots still get a `GameInvite` for accept/decline tracking
- `InviteController::accept()`: removed the `GamePlayer::create()` call — the row already exists from game creation; accept updates invite status only (plus existing all-accepted → `startGame()` logic)

---

## 2026-05-31 — Task 152: Start game immediately on creation; handle pending-invitee turn advancement

**Status:** Complete

- `GameController::store()`: removed pending-invite gate; always calls `startGame()` after creation; response now includes top-level `state_json` alongside `game`
- `TurnController::submit()`: before sending "your turn" notification, checks if next player has a pending invite; if so sets `status = waiting_for_players` and skips the notification
- `docs/backend/api-contract.md`: updated `POST /api/games` response example with `state_json` and `in_progress` status

---

## 2026-05-31 — Task 153: Invite accept unblocks the game when it's the accepter's turn

**Status:** Complete

- `InviteController::accept()`: removed all-accepted → `startGame()` block and `GameService` dependency; after marking invite accepted, checks if game was waiting for this player's turn and transitions to `in_progress` if so
- Sends "your turn" notification to accepter when unblocked; `game_started` in response now reflects unblocked state
- `docs/backend/api-contract.md`: updated `POST /api/invites/{id}/accept` description for turn-unblocking behavior

---

## 2026-05-31 — Task 155: Fix unauthenticated API requests returning 500 instead of 401

**Status:** Complete

- Added `$middleware->redirectGuestsTo(fn () => null)` to `bootstrap/app.php` `withMiddleware` closure
- Prevents default guest redirect from calling `route('login')` (no such route in this API-only app), which was throwing `RouteNotFoundException` and surfacing as 500
- Unauthenticated requests now return `{"message":"Unauthenticated."}` with HTTP 401 via existing `shouldRenderJsonWhen` handler

---

## 2026-06-01 — Task 188: Store turn events on submit

**Status:** Complete

- Migration `2026_06_01_000001_add_events_json_to_turns_table.php`: nullable `events_json` `longText` on `turns`, after `resulting_state_json`
- `TurnController::submit()`: validates optional `events` (`nullable|array`); upserts `events_json` from `json_encode($request->events)` when present, otherwise NULL
- `Turn` model: `events_json` added to `$fillable`
- Docs updated: `database-schema.md`, `turn-engine.md`, `project/current-state.md`

---

## 2026-06-05 — Backend Task 11.3: `unread_message_count` in `GET /api/games` and `GET /api/games/{id}`

**Status:** Complete

- Added private `unreadMessageCount(int $gameId, int $meId, ?int $lastReadId): int` helper to `GameController`; counts `game_messages` where `sender_user_id != $meId` and (if `$lastReadId` is set) `id > $lastReadId`
- `index`: `unread_message_count` added to each game's response array using the existing `$player` GamePlayer row
- `show`: `unread_message_count` added inside the `game` response object using the existing `$myPlayer` GamePlayer row

---

## 2026-06-05 — Backend Task 11.2: `MessageController` with `index` and `store` endpoints

**Status:** Complete

- Created `app/Http/Controllers/MessageController.php` with `index` (GET) and `store` (POST)
- `index`: membership guard (403), fetches all messages ordered by id with `sender` eager-load, maps to `{ id, senderUserId, senderName, content, createdAt }`, updates `game_players.last_read_message_id` to latest message id after fetch
- `store`: membership guard (403), validates `content` (required, string, max 500), creates `GameMessage`, marks sender as read, sends push notification to all other non-null human `game_players` with truncated content (80 chars), returns 201 with created message shape
- Routes `GET /games/{game}/messages` and `POST /games/{game}/messages` added to `auth:sanctum` group in `routes/api.php`
- Confirmed `GamePlayer::user()` belongsTo relationship already present

---

## 2026-06-05 — Backend Task 11.1: `game_messages` table, `GameMessage` model, `last_read_message_id` on `game_players`

**Status:** Complete

- Migration `2026_06_05_200208_create_game_messages_table.php`: `game_messages` table with `id`, `game_id` (FK → games, cascade delete), `sender_user_id` (FK → users, cascade delete), `content` (text), `timestamps()`; index on `game_id`
- Migration `2026_06_05_200208_add_last_read_message_id_to_game_players_table.php`: nullable `unsignedBigInteger` `last_read_message_id` added to `game_players` after `name`
- `GameMessage` model: `$fillable = ['game_id', 'sender_user_id', 'content']`; integer casts; `game()` and `sender()` belongsTo relationships
- `Game` model: `messages()` hasMany `GameMessage` added
- `GamePlayer` model: `last_read_message_id` added to `$fillable` and `$casts`
- `php artisan migrate` ran successfully

---

## 2026-06-01 — Task 189: Return latest_events from GET /api/games/{id}

**Status:** Complete

- `GameController::show()`: queries the latest submitted `Turn` (`resulting_state_json` not null, ordered by `turn_number` / `round_number` desc); decodes `events_json` into `latest_events`, or `[]` when absent
- Docs updated: `api-contract.md`, `project/current-state.md`, `development/known-issues.md`, `frontend/docs/tasks/backlog.md`, `frontend/docs/tasks/completed.md`
