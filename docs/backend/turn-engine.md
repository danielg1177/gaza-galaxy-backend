# Turn Engine

The backend does not execute any game logic. Game rules, combat, fleet movement, and AI behaviour are all computed by the TypeScript engine on the client. The backend's role in turns is to:

1. Validate identity and turn ownership
2. Persist mid-turn saves
3. Accept and store submitted state
4. Advance game pointers (`current_user_id`, `turn_number`, `round_number`)
5. Detect game completion and update `game_players.is_eliminated`
6. Fire push notifications

---

## alert_state Computation

Used in both `GET /api/games` and `GET /api/games/{id}`.

```
IF games.status = 'waiting'          → 'waiting_for_players'
IF games.status = 'finished'         → 'finished'
IF games.status = 'active':
  IF games.current_user_id != me     → 'waiting'
  IF games.current_user_id = me:
    Look up turns WHERE:
      game_id = games.id
      AND user_id = me
      AND turn_number = games.turn_number
      AND round_number = games.round_number
      AND submitted_at IS NULL
    IF no row found                               → 'your_turn'
    IF row.in_progress_actions_json IS NULL       → 'your_turn'
    IF row.in_progress_actions_json IS NOT NULL   → 'in_progress'
```

---

## Mid-Turn Save (`POST /api/games/{id}/turn/save`)

1. Verify `games.current_user_id = me`. Return `403` if not (or `409` if the turn has already advanced).
2. Upsert `turns` row keyed on `(game_id, user_id, turn_number, round_number)`:
   - Set `in_progress_actions_json = JSON(request.in_progress_actions)`
   - Set `started_at = NOW()` only if currently NULL

The `in_progress_actions` blob:
```json
{
  "partial_state_json": "{...full current GameState at moment of exit...}",
  "queued_orders": [
    { "fromPlanetId": "p-3", "toPlanetId": "p-7", "shipCount": 50 }
  ]
}
```

`partial_state_json` includes all mutations from this turn (builds placed, sliders changed). `queued_orders` are staged fleet dispatches not yet committed to the state.

---

## Turn Submit (`POST /api/games/{id}/turn/submit`)

### Validation
1. `games.current_user_id = me` → `403` if not.
2. `turn_number` in request must equal `games.turn_number` → `409` if not.
3. `round_number` in request must equal `games.round_number` → `409` if not.
4. `resulting_state` must contain: `map`, `players`, `fleets`, `currentPlayerId`, `status`, `roundNumber` (structural check only).
5. Optional `events` array (`nullable|array`) — client-computed turn events (`TurnEvent[]`). When present, stored as `events_json` on the turn row; when absent or null, `events_json` is NULL.

### State Advancement

The client has already run all AI turns before submitting. `resulting_state.currentPlayerId` is the next human player's engine ID (e.g. `"player-2"`).

1. Parse the integer index from `currentPlayerId` (e.g. `"player-2"` → `2`).
2. Look up `game_players` where `turn_order = 2`. That row's `user_id` is the next `current_user_id`.
3. Update `games`:
   - `state_json = JSON(resulting_state)`
   - `current_user_id = next_human_user_id`
   - `turn_number = turn_number + 1`
   - `round_number = resulting_state.roundNumber`
4. If `resulting_state.status = 'finished'`:
   - `games.status = 'finished'`
   - Find the non-eliminated player in `resulting_state.players`; match to `game_players` by `turn_order` → set `games.winner_user_id`

### Elimination Updates

For each `resulting_state.players[i]` where `isEliminated = true`, update `game_players` where `turn_order = i` to set `is_eliminated = 1`.

### Turn Record

Upsert the `turns` row:
- `submitted_actions_json = JSON(actions)`
- `resulting_state_json = JSON(resulting_state)`
- `events_json = JSON(events)` when `events` is provided; otherwise NULL
- `submitted_at = NOW()`
- `in_progress_actions_json = NULL`
- `started_at = COALESCE(started_at, NOW())`

### Notifications (after all DB writes)

- Active game: send "Your Turn!" to the next human player
- Finished game: send "Victory!" to winner; send "Game Over" to all other human players

