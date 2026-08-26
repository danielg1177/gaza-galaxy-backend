# Authentication

Gaza Galaxy uses **Laravel Sanctum** for API token authentication. There is no email, no OAuth, and no password reset flow. Registration and login are username + password only.

---

## Registration

`POST /api/auth/register` — public, no auth required.

**Validation:**
- `username`: required | string | min:3 | max:30 | regex:`/^[a-zA-Z0-9_]+$/` | unique in `users`
- `password`: required | string | min:6 | confirmed

**Logic:**
1. Create `users` row with `password = Hash::make($request->password)`.
2. Create a Sanctum personal access token: `$user->createToken('mobile')->plainTextToken`.
3. Return the plain-text token — it cannot be retrieved again after this response.

---

## Login

`POST /api/auth/login` — public.

**Logic:**
1. Find user by exact `username` (case-sensitive).
2. `Hash::check($request->password, $user->password)` — return `401` if false.
3. Delete all existing tokens for this user (single active session per device).
4. Create a new Sanctum token and return it.

---

## Logout

`POST /api/auth/logout` — auth required.

Deletes only the current token: `$request->user()->currentAccessToken()->delete()`.

---

## Current User

`GET /api/auth/me` — auth required.

Returns `{ "id": 1, "username": "commander_dan" }`.

---

## Change Username

`PATCH /api/auth/username` — auth required.

**Validation:**
- `username`: required | string | min:3 | max:32 | regex:`/^[a-zA-Z0-9_]+$/` | unique in `users` except the authenticated user

**Logic:**
1. Update `users.username`.
2. Return `{ "id", "username" }`.
3. Do **not** rewrite `game_players` commander names or `state_json` player names. Username is the account login identity; in-game names are per-campaign.

---

## Change Password

`PATCH /api/auth/password` — auth required.

**Validation:**
- `current_password`: required | string
- `password`: required | string | min:6 | confirmed

**Logic:**
1. `Hash::check($request->current_password, $user->password)` — return **422** if false (not 401; 401 would log the client out).
2. Update `password` (hashed via the User model cast).
3. Keep the current Sanctum token. Other devices stay signed in.

There is still no email-based password reset. This endpoint is for a signed-in user who knows their current password.

---

## Account Deletion

`DELETE /api/auth/account` — auth required.

**Validation:**
- `current_password`: required | string

**Logic:**
1. `Hash::check` current password — **422** if false (not 401).
2. `AccountDeletionService` settles live games, then deletes the user.
3. Waiting games they hosted are deleted. Open-lobby seats they joined are released. Pending invites they received are declined (cancels those games).
4. In-progress: permanent forfeit, commander name becomes `Former Commander`, creator transfers to the next remaining human. If it is their turn, `current_user_id` moves to the next playing human (action phase skipped). If they are the last playing human, the match finishes with no winner.
5. All Sanctum tokens are revoked. Push fields are cleared.

Local pass-and-play campaigns on the device are not server data and are not wiped.

---

## Token Lifecycle

| Rule | Detail |
|------|--------|
| Expiration | Never. `'expiration' => null` in `config/sanctum.php`. |
| Storage | Client stores in AsyncStorage; used indefinitely. |
| Invalidation | Explicit logout, account deletion, or when a new login replaces all prior tokens. |
| Format | `{id}|{token}` — sent as `Authorization: Bearer {token}` header. |

---

## User Model Requirements

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    use HasApiTokens;

    protected $fillable = ['username', 'password', 'expo_push_token'];

    protected $casts = [
        'password' => 'hashed',
    ];
}
```

---

## No Email / No Password Reset

- There is no `email` column.
- Unauthenticated password resets are admin-only (manual database update).
- A signed-in user can change their password via `PATCH /api/auth/password` if they know the current password.
- Username is the sole identifier for login and friend search.
