# Push Notifications

Gaza Galaxy uses the **Expo Push API** directly from Laravel. No Firebase setup, no APNs certificates — Expo handles the platform-level delivery.

---

## Helper

```php
function sendPushNotification(string $expoPushToken, string $title, string $body, array $data = []): void {
    if (empty($expoPushToken)) return;

    Http::post('https://exp.host/--/api/v2/push/send', [
        'to'    => $expoPushToken,
        'title' => $title,
        'body'  => $body,
        'data'  => $data,
        'sound' => 'default',
    ]);
}
```

For multiple recipients, pass an array of tokens to `to` (Expo supports up to 100 per request).

This lives in `app/Services/NotificationService.php`.

---

## Notification Events

| Event | Trigger | Recipients | Title | Body | `data` payload |
|-------|---------|-----------|-------|------|----------------|
| Turn submitted | `POST /turn/submit` (active game) | Next human player | "Your Turn!" | "It's your turn in {game.name}" | `{ game_id, event: "your_turn" }` |
| Game started | `startGame()` | First human player | "Game Started!" | "'{game.name}' has started — it's your first turn!" | `{ game_id, event: "game_started" }` |
| Game invite | `POST /api/games` | Each invited user | "Game Invite" | "{creator_username} invited you to play" | `{ game_id, event: "invite_received" }` |
| Invite accepted | `POST /invites/{id}/accept` | Game creator | "Invite Accepted" | "{username} accepted your invite" | `{ game_id, event: "invite_accepted" }` |
| Invite declined | `POST /invites/{id}/decline` | Game creator | "Invite Declined" | "{username} declined — game cancelled" | `{ game_id, event: "game_cancelled" }` |
| Game finished (winner) | `POST /turn/submit` (finished) | Winner | "Victory!" | "You won '{game.name}'!" | `{ game_id, event: "game_finished" }` |
| Game finished (losers) | `POST /turn/submit` (finished) | All other human players | "Game Over" | "'{game.name}' has ended" | `{ game_id, event: "game_finished" }` |

---

## Token Management

- The client registers its Expo push token via `POST /api/push-token`.
- Tokens are stored in `users.expo_push_token`.
- A user without a token (NULL) simply receives no notifications — never error.
- The format is `ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]`.

---

## Failure Handling (Initial Build)

Notification delivery is fire-and-forget. HTTP errors from Expo are not retried or surfaced to the client. The turn/invite logic completes regardless of notification success.

Future enhancement: add a queue with retry logic if notification reliability becomes a concern.

---

## No Queues (Initial Build)

Notifications are sent synchronously within the request cycle. This is acceptable for an initial build with low traffic. See `queues.md` for the future plan.