---

## Turn Abandon (`POST /api/games/{id}/turn/abandon`)

Clears the mid-turn save without ending the turn.

Find the unsubmitted `turns` row for `(game_id, user_id, turn_number, round_number, submitted_at IS NULL)`. If found, set `in_progress_actions_json = NULL`.

Does NOT advance `current_user_id` or increment `turn_number`.

---

## Game Initialization (`startGame`)

Called when:
- A game is created with no pending invites (solo human + AI)
- The last pending game invite is accepted

### Node.js Engine Bridge

The TypeScript game engine is compiled to a standalone CLI at `engine/init-game.js`. Laravel calls it synchronously:

```php
function startGame(Game $game): void {
    $mapConfig = json_decode($game->map_config_json, true);
    $playerSlots = GamePlayer::where('game_id', $game->id)
        ->orderBy('turn_order')
        ->get()
        ->map(fn($p) => [
            'type'       => $p->is_ai ? 'ai' : 'human',
            'name'       => $p->in_game_name,
            'difficulty' => $p->ai_difficulty,
        ])
        ->toArray();

    $input = json_encode(['mapConfig' => $mapConfig, 'playerSlots' => $playerSlots]);
    $result = Process::run("echo " . escapeshellarg($input) . " | node " . base_path('engine/init-game.js'));

    if ($result->failed()) {
        throw new \RuntimeException("Game engine failed: " . $result->errorOutput());
    }

    $initialState = json_decode($result->output(), true);

    // Set home_planet_id on each game_players row
    foreach ($initialState['map']['planets'] as $planet) {
        if ($planet['isHomePlanet'] && $planet['owner'] !== 'neutral') {
            preg_match('/player-(\d+)/', $planet['owner'], $matches);
            $index = (int) $matches[1];
            GamePlayer::where('game_id', $game->id)
                ->where('turn_order', $index)
                ->update(['home_planet_id' => $planet['id']]);
        }
    }

    // Determine first human player
    $firstPlayer = GamePlayer::where('game_id', $game->id)->where('turn_order', 0)->first();
    $firstHumanUserId = $firstPlayer->is_ai ? null : $firstPlayer->user_id;

    $game->update([
        'status'          => 'active',
        'state_json'      => json_encode($initialState),
        'current_user_id' => $firstHumanUserId,
        'turn_number'     => 1,
        'round_number'    => 1,
    ]);

    // Notify first human player
    if ($firstHumanUserId) {
        $firstUser = User::find($firstHumanUserId);
        sendPushNotification($firstUser->expo_push_token, "Game Started!",
            "Your game '{$game->name}' has started — it's your first turn!",
            ['game_id' => $game->id, 'event' => 'game_started']);
    }
}
```

### What the CLI Does

- Reads `{ mapConfig, playerSlots }` from stdin
- Runs `generateMap(mapConfig)` with seeded RNG
- Runs `placeSpawns(map, playerSlots)`
- Constructs the initial `GameState` matching `gameStore.startNewGame` on the client
- Writes full initial `GameState` JSON to stdout

The script is built from `src/game/` in the frontend repository and must be placed at `engine/init-game.js` in the backend root.

---

## Key Rules

- The backend **never re-runs** the game engine on turn submission.
- `state_json` is always the submitted `resulting_state`, stored as-is.
- `currentPlayerId` in the submitted state is always a human player (the client resolves all AI turns first).
- If `resulting_state.status = 'finished'`, the game ends immediately — no further turns are possible.

---

## Pass-and-Play Turn Handoff

In pass-and-play mode (local multiplayer on a single device):
- When a human player ends their turn, the frontend runs `endTurn()` which resolves all AI turns and advances `currentPlayerId` to the next human player
- The lock screen ("Pass the device") is displayed showing the next player's name and round number
- **Auto-handoff:** The lock screen automatically dismisses after 1.5 seconds, displaying the next player's turn without requiring manual interaction
- The "Start Turn" button remains visible and functional for manual override if the device holder wants to delay
- Any intermediate game logic (AI turns, arrivals, production) happens during the `endTurn()` calculation before the lock screen appears
