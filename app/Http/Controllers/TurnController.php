<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameInvite;
use App\Models\GamePlayer;
use App\Models\Turn;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TurnController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}
    public function save(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();

        if (! GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is not in progress'], 422);
        }

        if ($game->current_user_id != $me->id) {
            return response()->json(['message' => 'It is not your turn'], 403);
        }

        $request->validate([
            'in_progress_actions' => ['required', 'array'],
            'in_progress_actions.partial_state_json' => ['required', 'string'],
            'in_progress_actions.queued_orders' => ['present', 'array'],
        ]);

        Turn::updateOrCreate(
            [
                'game_id' => $game->id,
                'user_id' => $me->id,
                'turn_number' => $game->turn_number,
                'round_number' => $game->round_number,
            ],
            ['in_progress_actions_json' => json_encode($request->in_progress_actions)]
        );

        return response()->json(['saved' => true]);
    }

    public function abandon(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();

        if (! GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is not in progress'], 422);
        }

        if ($game->current_user_id != $me->id) {
            return response()->json(['message' => 'It is not your turn'], 403);
        }

        Turn::where('game_id', $game->id)
            ->where('user_id', $me->id)
            ->where('turn_number', $game->turn_number)
            ->where('round_number', $game->round_number)
            ->delete();

        return response()->json(['abandoned' => true]);
    }

    public function submit(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();

        if (! GamePlayer::where('game_id', $game->id)->where('user_id', $me->id)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($game->status !== 'in_progress') {
            return response()->json(['message' => 'Game is not in progress'], 422);
        }

        if ($game->current_user_id != $me->id) {
            return response()->json(['message' => 'It is not your turn'], 403);
        }

        $request->validate([
            'turn_number' => ['required', 'integer'],
            'round_number' => ['required', 'integer'],
            'actions' => ['required', 'array'],
            'resulting_state' => ['required', 'array'],
            'events' => ['nullable', 'array'],
        ]);

        if ($request->turn_number != $game->turn_number || $request->round_number != $game->round_number) {
            return response()->json(['message' => 'Stale turn data'], 409);
        }

        $state = $request->resulting_state;

        foreach (['status', 'currentPlayerId', 'roundNumber', 'players'] as $key) {
            if (! array_key_exists($key, $state)) {
                return response()->json(['message' => 'Invalid state payload'], 422);
            }
        }

        DB::transaction(function () use ($request, $game, $me, $state) {
            Turn::updateOrCreate(
                [
                    'game_id' => $game->id,
                    'user_id' => $me->id,
                    'turn_number' => $game->turn_number,
                    'round_number' => $game->round_number,
                ],
                [
                    'submitted_actions_json' => json_encode($request->actions),
                    'resulting_state_json' => json_encode($request->resulting_state),
                    'events_json' => $request->events !== null ? json_encode($request->events) : null,
                    'in_progress_actions_json' => null,
                ]
            );

            if ($state['status'] === 'finished') {
                $winnerUserId = null;

                foreach ($state['players'] as $player) {
                    if (($player['isEliminated'] ?? true) === false) {
                        if (preg_match('/player-(\d+)/', $player['id'], $matches)) {
                            $gamePlayer = GamePlayer::where('game_id', $game->id)
                                ->where('turn_order', (int) $matches[1])
                                ->first();

                            if ($gamePlayer) {
                                $winnerUserId = $gamePlayer->user_id;
                            }
                        }
                        break;
                    }
                }

                $game->update([
                    'status' => 'finished',
                    'state_json' => json_encode($request->resulting_state),
                    'current_user_id' => null,
                    'winner_user_id' => $winnerUserId,
                ]);

                $this->updateEliminations($game, $state['players']);

                return;
            }

            preg_match('/player-(\d+)/', $state['currentPlayerId'], $matches);
            $nextPlayer = GamePlayer::where('game_id', $game->id)
                ->where('turn_order', (int) $matches[1])
                ->first();

            $newTurnNumber  = isset($state['turnNumber'])  ? (int) $state['turnNumber']  : ($game->turn_number + 1);
            $newRoundNumber = isset($state['roundNumber']) ? (int) $state['roundNumber'] : $game->round_number;

            $game->update([
                'state_json' => json_encode($request->resulting_state),
                'current_user_id' => $nextPlayer->user_id,
                'turn_number' => $newTurnNumber,
                'round_number' => $newRoundNumber,
            ]);

            $this->updateEliminations($game, $state['players']);
        });

        $game->refresh();

        if ($game->status === 'finished' && $game->winner_user_id) {
            $humanPlayers = GamePlayer::where('game_id', $game->id)
                ->where('is_ai', false)
                ->whereNotNull('user_id')
                ->with('user')
                ->get();

            foreach ($humanPlayers as $player) {
                $body = $player->user_id === $game->winner_user_id
                    ? "You won {$game->name}!"
                    : "You lost {$game->name}.";

                $this->notificationService->sendPushNotification(
                    $player->user,
                    'Strategic Commander',
                    $body
                );
            }
        } elseif ($game->current_user_id) {
            $nextUserId = $game->current_user_id;

            $hasPendingInvite = GameInvite::where('game_id', $game->id)
                ->where('invitee_id', $nextUserId)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingInvite) {
                $game->status = 'waiting_for_players';
                $game->save();
            } else {
                $nextUser = User::find($game->current_user_id);

                if ($nextUser) {
                    $this->notificationService->sendPushNotification(
                        $nextUser,
                        'Strategic Commander',
                        "It's your turn in {$game->name}!"
                    );
                }
            }
        }

        return response()->json(['message' => 'Turn submitted']);
    }

    private function updateEliminations(Game $game, array $players): void
    {
        foreach ($players as $player) {
            if (($player['isEliminated'] ?? false) !== true) {
                continue;
            }

            if (! preg_match('/player-(\d+)/', $player['id'], $matches)) {
                continue;
            }

            GamePlayer::where('game_id', $game->id)
                ->where('turn_order', (int) $matches[1])
                ->update(['is_eliminated' => true]);
        }
    }
}
