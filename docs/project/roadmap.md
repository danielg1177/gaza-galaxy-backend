# Roadmap

Build order follows dependency order — each step depends on the previous being complete and tested.

---

## Phase 1 — Foundation

### 1.1 Database Migrations
Create all six migrations from `docs/backend/database-schema.md`:
- `users`
- `friendships`
- `games`
- `game_players`
- `game_invites`
- `turns`

### 1.2 Models
Create Eloquent models: `User`, `Friendship`, `Game`, `GamePlayer`, `GameInvite`, `Turn`.

Configure `User` with `HasApiTokens`, `$fillable`, and `$casts`.

### 1.3 Sanctum + CORS Configuration
- Set token expiration to `null`
- Enable Sanctum middleware on `api` guard
- Configure CORS to allow `api/*`

---

## Phase 2 — Authentication

Implement `AuthController`:
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`

Implement `PushTokenController`:
- `POST /api/push-token`

**Test:** Register two users. Login. Logout. Verify token auth. Verify push token save.

---

## Phase 3 — Friends System

Implement `FriendController` and `UserController`:
- `GET /api/friends`
- `GET /api/friends/requests`
- `POST /api/friends/request`
- `POST /api/friends/requests/{id}/accept`
- `POST /api/friends/requests/{id}/decline`
- `DELETE /api/friends/{id}`
- `GET /api/users/search`

**Test:** Full friend request flow between two users. Search. Accept. Verify list.

---

## Phase 4 — Game Creation and Invites

Implement `GameController` (create + list) and `InviteController`:
- `POST /api/games`
- `GET /api/games`
- `GET /api/invites`
- `POST /api/invites/{id}/accept`
- `POST /api/invites/{id}/decline`
- `DELETE /api/games/{id}`

**Prerequisite:** `engine/init-game.js` must be available before accept/decline can fully start a game.

**Test:** Create game with friend invite. Verify invite appears. Accept invite. Verify game starts. Decline invite. Verify game cancelled.

---

## Phase 5 — Game Engine Bridge

Build or obtain `engine/init-game.js` from the frontend team.

Place it at `{backend_root}/engine/init-game.js`.

Implement `GameService::startGame()` per `docs/backend/turn-engine.md`.

**Test:** Create a solo game (no invites). Verify state_json is populated, game status = active.

---

## Phase 6 — Game Detail + Turn System

Implement `TurnController` and `GET /api/games/{id}`:
- `GET /api/games/{id}`
- `POST /api/games/{id}/turn/save`
- `POST /api/games/{id}/turn/submit`
- `POST /api/games/{id}/turn/abandon`

**Test:** Full turn cycle. Mid-turn save. Resume. Submit. Verify state advances. Verify privacy (other player cannot see in_progress_actions).

---

## Phase 7 — Push Notifications

Implement `NotificationService::sendPushNotification()`.

Wire into all trigger points:
- Game invite
- Invite accepted/declined
- Game started
- Your turn
- Game finished

**Test:** Verify notifications fire for all events (check Expo push receipt logs).

---

## Phase 8 — End-to-End Testing

Run the full testing checklist from `docs/backend-build-instructions.md` Section 9.

---

## Phase 9 — PWA Push Notifications

Required for the PWA release. The frontend is switching from Expo push tokens (native-only) to the Web Push API (VAPID). These tasks add VAPID key management, a web push subscription storage column, a new API endpoint, and VAPID-based notification delivery alongside the existing Expo push path.

### 9.1 Generate VAPID key pair and add to `.env`
Generate VAPID public/private key pair (`VAPID::createVapidKeys()` from the web-push library after 9.2 is installed, or via `openssl`). Add `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` to `.env` and `.env.example`. Share `VAPID_PUBLIC_KEY` with the frontend team.

### 9.2 Install `minishlink/web-push`
```bash
composer require minishlink/web-push
```

### 9.3 Add `web_push_subscription` column to `users`
Migration: nullable `text` column on `users` table. `User::$fillable` updated. Stores the serialized `PushSubscription.toJSON()` object `{ endpoint, keys: { p256dh, auth } }`.

### 9.4 Add `POST /api/push-subscription` endpoint
New route in the `auth:sanctum` group. Validates `subscription.endpoint` (URL), `subscription.keys.p256dh`, `subscription.keys.auth`. Stores `json_encode($validated['subscription'])` on the user.

### 9.5 Update `NotificationService` to send Web Push
Add `sendWebPushNotification(User $user, ...)` private method using `WebPush` + `Subscription` from `minishlink/web-push`. Call it from the existing `sendPushNotification()` after the Expo push block. Add VAPID config to `config/services.php`.

**Test:** Deploy VAPID keys. Install the PWA in a browser. Verify push subscription is uploaded (`users.web_push_subscription` populated). Trigger a game event (e.g. friend submits a turn) and confirm the notification arrives in the browser/OS.

---

## Phase 10 — Bug Fix: Spoiler Notification Sent to Eliminated/Losing Player Before They See the Final Battle

**Context:** See `frontend/docs/tasks/backlog.md` Phase 47. When a player is eliminated or the game ends, the backend currently sends them an "eliminated" or "game over" push notification immediately. This spoils the outcome before the player has had a chance to open the game and view the final battle report. The frontend fix (Tasks 214–215) allows the player to enter the finished game and see the fight — but only if they are not already discouraged by a blunt "you lost" notification.

---

### ~~Backend Task 10.1 — Change elimination/game-over push notification to neutral "view results" framing~~ *(complete 2026-06-04)*

**File:** `app/Http/Controllers/TurnController.php`

**Goal:** Replace the spoiler-heavy "eliminated" / "game over" notification copy sent to the losing or eliminated player with a neutral "see what happened" message that entices them to open the game and view the final battle report.

**Current behaviour (`TurnController::submit()`):**

After a turn submit that ends the game, the backend sends the loser/eliminated player a notification along the lines of:
- "You've been eliminated from [Game]" — or equivalent game-over copy

**Required change:**

1. Locate the push notification call(s) in `TurnController::submit()` that fire when `$game->status` is set to `'finished'` and a `winner_user_id` is determined.

2. For every player who is **not** the winner, change the notification to:
   - **Title:** `"Final battle awaits"` (or the game name, e.g. `"{$game->name}"`)
   - **Body:** `"The last round is ready to view — see how it ended."`

3. For the **winner**, the notification can remain positive (e.g. `"You won [Game]!"`) or be similarly neutralised — use judgement; the winner is not at risk of being spoiled since they already know the outcome.

4. No other logic changes — only the notification `title` and `body` strings are modified.

5. Update `docs/backend/notifications.md` to document the revised notification copy for the game-end event.

**Verification:**

- Submit a turn that eliminates another player. Confirm the eliminated player's push notification body is the neutral "see how it ended" copy, not an elimination spoiler.
- The winner's notification remains positive and fires correctly.
- No other notification paths (invite, your-turn, game-started) are changed.

---

## Phase 11 — Feature: In-Game Messaging System

Players in an async multiplayer game can send messages to one another throughout the game. Messages are stored per-game and each player's read position is tracked, enabling unread counts to be surfaced in the game list and game detail APIs.

Work tasks in order: 11.1 → 11.2 → 11.3 → 11.4.

---

### Backend Task 11.1 — Create `game_messages` table, `GameMessage` model, and `last_read_message_id` on `game_players`

**Goal:** Provide the schema required to store messages and track each player's read position.

**Steps:**

1. **Migration 1 — `game_messages` table** (`php artisan make:migration create_game_messages_table`):
   ```php
   Schema::create('game_messages', function (Blueprint $table) {
       $table->bigIncrements('id');
       $table->unsignedBigInteger('game_id');
       $table->unsignedBigInteger('sender_user_id');
       $table->text('content');
       $table->timestamps();

       $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
       $table->foreign('sender_user_id')->references('id')->on('users')->cascadeOnDelete();
       $table->index('game_id');
   });
   ```

2. **Migration 2 — `last_read_message_id` on `game_players`** (`php artisan make:migration add_last_read_message_id_to_game_players_table`):
   ```php
   Schema::table('game_players', function (Blueprint $table) {
       $table->unsignedBigInteger('last_read_message_id')->nullable()->after('name');
   });
   ```
   No FK constraint needed — the referenced message may be deleted if the game is deleted, but cascade delete on `game_messages` handles cleanup.

3. **`GameMessage` model** (`app/Models/GameMessage.php`):
   ```php
   protected $fillable = ['game_id', 'sender_user_id', 'content'];
   protected $casts    = ['game_id' => 'integer', 'sender_user_id' => 'integer'];
   ```
   Relationships: `game()` belongsTo `Game`, `sender()` belongsTo `User`.

4. **Update `Game` model:** add `messages()` hasMany `GameMessage`.

5. **Update `GamePlayer` model:** add `'last_read_message_id'` to `$fillable` and `'last_read_message_id' => 'integer'` to `$casts`.

6. Run `php artisan migrate`.

---

### Backend Task 11.2 — Create `MessageController` with `index` and `store` endpoints

**Goal:** Allow players to fetch all messages for a game and to send new messages.

**File:** `app/Http/Controllers/MessageController.php`

**Endpoints:**

- **`GET /api/games/{game}/messages`** — returns all messages for the game, sorted oldest-first. Also marks all messages as read for the calling user by updating their `game_players.last_read_message_id` to the latest message's `id`.
- **`POST /api/games/{game}/messages`** — sends a new message. Validates `content` (required, string, max 500). Returns the created message.

**Implementation:**

```php
// index
public function index(Game $game): JsonResponse
{
    $me = $request->user();
    // Membership guard
    $player = $game->players()->where('user_id', $me->id)->first();
    abort_unless($player, 403);

    $messages = $game->messages()
        ->with('sender')
        ->orderBy('id')
        ->get()
        ->map(fn ($m) => [
            'id'            => $m->id,
            'senderUserId'  => $m->sender_user_id,
            'senderName'    => $game->players()
                                   ->where('user_id', $m->sender_user_id)
                                   ->value('name') ?? $m->sender->username,
            'content'       => $m->content,
            'createdAt'     => $m->created_at->toIso8601String(),
        ]);

    // Mark as read
    if ($messages->isNotEmpty()) {
        $player->update(['last_read_message_id' => $messages->last()['id']]);
    }

    return response()->json(['messages' => $messages]);
}

