# Authentication

Strategic Commander uses **Laravel Sanctum** for API token authentication. There is no email, no OAuth, and no password reset flow. Registration and login are username + password only.

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

## Token Lifecycle

| Rule | Detail |
|------|--------|
| Expiration | Never. `'expiration' => null` in `config/sanctum.php`. |
| Storage | Client stores in AsyncStorage; used indefinitely. |
| Invalidation | Explicit logout only, or when a new login replaces all prior tokens. |
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
- Password resets are admin-only (manual database update).
- Username is the sole identifier for login and friend search.
