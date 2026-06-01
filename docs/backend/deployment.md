# Deployment

## Server Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 11 |
| MySQL | 8+ |
| Node.js | 18+ (LTS) |
| Composer | 2.x |

Node.js must be installed globally and available on the system `PATH` so Laravel's `Process::run(...)` can invoke `node engine/init-game.js`.

---

## Initial Setup

```bash
composer create-project laravel/laravel strategic-commander-api
cd strategic-commander-api
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

---

## Environment Variables (`.env`)

```dotenv
APP_NAME="Strategic Commander API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=strategic_commander
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

QUEUE_CONNECTION=sync
```

---

## Key Config Changes

### `config/sanctum.php`

```php
'expiration' => null,  // Tokens never expire
```

### `config/cors.php`

```php
'paths'            => ['api/*'],
'allowed_origins'  => ['*'],  // Tighten to app domain in production
'allowed_methods'  => ['*'],
'allowed_headers'  => ['*'],
```

---

## Engine Script

Place the compiled Node.js CLI at:

```
{project_root}/engine/init-game.js
```

This script is built from the frontend repository (`src/game/`) using a TypeScript compiler or bundler (e.g. `esbuild`). The frontend team is responsible for providing it. The backend team is responsible for deploying it alongside the Laravel application.

Verify it works:

```bash
echo '{"mapConfig":{"mapSize":"medium","mapWidth":286,"mapHeight":286,"planetCount":10,"seed":12345,"galaxyShape":"scattered"},"playerSlots":[{"type":"human","name":"Dan"},{"type":"ai","name":"Bot","difficulty":"normal"}]}' | node engine/init-game.js
```

Expected: a valid `GameState` JSON object printed to stdout.

---

## Migrations

Run in this order (already handled by Laravel's migration system, which runs by timestamp):

1. `create_users_table`
2. `create_friendships_table`
3. `create_games_table`
4. `create_game_players_table`
5. `create_game_invites_table`
6. `create_turns_table`

```bash
php artisan migrate
```

---

## No Queue Workers Needed (Initial Build)

`QUEUE_CONNECTION=sync` means all jobs run synchronously in the request. No `php artisan queue:work` process is needed.

---

## Hosting Recommendation

Any standard PHP + MySQL host works. Minimum requirements:
- VPS or managed PHP hosting (Laravel Forge, Ploi, DigitalOcean, etc.)
- Node.js available on the server
- SSL/TLS (required for Expo push token transmission)