// store
public function store(Request $request, Game $game): JsonResponse
{
    $me = $request->user();
    $player = $game->players()->where('user_id', $me->id)->first();
    abort_unless($player, 403);

    $validated = $request->validate(['content' => ['required', 'string', 'max:500']]);

    $msg = GameMessage::create([
        'game_id'        => $game->id,
        'sender_user_id' => $me->id,
        'content'        => $validated['content'],
    ]);

    // Mark as read for sender immediately
    $player->update(['last_read_message_id' => $msg->id]);

    // Send push notifications to all other game members (Task 11.4)
    // ...

    $senderName = $player->name ?? $me->username;

    return response()->json(['message' => [
        'id'           => $msg->id,
        'senderUserId' => $msg->sender_user_id,
        'senderName'   => $senderName,
        'content'      => $msg->content,
        'createdAt'    => $msg->created_at->toIso8601String(),
    ]], 201);
}
```

Register both routes inside the `auth:sanctum` group in `routes/api.php`:
```php
Route::get('/games/{game}/messages', [MessageController::class, 'index']);
Route::post('/games/{game}/messages', [MessageController::class, 'store']);
```

Update `docs/backend/api-contract.md` and `docs/project/current-state.md` with the two new endpoints.

---

### Backend Task 11.3 — Add `unread_message_count` to `GET /api/games` and `GET /api/games/{id}` responses

**Goal:** The frontend needs to know how many unread messages exist per game without fetching the full message thread. Include this count on both the game list and game detail responses.

**File:** `app/Http/Controllers/GameController.php`

**Computation:** For the calling user's `GamePlayer` row, count messages in `game_messages` where `id > game_players.last_read_message_id` AND `sender_user_id != me.id`. When `last_read_message_id` is null, all messages from others are unread.

**`index` update:**

Add a subquery or eager-load to compute `unread_message_count` per game. The safest approach is a raw subquery on the game collection after the main query:

```php
$myPlayerId = /* the GamePlayer id for current user in this game */;
$unread = GameMessage::where('game_id', $game->id)
    ->where('sender_user_id', '!=', $me->id)
    ->when($player->last_read_message_id, fn ($q, $lastRead) =>
        $q->where('id', '>', $lastRead)
    )
    ->count();
