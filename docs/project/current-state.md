# Current State

_Last updated: 2026-08-25_

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
- `PATCH /auth/username` — 200 `{ id, username }` — field: `username`
- `PATCH /auth/password` — 200 `{ message }` — fields: `current_password`, `password`, `password_confirmation` (422 if current password is wrong)

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
- `GET /games` — `{ games: [{ id, name, status, play_mode, alert_state, is_my_turn, has_in_progress_actions, winner_user_id, players, current_player_name, round_number, turn_number, created_at, unread_message_count }] }`
- `POST /games` — 201 — fields: `name`, `map_config` (object), `player_slots` (with `name`, `type`, `user_id`)
- `GET /games/{game}` — members only (403 otherwise) — `{ game: { ..., unread_message_count, players }, state_json, is_my_turn, alert_state, in_progress_actions, latest_events }` — `latest_events` is the decoded `events_json` from the most recently submitted turn, or `[]`. Players include `is_forfeited`. Sitting-out members get `is_my_turn: false` / `alert_state: waiting`.
- `DELETE /games/{game}` — creator only, any status — 200 `{ message }`
- `POST /games/{game}/forfeit` — human member, in-progress, not eliminated — 200 `{ forfeited: true }`
- `POST /games/{game}/rejoin` — sitting-out human member, in-progress, not eliminated — 200 `{ rejoined: true }`
- `POST /games/{game}/end` — any human member, in-progress — 200 `{ ended: true }` — finishes the match for everyone (`winner_user_id` null)

**Turns**
- `POST /games/{game}/turn/save` — current player only — field: `in_progress_actions` (object)
- `POST /games/{game}/turn/submit` — current player only — fields: `actions` (array), `resulting_state` (object), `turn_number`, `round_number`, optional `events` (array, stored in `turns.events_json`)
- `POST /games/{game}/turn/abandon` — current player only — clears mid-turn save only

**Messages**
- `GET /games/{game}/messages` — members only — `{ messages: [{ id, senderUserId, senderName, content, createdAt }] }` — also marks all messages as read for the calling user
- `POST /games/{game}/messages` — members only — field: `content` (string, max 500) — 201 `{ message: { id, senderUserId, senderName, content, createdAt } }` — sends push notification to all other human players

**Invites**
- `GET /invites` — pending invites where invitee = me
- `POST /invites/{invite}/accept` — invitee only
- `POST /invites/{invite}/decline` — invitee only, cancels game

## Current Database Tables

`users`, `friendships`, `games`, `game_players` (+ `is_forfeited`), `game_invites`, `turns`, `game_messages`, `personal_access_tokens`, `cache`, `jobs`, `migrations`

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

**Account settings** (2026-08-25) — `PATCH /api/auth/username` and `PATCH /api/auth/password` let a signed-in user change their login username or password. Username change does not rewrite in-game commander names. Wrong current password returns 422.

## Pending Tasks

Work through these in order. Each task should be implemented, tested, and marked complete before moving to the next.

1. ~~**Task 155 — Fix unauthenticated API requests returning 500 instead of 401**~~ — Complete (2026-05-31). Added `$middleware->redirectGuestsTo(fn () => null)` to `bootstrap/app.php`.

---

2. ~~**Place `engine/init-game.js`**~~ — Not needed. `GameService::startGame()` skips the engine script when `state_json` is already populated (Phase 18, Task 152). The client generates the initial state and passes it as `state_json` on game creation.

3. ~~**Phase 18 — Play with Friends: Creator-First Start**~~ — Complete (2026-05-31). Tasks 151–154 done.

4. ~~**Allow creator to delete a game regardless of status**~~ — Complete (2026-05-31).

---

## Phase 11 — Feature: In-Game Messaging System

**Status:** Not started. See `docs/project/roadmap.md` Phase 11 for full task specifications.

Four tasks to add per-game player messaging with unread count tracking and push notifications:

- ~~**Backend Task 11.1**~~ — Create `game_messages` table, `GameMessage` model, and `last_read_message_id` column on `game_players` *(complete 2026-06-05)*
- ~~**Backend Task 11.2**~~ — Create `MessageController` with `GET /api/games/{game}/messages` and `POST /api/games/{game}/messages` *(complete 2026-06-05)*
- ~~**Backend Task 11.3**~~ — Add `unread_message_count` to `GET /api/games` and `GET /api/games/{id}` responses *(complete 2026-06-05)*
- ~~**Backend Task 11.4**~~ — Send push notification to all other game members when a message is posted *(complete 2026-06-05 — wired in `MessageController::store`)*

---

## Phase 10 — Bug Fix: Spoiler Notification Sent to Eliminated/Losing Player

**Status:** Complete (2026-06-04).

~~**Backend Task 10.1**~~ — Change elimination/game-over push notification to neutral "view results" framing (`TurnController.php`, `docs/backend/notifications.md`) *(complete 2026-06-04)*

---

## Phase 9 — PWA Push Notifications

The frontend is being converted to a PWA. `expo-notifications` does not support background push on the web — the browser requires the **Web Push API (VAPID)** instead. These five tasks add VAPID-based push delivery alongside the existing Expo push path. Work them in order (9.1 → 9.2 → 9.3 → 9.4 → 9.5).

---

### Backend Task 9.1 — Generate VAPID key pair and add to `.env`

**Goal:** Generate a VAPID key pair (used to authenticate the server when sending Web Push messages) and store it in the environment config.

**Steps:**

