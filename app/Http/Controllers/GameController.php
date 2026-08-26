<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\Game;
use App\Models\GameInvite;
use App\Models\GamePlayer;
use App\Models\Turn;
use App\Models\User;
use App\Services\GameService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    public function __construct(
        private GameService $gameService,
        private NotificationService $notificationService
    ) {}
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $gameIds = GamePlayer::where('user_id', $me->id)->pluck('game_id');
        $games = Game::whereIn('id', $gameIds)->with(['players.user'])->get();

        $gamesPayload = $games->map(function (Game $game) use ($me) {
            $player = $game->players->firstWhere('user_id', $me->id);
            $iAmForfeited = $player?->is_forfeited === true;
            $isMyTurn = $game->current_user_id === $me->id && ! $iAmForfeited;
            $hasInProgressActions = Turn::where('game_id', $game->id)
                ->where('user_id', $me->id)
                ->where('turn_number', $game->turn_number)
                ->where('round_number', $game->round_number)
                ->whereNotNull('in_progress_actions_json')
                ->exists();

            $alertState = match (true) {
                $game->status === 'waiting_for_players' => 'waiting_for_players',
                $game->status === 'finished' => 'finished',
                $game->status === 'in_progress' && $isMyTurn && $hasInProgressActions => 'in_progress',
                $game->status === 'in_progress' && $isMyTurn => 'your_turn',
                $game->status === 'in_progress' => 'waiting',
            };

            $currentPlayer = $game->players->firstWhere('user_id', $game->current_user_id);

            return [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'play_mode' => 'async_multiplayer',
                'alert_state' => $alertState,
                'is_my_turn' => $isMyTurn,
                'has_in_progress_actions' => $hasInProgressActions,
                'winner_user_id' => $game->winner_user_id,
                'is_open_lobby' => $this->isMatchmakingLobby($game),
                'map_config' => $this->decodeMapConfig($game),
                'players' => $game->players->map(fn (GamePlayer $p) => $this->serializePlayer($p))->values(),
                'current_player_name' => $currentPlayer?->name,
                'round_number' => $game->round_number,
                'turn_number' => $game->turn_number,
                'created_at' => $game->created_at->toIso8601String(),
                'unread_message_count' => $this->unreadMessageCount($game->id, $me->id, $player?->last_read_message_id),
                'blocked_players' => $this->serializeBlockedPlayers($game, $me),
            ];
        })->values();

        return response()->json(['games' => $gamesPayload]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'map_config' => ['required', 'array'],
            'player_slots' => ['required', 'array', 'min:2'],
            'player_slots.*.type' => ['required', 'in:human,ai'],
            'player_slots.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'player_slots.*.name' => ['required', 'string', 'max:50'],
            // Client-generated initial state; when present the engine script is skipped.
            'state_json' => ['nullable', 'string'],
        ]);

        $me = $request->user();

        $slotZero = $validated['player_slots'][0] ?? null;
        if (
            ($slotZero['type'] ?? null) !== 'human' ||
            (
                ($slotZero['user_id'] ?? null) !== null &&
                ($slotZero['user_id'] ?? null) != $me->id
            )
        ) {
            return response()->json(['message' => 'Slot 0 must be the creator'], 422);
        }

        if (($validated['player_slots'][0]['user_id'] ?? null) === null) {
            $validated['player_slots'][0]['user_id'] = $me->id;
        }

        $openHumanCount = 0;
        $invitedHumanCount = 0;
        foreach ($validated['player_slots'] as $slot) {
            if (($slot['type'] ?? null) !== 'human') {
                continue;
            }
            $slotUserId = $slot['user_id'] ?? null;
            if ($slotUserId == $me->id) {
                continue;
            }
            if ($slotUserId === null) {
                $openHumanCount++;
            } else {
                $invitedHumanCount++;
            }
        }

        if ($openHumanCount > 0 && $invitedHumanCount > 0) {
            return response()->json(['message' => 'Cannot mix invited friends and open seats'], 422);
        }

        $isOpenLobby = $openHumanCount > 0;

        foreach ($validated['player_slots'] as $slot) {
            if ($slot['type'] !== 'human') {
                continue;
            }
            $slotUserId = $slot['user_id'] ?? null;
            if ($slotUserId === null) {
                continue;
            }
            if (! $this->isAcceptedFriend($me->id, $slotUserId)) {
                return response()->json(['message' => 'All human players must be accepted friends'], 422);
            }
        }

        $clientStateJson = $isOpenLobby ? null : ($validated['state_json'] ?? null);

        try {
            $result = DB::transaction(function () use ($validated, $me, $clientStateJson, $isOpenLobby) {
                $game = Game::create([
                    'name' => $validated['name'],
                    'status' => 'waiting_for_players',
                    'map_config_json' => json_encode($validated['map_config']),
                    // Invite games store client-generated state so startGame() can skip
                    // the engine script. Open lobbies stay empty until the roster fills.
                    'state_json' => $clientStateJson,
                    'created_by_user_id' => $me->id,
                    'current_user_id' => null,
                    'turn_number' => 1,
                    'round_number' => 1,
                ]);

                $players = [];
                $invites = [];

                foreach ($validated['player_slots'] as $turnOrder => $slot) {
                    if ($slot['type'] === 'ai') {
                        $players[] = GamePlayer::create([
                            'game_id' => $game->id,
                            'user_id' => null,
                            'name' => $slot['name'],
                            'turn_order' => $turnOrder,
                            'is_ai' => true,
                        ]);
                    } else {
                        $slotUserId = $slot['user_id'] ?? null;
                        $players[] = GamePlayer::create([
                            'game_id' => $game->id,
                            'user_id' => $slotUserId,
                            'name' => $slotUserId === null
                                ? (filled($slot['name'] ?? null) && ($slot['name'] !== 'Open') ? $slot['name'] : 'Open')
                                : $slot['name'],
                            'turn_order' => $turnOrder,
                            'is_ai' => false,
                        ]);

                        if ($slotUserId !== null && $slotUserId != $me->id) {
                            $invites[] = GameInvite::create([
                                'game_id' => $game->id,
                                'inviter_id' => $me->id,
                                'invitee_id' => $slotUserId,
                                'player_slot_index' => $turnOrder,
                                'status' => 'pending',
                            ]);
                        }
                    }
                }

                if (! $isOpenLobby) {
                    $this->gameService->startGame($game);
                    $game->refresh();
                }

                return compact('game', 'players', 'invites');
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Game engine error: ' . $e->getMessage()], 500);
        }

        foreach ($result['invites'] as $invite) {
            $invitee = User::find($invite->invitee_id);

            if ($invitee) {
                $this->notificationService->sendPushNotification(
                    $invitee,
                    config('app.name'),
                    "You've been invited to play {$result['game']->name}!",
                    ['game_id' => $result['game']->id, 'event' => 'invite_received']
                );
            }
        }

        $game = $result['game'];
        $isMyTurn = $game->current_user_id === $me->id;
        $alertState = match (true) {
            $game->status === 'waiting_for_players' => 'waiting_for_players',
            $game->status === 'finished' => 'finished',
            $game->status === 'in_progress' && $isMyTurn => 'your_turn',
            $game->status === 'in_progress' => 'waiting',
            default => 'waiting_for_players',
        };
        $currentPlayer = collect($result['players'])->firstWhere('user_id', $game->current_user_id);

        return response()->json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'play_mode' => 'async_multiplayer',
                'alert_state' => $alertState,
                'is_my_turn' => $isMyTurn,
                'has_in_progress_actions' => false,
                'winner_user_id' => $game->winner_user_id,
                'is_open_lobby' => $isOpenLobby,
                'map_config' => $this->decodeMapConfig($game),
                'players' => collect($result['players'])->map(fn (GamePlayer $player) => $this->serializePlayer($player))->values(),
                'current_player_name' => $currentPlayer?->name,
                'round_number' => $game->round_number,
                'turn_number' => $game->turn_number,
                'created_at' => $game->created_at->toIso8601String(),
            ],
            'state_json' => $game->state_json,
        ], 201);
    }

    public function show(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();

        $myPlayer = GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->first();
        if (! $myPlayer) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $iAmForfeited = $myPlayer->is_forfeited === true;
        $isMyTurn = $game->current_user_id === $me->id && ! $iAmForfeited;
        $hasInProgressActions = Turn::where('game_id', $game->id)
            ->where('user_id', $me->id)
            ->where('turn_number', $game->turn_number)
            ->where('round_number', $game->round_number)
            ->whereNotNull('in_progress_actions_json')
            ->exists();

        $alertState = match (true) {
            $game->status === 'waiting_for_players' => 'waiting_for_players',
            $game->status === 'finished' => 'finished',
            $game->status === 'in_progress' && $isMyTurn && $hasInProgressActions => 'in_progress',
            $game->status === 'in_progress' && $isMyTurn => 'your_turn',
            $game->status === 'in_progress' => 'waiting',
        };

        $inProgressActions = null;
        if ($isMyTurn) {
            $turn = Turn::where('game_id', $game->id)
                ->where('user_id', $me->id)
                ->where('turn_number', $game->turn_number)
                ->where('round_number', $game->round_number)
                ->whereNotNull('in_progress_actions_json')
                ->first();

            if ($turn) {
                $inProgressActions = json_decode($turn->in_progress_actions_json, true);
            }
        }

        // Determine which round to fetch events from.
        //
        // Active games: use the requesting user's own last submitted round.
        // The global max(round_number) would be wrong here — in a 2-player game,
        // Player B (who submits first each round) advances the round counter before
        // Player A (last submitter) even loads their result, so the global max
        // would point at B's new round and skip A's combat events entirely.
        //
        // Finished games: use the global max(round_number) across ALL submitted
        // turns. When a player is eliminated on an opponent's turn (before ever
        // getting to submit in that round), their own max round is N-1 while the
        // elimination combat events live in the opponent's round N turn record.
        // The "active game advancing" problem cannot occur on a finished game
        // because there is no next round to advance to.
        if ($game->status === 'finished') {
            $userLastRound = Turn::where('game_id', $game->id)
                ->whereNotNull('resulting_state_json')
                ->max('round_number');
        } else {
            $userLastRound = Turn::where('game_id', $game->id)
                ->where('user_id', $me->id)
                ->whereNotNull('resulting_state_json')
                ->max('round_number');
        }

        $latestEvents = [];
        if ($userLastRound !== null) {
            $roundTurns = Turn::where('game_id', $game->id)
                ->where('round_number', $userLastRound)
                ->whereNotNull('resulting_state_json')
                ->get();

            foreach ($roundTurns as $turn) {
                if ($turn->events_json !== null) {
                    $decoded = json_decode($turn->events_json, true);
                    if (is_array($decoded)) {
                        $latestEvents = array_merge($latestEvents, $decoded);
                    }
                }
            }
        }

        $finalStateJson = null;
        if ($game->status === 'finished') {
            $finalTurn = Turn::where('game_id', $game->id)
                ->whereNotNull('resulting_state_json')
                ->orderByDesc('turn_number')
                ->orderByDesc('round_number')
                ->first();
            if ($finalTurn) {
                $finalStateJson = json_decode($finalTurn->resulting_state_json, true);
            }
        }

        $game->load('players');

        return response()->json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'play_mode' => 'async_multiplayer',
                'round_number' => $game->round_number,
                'turn_number' => $game->turn_number,
                'unread_message_count' => $this->unreadMessageCount($game->id, $me->id, $myPlayer->last_read_message_id),
                'is_open_lobby' => $this->isMatchmakingLobby($game),
                'map_config' => $this->decodeMapConfig($game),
                'players' => $game->players->sortBy('turn_order')->values()->map(fn (GamePlayer $player) => $this->serializePlayer($player))->values(),
            ],
            'state_json' => $game->state_json,
            'is_my_turn' => $isMyTurn,
            'alert_state' => $alertState,
            'in_progress_actions' => $inProgressActions,
            'latest_events' => $latestEvents,
            'final_state_json' => $finalStateJson,
        ]);
    }

    public function update(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();

        if ($game->created_by_user_id != $me->id) {
            return response()->json(['message' => 'Only the creator can update this game'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $game->update([
            'name' => $validated['name'],
        ]);

        return response()->json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
            ],
        ]);
    }

    public function destroy(Request $request, Game $game)
    {
        $me = $request->user();

        if (! GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($game->created_by_user_id != $me->id) {
            return response()->json(['message' => 'Only the creator can delete this game'], 403);
        }

        $game->load(['players.user']);
        $wasWaitingLobby = $this->isMatchmakingLobby($game);
        $gameName = $game->name;
        $otherHumans = $game->players
            ->filter(fn (GamePlayer $p) => ! $p->is_ai && $p->user_id !== null && $p->user_id != $me->id);

        $game->delete();

        if ($wasWaitingLobby) {
            foreach ($otherHumans as $other) {
                if ($other->user === null) {
                    continue;
                }
                $this->notificationService->sendPushNotification(
                    $other->user,
                    $gameName,
                    'The lobby was cancelled.',
                    ['event' => 'game_cancelled']
                );
            }
        }

        return response()->json(['message' => 'Game deleted']);
    }

    public function openIndex(Request $request): JsonResponse
    {
        $me = $request->user();

        $games = Game::with(['players.user', 'createdBy'])
            ->where('status', 'waiting_for_players')
            ->where(function ($query) {
                $query->whereNull('state_json')->orWhere('state_json', '');
            })
            ->whereHas('players', function ($query) {
                $query->where('is_ai', false)->whereNull('user_id');
            })
            ->whereDoesntHave('players', function ($query) use ($me) {
                $query->where('user_id', $me->id);
            })
            ->orderByDesc('created_at')
            ->get();

        $payload = $games->map(fn (Game $game) => $this->serializeOpenLobby($game, $me))->values();

        return response()->json([
            'games' => $payload,
            'count' => $payload->count(),
        ]);
    }

    public function join(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $shouldStart = false;

        $locked = DB::transaction(function () use ($game, $me, &$shouldStart) {
                $locked = Game::where('id', $game->id)->lockForUpdate()->first();
                if ($locked === null) {
                    $this->abortJson(404, 'Not found');
                }
                $locked->load(['players', 'createdBy']);

                if ($locked->status !== 'waiting_for_players' || ! blank($locked->state_json)) {
                    $this->abortJson(422, 'This game is not open for joining');
                }

                if ($locked->players->contains(fn (GamePlayer $p) => $p->user_id == $me->id)) {
                    $this->abortJson(422, 'You are already in this game');
                }

                $openSeat = $locked->players
                    ->filter(fn (GamePlayer $p) => ! $p->is_ai && $p->user_id === null)
                    ->sortBy('turn_order')
                    ->first();

                if ($openSeat === null) {
                    $this->abortJson(409, 'Game is full');
                }

                $openSeat->update([
                    'user_id' => $me->id,
                    'name' => $me->username,
                ]);

                $locked->load(['players', 'createdBy']);
                $shouldStart = ! $locked->players->contains(
                    fn (GamePlayer $p) => ! $p->is_ai && $p->user_id === null
                );

                return $locked;
            });

        $locked->load(['players.user', 'createdBy']);

        foreach ($locked->players as $otherPlayer) {
            if (
                $otherPlayer->is_ai
                || $otherPlayer->user === null
                || $otherPlayer->user_id == $me->id
            ) {
                continue;
            }
            if (! Friendship::isBlocked($me->id, (int) $otherPlayer->user_id)) {
                continue;
            }
            $this->notificationService->sendPushNotification(
                $otherPlayer->user,
                $locked->name,
                "A commander you've blocked joined this lobby. They cannot message you.",
                ['game_id' => $locked->id, 'event' => 'blocked_player_joined']
            );
        }

        return response()->json([
            'joined' => true,
            'should_start' => $shouldStart,
            'game' => $this->serializeOpenLobby($locked, $me),
        ]);
    }

    public function leave(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();

        $locked = DB::transaction(function () use ($game, $me) {
            $locked = Game::where('id', $game->id)->lockForUpdate()->first();
                if ($locked === null) {
                    $this->abortJson(404, 'Not found');
                }
                $locked->load('players');

                if ($locked->created_by_user_id == $me->id) {
                    $this->abortJson(422, 'The creator cannot leave — delete the game instead');
                }

                if ($locked->status !== 'waiting_for_players' || ! blank($locked->state_json)) {
                    $this->abortJson(422, 'You can only leave a waiting lobby');
                }

                $player = $locked->players->firstWhere('user_id', $me->id);
                if ($player === null) {
                    $this->abortJson(403, 'Forbidden');
                }

            $player->update([
                'user_id' => null,
                'name' => 'Open',
            ]);

            return $locked;
        });

        $locked->load(['players', 'createdBy']);

        return response()->json([
            'left' => true,
            'game' => $this->serializeOpenLobby($locked, $me),
        ]);
    }

    public function startLobby(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'state_json' => ['required', 'string'],
        ]);

        $me = $request->user();

        $member = GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->first();
        if ($member === null) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $startedNow = false;

        try {
            DB::transaction(function () use ($game, $validated, &$startedNow) {
                $locked = Game::where('id', $game->id)->lockForUpdate()->first();
                if ($locked === null) {
                    $this->abortJson(404, 'Not found');
                }
                $locked->load('players');

                if ($locked->status === 'in_progress' && ! blank($locked->state_json)) {
                    return;
                }

                if ($locked->status !== 'waiting_for_players') {
                    $this->abortJson(422, 'Game cannot be started');
                }

                $openSeat = $locked->players->first(
                    fn (GamePlayer $p) => ! $p->is_ai && $p->user_id === null
                );
                if ($openSeat !== null) {
                    $this->abortJson(422, 'Lobby is not full');
                }

                $humanCount = $locked->players->filter(fn (GamePlayer $p) => ! $p->is_ai)->count();
                if ($humanCount < 2) {
                    $this->abortJson(422, 'Not a matchmaking lobby');
                }

                $locked->state_json = $validated['state_json'];
                $locked->save();
                $this->gameService->startGame($locked);
                $startedNow = true;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Game engine error: ' . $e->getMessage()], 500);
        }

        $game->refresh();
        $game->load(['players.user']);

        if ($startedNow) {
            $this->notifyMatchmakingStarted($game);
        }

        $isMyTurn = $game->current_user_id === $me->id;

        return response()->json([
            'started' => true,
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'play_mode' => 'async_multiplayer',
                'alert_state' => $isMyTurn ? 'your_turn' : 'waiting',
                'is_my_turn' => $isMyTurn,
                'has_in_progress_actions' => false,
                'winner_user_id' => $game->winner_user_id,
                'is_open_lobby' => false,
                'map_config' => $this->decodeMapConfig($game),
                'players' => $game->players->sortBy('turn_order')->values()->map(fn (GamePlayer $p) => $this->serializePlayer($p))->values(),
                'current_player_name' => $game->players->firstWhere('user_id', $game->current_user_id)?->name,
                'round_number' => $game->round_number,
                'turn_number' => $game->turn_number,
                'created_at' => $game->created_at->toIso8601String(),
            ],
            'state_json' => $game->state_json,
        ]);
    }

    public function forfeit(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->first();

        if ($player === null) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is not in progress'], 422);
        }

        if ($player->is_ai || $player->user_id === null) {
            return response()->json(['message' => 'Only human players can forfeit'], 422);
        }

        if ($player->is_eliminated) {
            return response()->json(['message' => 'Eliminated players cannot forfeit'], 422);
        }

        if ($player->is_forfeited) {
            return response()->json(['message' => 'Already sitting out'], 422);
        }

        $player->update(['is_forfeited' => true]);

        if ($game->current_user_id == $me->id) {
            Turn::where('game_id', $game->id)
                ->where('user_id', $me->id)
                ->where('turn_number', $game->turn_number)
                ->where('round_number', $game->round_number)
                ->whereNull('resulting_state_json')
                ->update(['in_progress_actions_json' => null]);
        }

        return response()->json(['forfeited' => true]);
    }

    public function rejoin(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->first();

        if ($player === null) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is not in progress'], 422);
        }

        if ($player->is_eliminated) {
            return response()->json(['message' => 'Eliminated players cannot rejoin'], 422);
        }

        if (! $player->is_forfeited) {
            return response()->json(['message' => 'You are not sitting out'], 422);
        }

        $player->update(['is_forfeited' => false]);

        return response()->json(['rejoined' => true]);
    }

    public function endGame(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->first();

        if ($player === null) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($player->is_ai || $player->user_id === null) {
            return response()->json(['message' => 'Only human players can end a game'], 422);
        }

        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is not in progress'], 422);
        }

        $state = json_decode($game->state_json ?? '', true);
        if (is_array($state)) {
            $state['status'] = 'finished';
            $state['winnerId'] = null;
            $game->state_json = json_encode($state);
        }

        $game->status = 'finished';
        $game->current_user_id = null;
        $game->winner_user_id = null;
        $game->save();

        $otherHumans = GamePlayer::where('game_id', $game->id)
            ->where('is_ai', false)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $me->id)
            ->with('user')
            ->get();

        $enderName = $player->name ?: $me->username;

        foreach ($otherHumans as $other) {
            if ($other->user === null) {
                continue;
            }

            $this->notificationService->sendPushNotification(
                $other->user,
                $game->name,
                "{$enderName} ended the game for all players.",
                ['game_id' => $game->id, 'event' => 'game_ended']
            );
        }

        return response()->json(['ended' => true]);
    }

    private function serializePlayer(GamePlayer $player): array
    {
        return [
            'in_game_name' => $player->name,
            'is_ai' => $player->is_ai,
            'is_eliminated' => $player->is_eliminated,
            'is_forfeited' => $player->is_forfeited,
            'user_id' => $player->user_id,
        ];
    }

    private function unreadMessageCount(int $gameId, int $meId, ?int $lastReadId): int
    {
        $blockedIds = Friendship::blockedUserIds($meId);

        return \App\Models\GameMessage::where('game_id', $gameId)
            ->whereNull('hidden_at')
            ->where(function ($query) use ($meId) {
                $query->whereNull('sender_user_id')
                    ->orWhere('sender_user_id', '!=', $meId);
            })
            ->when(count($blockedIds) > 0, function ($query) use ($blockedIds) {
                $query->where(function ($inner) use ($blockedIds) {
                    $inner->whereNull('sender_user_id')
                        ->orWhereNotIn('sender_user_id', $blockedIds);
                });
            })
            ->when($lastReadId !== null, fn ($q) => $q->where('id', '>', $lastReadId))
            ->count();
    }

    private function isMatchmakingLobby(Game $game): bool
    {
        if ($game->status !== 'waiting_for_players') {
            return false;
        }

        return blank($game->state_json);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMapConfig(Game $game): array
    {
        $decoded = json_decode($game->map_config_json ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOpenLobby(Game $game, ?User $viewer = null): array
    {
        $humans = $game->players->filter(fn (GamePlayer $p) => ! $p->is_ai);
        $host = $game->createdBy;

        return [
            'id' => $game->id,
            'name' => $game->name,
            'status' => $game->status,
            'host' => $host === null ? null : [
                'id' => $host->id,
                'username' => $host->username,
            ],
            'map_config' => $this->decodeMapConfig($game),
            'human_filled' => $humans->filter(fn (GamePlayer $p) => $p->user_id !== null)->count(),
            'human_total' => $humans->count(),
            'ai_count' => $game->players->filter(fn (GamePlayer $p) => $p->is_ai)->count(),
            'is_open_lobby' => $this->isMatchmakingLobby($game),
            'players' => $game->players
                ->sortBy('turn_order')
                ->values()
                ->map(fn (GamePlayer $p) => $this->serializePlayer($p))
                ->values(),
            'blocked_players' => $viewer === null ? [] : $this->serializeBlockedPlayers($game, $viewer),
            'created_at' => $game->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, username: string, in_game_name: string}>
     */
    private function serializeBlockedPlayers(Game $game, User $viewer): array
    {
        $blocked = [];
        foreach ($game->players as $player) {
            if ($player->is_ai || $player->user_id === null || $player->user_id == $viewer->id) {
                continue;
            }
            if (! Friendship::isBlocked($viewer->id, (int) $player->user_id)) {
                continue;
            }
            $blocked[] = [
                'id' => (int) $player->user_id,
                'username' => $player->user?->username ?? $player->name,
                'in_game_name' => $player->name,
            ];
        }

        return $blocked;
    }

    private function notifyMatchmakingStarted(Game $game): void
    {
        $firstUserId = $game->current_user_id;

        foreach ($game->players as $player) {
            if ($player->is_ai || $player->user === null) {
                continue;
            }

            if ($player->user_id == $firstUserId) {
                $this->notificationService->sendPushNotification(
                    $player->user,
                    'Game Started!',
                    "'{$game->name}' has started — it's your first turn!",
                    ['game_id' => $game->id, 'event' => 'game_started']
                );
            } else {
                $this->notificationService->sendPushNotification(
                    $player->user,
                    $game->name,
                    "'{$game->name}' has started. You'll be notified when it's your turn.",
                    ['game_id' => $game->id, 'event' => 'game_started']
                );
            }
        }
    }

    private function abortJson(int $status, string $message): never
    {
        abort(response()->json(['message' => $message], $status));
    }

    private function isAcceptedFriend(int $creatorId, int $userId): bool
    {
        if ($creatorId === $userId) {
            return true;
        }

        return Friendship::where('status', 'accepted')
            ->where(function ($query) use ($creatorId, $userId) {
                $query->where(function ($q) use ($creatorId, $userId) {
                    $q->where('requester_id', $creatorId)
                        ->where('addressee_id', $userId);
                })->orWhere(function ($q) use ($creatorId, $userId) {
                    $q->where('requester_id', $userId)
                        ->where('addressee_id', $creatorId);
                });
            })
            ->exists();
    }
}