```

Add `'unread_message_count' => $unread` to each game's response array.

**`show` update:** apply the same computation to the single game detail response.

Update `docs/backend/api-contract.md` and `docs/project/current-state.md`.

---

### Backend Task 11.4 — Send push notification to game members when a new message is posted

**Goal:** When a player sends a message, all other human players in the game receive a push notification so they know a message is waiting.

**File:** `app/Http/Controllers/MessageController.php` (`store` method)

**Requirements:**

After the `GameMessage` is created (and outside any transaction), iterate over all `game_players` for the game:
- Skip the sender (`user_id === $me->id`).
- Skip AI players (rows where `user_id` is null or the player is an AI slot — check the existing AI-player pattern used in `TurnController`).
- For each remaining human player, load their `User` record and call `$this->notificationService->sendPushNotification($user, $title, $body)`.

**Notification copy:**
- **Title:** the game name, e.g. `"$game->name"`
- **Body:** `"$senderName: $truncatedContent"` — truncate `content` to 80 characters if longer (append `"..."`)

Inject `NotificationService` via the constructor, matching the existing pattern in `TurnController`.

Update `docs/backend/notifications.md` with the new notification event.

---

## Phase 12 — Bug Fix: `turns.in_progress_actions_json` Column Too Small

**Status:** Complete (2026-06-08).

One migration to fix the "Exit Game mid-turn fails in complex games" bug. See `docs/development/known-issues.md` for the full root-cause write-up.

---

### Backend Task 12.1 — Migrate `turns.in_progress_actions_json` from TEXT to LONGTEXT

**File:** `backend/database/migrations/` (new migration file)

**Root cause recap:** `in_progress_actions_json` was created as `TEXT` (max 65,535 bytes). A complex late-game state JSON-encoded by PHP can exceed that limit, causing MySQL strict-mode to throw "Data too long for column" → 500 → frontend "Could not save your progress." The `resulting_state_json` column was correctly created as `LONGTEXT` from the start; this migration brings `in_progress_actions_json` in line with it.

**Requirements:**

1. Create `backend/database/migrations/2026_06_08_000001_expand_in_progress_actions_json_on_turns_table.php`:

   ```php
   <?php

   use Illuminate\Database\Migrations\Migration;
   use Illuminate\Database\Schema\Blueprint;
   use Illuminate\Support\Facades\Schema;

   return new class extends Migration
   {
       public function up(): void
       {
           Schema::table('turns', function (Blueprint $table) {
               $table->longText('in_progress_actions_json')->nullable()->change();
           });
       }

       public function down(): void
       {
           Schema::table('turns', function (Blueprint $table) {
               $table->text('in_progress_actions_json')->nullable()->change();
           });
       }
   };
   ```

2. Run `php artisan migrate` on the production database.

3. Update `docs/backend/database-schema.md`: change `in_progress_actions_json` column type from `text` to `longtext` in the `turns` table definition.

4. Update `docs/development/task-log.md` with a completion entry.

**Verification:**
- `php artisan migrate:status` shows the new migration as `Ran`.
- In a long-running game session, **⋮ → Exit Game** succeeds without error.
- Re-entering the game restores the saved in-progress state correctly.

---

## Future (Post-Launch)

| Feature | When |
|---------|------|
| Queue-based notification delivery | When fire-and-forget reliability becomes insufficient |
| Redis + Horizon | When queue volume justifies it |
| WebSocket / real-time updates | If the client needs live game state without polling |
| Password reset flow | If admin-only resets become insufficient |
| Leaderboards / stats | Post-launch feature |
| Spectator mode | Post-launch feature |
