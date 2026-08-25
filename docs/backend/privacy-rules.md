# Privacy Rules

Gaza Galaxy is a competitive turn-based game. Players must not be able to see each other's in-progress decisions. The following rules are mandatory and must be enforced at the API layer.

---

## Rule 1: Mid-Turn State Is Private

`in_progress_actions` (the mid-turn save blob) is **only returned to the player whose turn it currently is**.

**Enforcement in `GET /api/games/{id}`:**

```php
$isMyTurn = ($game->current_user_id === $authUser->id);

$inProgressActions = null;
if ($isMyTurn) {
    $turn = Turn::where('game_id', $game->id)
        ->where('user_id', $authUser->id)
        ->where('turn_number', $game->turn_number)
        ->where('round_number', $game->round_number)
        ->whereNull('submitted_at')
        ->first();

    if ($turn && $turn->in_progress_actions_json !== null) {
        $inProgressActions = json_decode($turn->in_progress_actions_json, true);
    }
}

return response()->json([
    // ...
    'is_my_turn'          => $isMyTurn,
    'in_progress_actions' => $inProgressActions,  // null for all non-current players
]);
```

If `is_my_turn = false`, `in_progress_actions` must be `null` regardless of what is in the database.

---

## Rule 2: Membership Verification

Every `GET /api/games/{id}` and all turn endpoints must verify the requesting user is a member of the game:

```php
$isPlayer = GamePlayer::where('game_id', $game->id)
    ->where('user_id', $authUser->id)
    ->exists();

if (!$isPlayer) {
    abort(403);
}
```

Non-members must receive `403`, not `404`. (Returning `404` would leak information about whether a game ID exists.)

---

## Rule 3: Turn Submission Authorization

`POST /api/games/{id}/turn/save` and `POST /api/games/{id}/turn/submit` must verify:

```php
if ($game->current_user_id !== $authUser->id) {
    abort(403);  // or 409 if turn has already advanced
}
```

A player cannot save or submit a turn that is not currently theirs.

---

## Rule 4: No `state_json` in Game List

`GET /api/games` (the list endpoint) never returns `state_json`. Only `GET /api/games/{id}` (the detail endpoint, with membership verification) returns the full state.

`GET /api/games/open` lists waiting matchmaking lobbies for any signed-in user, but only settings and seat counts — never `state_json` or mid-turn data.

---

## Rule 5: Invite Privacy

`GET /api/invites` returns only invites where `invitee_id = me`. A user cannot see game invites addressed to other users.

`POST /api/invites/{id}/accept` and `POST /api/invites/{id}/decline` verify `invitee_id = me`. If not, return `404` (not `403`) to avoid revealing whether the invite ID exists.

---

## Rule 6: Friend Request Privacy

`GET /api/friends/requests` returns only requests where `addressee_id = me`. Outgoing pending requests are not listed here.

---

## Summary Table

| Data | Who Can Access |
|------|---------------|
| `state_json` | Any member of the game (via `GET /api/games/{id}`) |
| `in_progress_actions` | Only the current player, only on their turn |
| `resulting_state_json` (in `turns`) | Not exposed via API (backend-only audit log) |
| `submitted_actions_json` (in `turns`) | Not exposed via API (backend-only audit log) |
| Game invite details | Only the invitee |
| Friend requests | Only the addressee |