1. Install the PHP web-push package first (Task 9.2 below), then run:
   ```bash
   php artisan web-push:vapid
   ```
   Or generate manually with `openssl`:
   ```bash
   openssl ecparam -name prime256v1 -genkey -noout | openssl pkcs8 -topk8 -nocrypt -out vapid_private.pem
   openssl ec -in vapid_private.pem -pubout -out vapid_public.pem
   ```
   The `minishlink/web-push` library provides a helper: `VAPID::createVapidKeys()` which returns `['publicKey' => ..., 'privateKey' => ...]`.

2. Add to `.env`:
   ```
   VAPID_SUBJECT=mailto:admin@yourdomain.com
   VAPID_PUBLIC_KEY=your_base64url_public_key
   VAPID_PRIVATE_KEY=your_base64url_private_key
   ```

3. Add the same three keys to `.env.example` with placeholder values.

4. The `VAPID_PUBLIC_KEY` value must be given to the frontend team for `EXPO_PUBLIC_VAPID_PUBLIC_KEY` in their `.env`.

---

### Backend Task 9.2 — Install `minishlink/web-push` PHP package

**Goal:** Add the PHP library that handles VAPID signing and Web Push protocol delivery.

```bash
composer require minishlink/web-push
```

Confirm `composer.json` and `composer.lock` are updated. No code changes yet — this just makes the library available for Tasks 9.4 and 9.5.

---

### Backend Task 9.3 — Add `web_push_subscription` column to `users` table

**Goal:** Store the browser's `PushSubscription` JSON (endpoint URL + encryption keys) per user.

**Steps:**

1. Create migration: `php artisan make:migration add_web_push_subscription_to_users_table`

2. Migration content:
   ```php
   Schema::table('users', function (Blueprint $table) {
       $table->text('web_push_subscription')->nullable()->after('expo_push_token');
   });
   ```

3. Update `User` model: add `'web_push_subscription'` to `$fillable`.

4. Run `php artisan migrate`.

**Stored format:** The column stores the serialized JSON of the browser's `PushSubscription.toJSON()` result:
```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/...",
  "keys": {
    "p256dh": "...",
    "auth": "..."
  }
}
```

---

### Backend Task 9.4 — Add `POST /api/push-subscription` endpoint

**Goal:** Accept a web push subscription from the frontend and store it on the user record.

**Steps:**

1. Add a method to `PushTokenController` (or create a new `PushSubscriptionController`):
   ```php
   public function storeSubscription(Request $request): JsonResponse
   {
       $validated = $request->validate([
           'subscription'           => ['required', 'array'],
           'subscription.endpoint'  => ['required', 'string', 'url'],
           'subscription.keys'      => ['required', 'array'],
           'subscription.keys.p256dh' => ['required', 'string'],
           'subscription.keys.auth'   => ['required', 'string'],
       ]);

       $request->user()->update([
           'web_push_subscription' => json_encode($validated['subscription']),
       ]);

       return response()->json(['saved' => true]);
   }
   ```

2. Register the route inside the `auth:sanctum` group in `routes/api.php`:
   ```php
   Route::post('/push-subscription', [PushTokenController::class, 'storeSubscription']);
   ```

3. Update `docs/backend/api-contract.md` and `docs/project/current-state.md` with the new endpoint.

---

### Backend Task 9.5 — Update `NotificationService` to send Web Push

**Goal:** When a user has a `web_push_subscription`, send the notification via VAPID Web Push in addition to (or instead of) the Expo push token path. Both paths should be attempted if both fields are populated on the user record.

**File:** `app/Services/NotificationService.php`

**Requirements:**

1. Import the web-push library at the top of the service:
   ```php
   use Minishlink\WebPush\WebPush;
   use Minishlink\WebPush\Subscription;
   ```

2. Add a private method `sendWebPushNotification(User $user, string $title, string $body, array $data = []): void`:
   ```php
   private function sendWebPushNotification(User $user, string $title, string $body, array $data = []): void
   {
       if (blank($user->web_push_subscription)) return;

       try {
           $sub = json_decode($user->web_push_subscription, true);
           $subscription = Subscription::create($sub);

           $auth = [
               'VAPID' => [
                   'subject'    => config('services.vapid.subject'),
                   'publicKey'  => config('services.vapid.public_key'),
                   'privateKey' => config('services.vapid.private_key'),
               ],
           ];

           $webPush = new WebPush($auth);
           $payload = json_encode(['title' => $title, 'body' => $body, 'data' => $data]);
           $webPush->queueNotification($subscription, $payload);
           $webPush->flush();
       } catch (\Throwable) {
           // Fire-and-forget: swallow all errors
       }
   }
   ```

3. Add VAPID config to `config/services.php`:
   ```php
   'vapid' => [
       'subject'     => env('VAPID_SUBJECT'),
       'public_key'  => env('VAPID_PUBLIC_KEY'),
       'private_key' => env('VAPID_PRIVATE_KEY'),
   ],
   ```

4. Update the existing `sendPushNotification(User $user, ...)` method to also call `sendWebPushNotification()` after the existing Expo push block:
   ```php
   public function sendPushNotification(User $user, string $title, string $body, array $data = []): void
   {
       // Existing Expo push path (unchanged)
       if (!blank($user->expo_push_token)) {
           try {
               Http::post('https://exp.host/--/api/v2/push/send', [
                   'to'    => $user->expo_push_token,
                   'title' => $title,
                   'body'  => $body,
                   'data'  => $data,
                   'sound' => 'default',
               ]);
           } catch (\Throwable) {}
       }

       // Web Push path (new)
       $this->sendWebPushNotification($user, $title, $body, $data);
   }
   ```

5. Update `docs/backend/notifications.md` and `docs/project/current-state.md` with the new web push path.
