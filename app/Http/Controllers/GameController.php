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
        $games = Game::whereIn('id', $gameIds)->with('players')->get();

        $gamesPayload = $games->map(function (Game $game) use ($me) {
            $isMyTurn = $game->current_user_id === $me->id;
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
            $player = $game->players->firstWhere('user_id', $me->id);

            return [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'play_mode' => 'async_multiplayer',
                'alert_state' => $alertState,
                'is_my_turn' => $isMyTurn,
                'has_in_progress_actions' => $hasInProgressActions,
                'winner_user_id' => $game->winner_user_id,
                'players' => $game->players->map(fn (GamePlayer $player) => [
                    'in_game_name' => $player->name,
                    'is_ai' => $player->is_ai,
                    'is_eliminated' => $player->is_eliminated,
                    'user_id' => $player->user_id,
                ])->values(),
                'current_player_name' => $currentPlayer?->name,
                'round_number' => $game->round_number,
                'turn_number' => $game->turn_number,
                'created_at' => $game->created_at->toIso8601String(),
                'unread_message_count' => $this->unreadMessageCount($game->id, $me->id, $player->last_read_message_id),
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

        foreach ($validated['player_slots'] as $slot) {
            if ($slot['type'] === 'human' && ! $this->isAcceptedFriend($me->id, $slot['user_id'])) {
                return response()->json(['message' => 'All human players must be accepted friends'], 422);
            }
        }

        $clientStateJson = $validated['state_json'] ?? null;

        try {
            $result = DB::transaction(function () use ($validated, $me, $clientStateJson) {
                $game = Game::create([
                    'name' => $validated['name'],
                    'status' => 'waiting_for_players',
                    'map_config_json' => json_encode($validated['map_config']),
                    // Store client-provided state immediately so startGame() can use it
                    // without running the engine script.
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
                        $players[] = GamePlayer::create([
                            'game_id' => $game->id,
                            'user_id' => $slot['user_id'],
                            'name' => $slot['name'],
                            'turn_order' => $turnOrder,
                            'is_ai' => false,
                        ]);

                        if ($slot['user_id'] != $me->id) {
                            $invites[] = GameInvite::create([
                                'game_id' => $game->id,
                                'inviter_id' => $me->id,
                                'invitee_id' => $slot['user_id'],
                                'player_slot_index' => $turnOrder,
                                'status' => 'pending',
                            ]);
                        }
                    }
                }

                $this->gameService->startGame($game);
                $game->refresh();

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
                'players' => collect($result['players'])->map(fn (GamePlayer $player) => [
                    'in_game_name' => $player->name,
                    'is_ai' => $player->is_ai,
                    'is_eliminated' => $player->is_eliminated,
                ])->values(),
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

        $isMyTurn = $game->current_user_id === $me->id;
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

        return response()->json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'status' => $game->status,
                'play_mode' => 'async_multiplayer',
                'round_number' => $game->round_number,
                'turn_number' => $game->turn_number,
                'unread_message_count' => $this->unreadMessageCount($game->id, $me->id, $myPlayer->last_read_message_id),
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

        $game->delete();

        return response()->json(['message' => 'Game deleted']);
    }

    private function unreadMessageCount(int $gameId, int $meId, ?int $lastReadId): int
    {
        return \App\Models\GameMessage::where('game_id', $gameId)
            ->where('sender_user_id', '!=', $meId)
            ->when($lastReadId !== null, fn ($q) => $q->where('id', '>', $lastReadId))
            ->count();
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
