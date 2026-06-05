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

## Future (Post-Launch)

| Feature | When |
|---------|------|
| Queue-based notification delivery | When fire-and-forget reliability becomes insufficient |
| Redis + Horizon | When queue volume justifies it |
| WebSocket / real-time updates | If the client needs live game state without polling |
| Password reset flow | If admin-only resets become insufficient |
| Leaderboards / stats | Post-launch feature |
| Spectator mode | Post-launch feature |
