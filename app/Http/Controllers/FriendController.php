<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\Game;
use App\Models\GameInvite;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FriendController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $friendships = Friendship::where('status', 'accepted')
            ->where(function ($query) use ($user) {
                $query->where('requester_id', $user->id)
                    ->orWhere('addressee_id', $user->id);
            })
            ->with(['requester', 'addressee'])
            ->get();

        $friends = $friendships->map(function (Friendship $friendship) use ($user) {
            $friend = $friendship->requester_id === $user->id
                ? $friendship->addressee
                : $friendship->requester;

            return [
                'friendship_id' => $friendship->id,
                'user' => [
                    'id' => $friend->id,
                    'username' => $friend->username,
                ],
            ];
        })->values();

        return response()->json(['friends' => $friends]);
    }

    public function requests(Request $request): JsonResponse
    {
        $user = $request->user();

        $friendships = Friendship::where('addressee_id', $user->id)
            ->where('status', 'pending')
            ->with('requester')
            ->get();

        $requests = $friendships->map(function (Friendship $friendship) {
            return [
                'friendship_id' => $friendship->id,
                'from_user' => [
                    'id' => $friendship->requester->id,
                    'username' => $friendship->requester->username,
                ],
                'created_at' => $friendship->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json(['requests' => $requests]);
    }

    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
        ]);

        $user = $request->user();
        $target = User::where('username', $validated['username'])->first();

        if (! $target || Friendship::isBlocked($user->id, $target->id)) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($target->id === $user->id) {
            return response()->json(['message' => 'Cannot add yourself'], 422);
        }

        $exists = Friendship::where(function ($query) use ($user, $target) {
            $query->where('requester_id', $user->id)
                ->where('addressee_id', $target->id);
        })->orWhere(function ($query) use ($user, $target) {
            $query->where('requester_id', $target->id)
                ->where('addressee_id', $user->id);
        })->exists();

        if ($exists) {
            return response()->json(['message' => 'Friendship already exists'], 422);
        }

        $friendship = Friendship::create([
            'requester_id' => $user->id,
            'addressee_id' => $target->id,
            'status' => 'pending',
        ]);

        return response()->json([
            'friendship_id' => $friendship->id,
            'status' => 'pending',
        ], 201);
    }

    public function accept(Request $request, Friendship $friendship): JsonResponse
    {
        $user = $request->user();

        if ($friendship->addressee_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($friendship->status !== 'pending') {
            return response()->json(['message' => 'Request is not pending'], 422);
        }

        $friendship->update(['status' => 'accepted']);

        return response()->json([
            'friendship_id' => $friendship->id,
            'status' => 'accepted',
        ]);
    }

    public function decline(Request $request, Friendship $friendship): JsonResponse
    {
        $user = $request->user();

        if ($friendship->addressee_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($friendship->status !== 'pending') {
            return response()->json(['message' => 'Request is not pending'], 422);
        }

        $friendship->delete();

        return response()->json(['message' => 'Declined']);
    }

    public function destroy(Request $request, Friendship $friendship): JsonResponse
    {
        $user = $request->user();

        if ($friendship->requester_id !== $user->id && $friendship->addressee_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($friendship->status === 'blocked') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $friendship->delete();

        return response()->json(['message' => 'Friend removed']);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1'],
        ]);

        $me = $request->user();
        $blockedIds = Friendship::blockedUserIds($me->id);

        $users = User::where('username', 'like', '%'.$validated['q'].'%')
            ->where('id', '!=', $me->id)
            ->when(count($blockedIds) > 0, fn ($query) => $query->whereNotIn('id', $blockedIds))
            ->limit(20)
            ->get(['id', 'username']);

        $userIds = $users->pluck('id');
        $friendships = Friendship::where(function ($q) use ($me, $userIds) {
            $q->where('requester_id', $me->id)->whereIn('addressee_id', $userIds);
        })->orWhere(function ($q) use ($me, $userIds) {
            $q->where('addressee_id', $me->id)->whereIn('requester_id', $userIds);
        })->get();

        $results = $users->map(function (User $user) use ($me, $friendships) {
            $friendship = $friendships->first(function (Friendship $f) use ($me, $user) {
                return ($f->requester_id === $me->id && $f->addressee_id === $user->id)
                    || ($f->requester_id === $user->id && $f->addressee_id === $me->id);
            });

            $friendshipStatus = match (true) {
                $friendship === null => 'none',
                $friendship->status === 'accepted' => 'accepted',
                $friendship->status === 'pending' && $friendship->requester_id === $me->id => 'pending_sent',
                $friendship->status === 'pending' && $friendship->addressee_id === $me->id => 'pending_received',
                default => 'none',
            };

            return [
                'id' => $user->id,
                'username' => $user->username,
                'friendship_status' => $friendshipStatus,
            ];
        })->values();

        return response()->json(['users' => $results]);
    }

    public function blocked(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = Friendship::where('status', 'blocked')
            ->where('requester_id', $user->id)
            ->with('addressee')
            ->get();

        $blocked = $rows->map(function (Friendship $friendship) {
            return [
                'friendship_id' => $friendship->id,
                'user' => [
                    'id' => $friendship->addressee->id,
                    'username' => $friendship->addressee->username,
                ],
            ];
        })->values();

        return response()->json(['blocked' => $blocked]);
    }

    public function block(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $targetId = (int) $validated['user_id'];

        if ($targetId === $user->id) {
            return response()->json(['message' => 'Cannot block yourself'], 422);
        }

        $target = User::find($targetId);
        if ($target === null) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $friendship = DB::transaction(function () use ($user, $targetId) {
            $already = Friendship::where('requester_id', $user->id)
                ->where('addressee_id', $targetId)
                ->where('status', 'blocked')
                ->first();
            if ($already !== null) {
                return $already;
            }

            Friendship::between($user->id, $targetId)
                ->whereIn('status', ['pending', 'accepted'])
                ->delete();

            return Friendship::create([
                'requester_id' => $user->id,
                'addressee_id' => $targetId,
                'status' => 'blocked',
            ]);
        });

        $this->cancelPendingInvitesBetween($user->id, $targetId);

        return response()->json([
            'friendship_id' => $friendship->id,
            'status' => 'blocked',
        ]);
    }

    public function unblock(Request $request, Friendship $friendship): JsonResponse
    {
        $user = $request->user();

        if ($friendship->requester_id !== $user->id || $friendship->status !== 'blocked') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $friendship->delete();

        return response()->json(['message' => 'Unblocked']);
    }

    private function cancelPendingInvitesBetween(int $userA, int $userB): void
    {
        $invites = GameInvite::where('status', 'pending')
            ->where(function ($query) use ($userA, $userB) {
                $query->where(function ($pair) use ($userA, $userB) {
                    $pair->where('inviter_id', $userA)->where('invitee_id', $userB);
                })->orWhere(function ($pair) use ($userA, $userB) {
                    $pair->where('inviter_id', $userB)->where('invitee_id', $userA);
                });
            })
            ->get();

        foreach ($invites as $invite) {
            DB::transaction(function () use ($invite) {
                $invite->update(['status' => 'declined']);
                Game::where('id', $invite->game_id)->update(['status' => 'finished']);
                GameInvite::where('game_id', $invite->game_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'declined']);
            });

            $game = Game::with('createdBy')->find($invite->game_id);
            if ($game?->createdBy === null) {
                continue;
            }

            $this->notificationService->sendPushNotification(
                $game->createdBy,
                config('app.name'),
                "An invite was declined. {$game->name} has been cancelled.",
                ['game_id' => $game->id, 'event' => 'game_cancelled']
            );
        }
    }
}
