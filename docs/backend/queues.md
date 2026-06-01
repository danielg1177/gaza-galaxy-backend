# Queues

## Current Status: Not Implemented

The initial build uses **no queues**. All work (notifications, game initialization) is synchronous within the request cycle. Redis and Laravel Horizon are explicitly excluded from the initial build.

---

## Why Queues Are Deferred

- The initial player base is small.
- Synchronous Expo HTTP calls are fast (< 200ms typical).
- Node.js game init is fast (< 1s typical for map generation).
- Adding queues increases infrastructure complexity significantly.

---

## Future: When Queues Become Necessary

Add queues when any of the following occur:

1. **Push notification failures** — if Expo delivery becomes unreliable or batching is needed
2. **Game init latency** — if `startGame()` Node.js calls begin timing out on large maps
3. **Turn submission P99 latency** — if turn submissions are slow due to cascading notification sends

---

## Future Queue Plan

When implemented, use **Laravel Queues with a database driver** first (no Redis required), then migrate to Redis if throughput demands it.

### Proposed Jobs

| Job | Trigger | Payload |
|-----|---------|---------|
| `SendPushNotification` | Any notification event | `token`, `title`, `body`, `data` |
| `InitializeGame` | All invites accepted or no-invite game created | `game_id` |

### Configuration (future)

```php
// config/queue.php
'default' => env('QUEUE_CONNECTION', 'database'),
```

```bash
php artisan queue:table
php artisan migrate
php artisan queue:work
```

### Horizon (future)

Add Laravel Horizon for monitoring and retry dashboards once Redis is in place.

---

## Do Not Add Queues Until Needed

Following the principle of minimal infrastructure: do not introduce queues speculatively. The synchronous approach works and is easier to debug. Add queues only when a concrete performance or reliability problem is observed.
