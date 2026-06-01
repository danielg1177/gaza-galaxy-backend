<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameInvite;
use App\Models\GamePlayer;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InviteController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $invites = GameInvite::where('invitee_id', $me->id)
            ->where('status', 'pending')
            ->with(['game', 'inviter'])
            ->get();

        $data = $invites->map(fn (GameInvite $invite) => [
            'id' => $invite->id,
            'game_id' => $invite->game_id,
            'inviter' => [
                'id' => $invite->inviter->id,
                'username' => $invite->inviter->username,
            ],
            'player_slot_index' => $invite->player_slot_index,
            'status' => $invite->status,
            'game' => [
                'id' => $invite->game->id,
                'name' => $invite->game->name,
                'map_config_json' => $invite->game->map_config_json,
            ],
        ])->values();

        return response()->json(['invites' => $data]);
    }

    public function accept(Request $request, GameInvite $invite): JsonResponse
    {
        $me = $request->user();

        if ($invite->invitee_id !== $me->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($invite->status !== 'pending') {
            return response()->json(['message' => 'Invite is not pending'], 422);
        }

        $unblocked = false;

        DB::transaction(function () use ($invite, $me, &$unblocked) {
            $invite->update(['status' => 'accepted']);

            $game = Game::find($invite->game_id);

            if ($game->current_user_id == $me->id && $game->status === 'waiting_for_players') {
                $game->status = 'in_progress';
                $game->save();
                $unblocked = true;
            } else {
                $unblocked = false;
            }
        });

        $invite->refresh();
        $game = Game::with('createdBy')->find($invite->game_id);
        $invite->load('inviter');

        $this->notificationService->sendPushNotification(
            $invite->inviter->expo_push_token ?? '',
            'Strategic Commander',
            "Your invite was accepted for {$game->name}."
        );

        if ($unblocked) {
            $this->notificationService->sendPushNotification(
                $me->expo_push_token ?? '',
                'Strategic Commander',
                "It's your turn in {$game->name}!"
            );
        }

        return response()->json([
            'accepted' => true,
            'game_started' => $unblocked,
        ]);
    }

    public function decline(Request $request, GameInvite $invite): JsonResponse
    {
        $me = $request->user();

        if ($invite->invitee_id !== $me->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($invite->status !== 'pending') {
            return response()->json(['message' => 'Invite is not pending'], 422);
        }

        DB::transaction(function () use ($invite) {
            $invite->update(['status' => 'declined']);

            Game::where('id', $invite->game_id)->update(['status' => 'finished']);

            GameInvite::where('game_id', $invite->game_id)
                ->where('status', 'pending')
                ->update(['status' => 'declined']);
        });

        $game = Game::with('createdBy')->find($invite->game_id);

        $this->notificationService->sendPushNotification(
            $game->createdBy->expo_push_token ?? '',
            'Strategic Commander',
            "An invite was declined. {$game->name} has been cancelled."
        );

        return response()->json([
            'data' => [
                'id' => $invite->id,
                'status' => 'declined',
                'game_id' => $invite->game_id,
            ],
        ]);
    }
}
