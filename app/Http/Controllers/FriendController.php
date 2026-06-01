<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FriendController extends Controller
{
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

        if (! $target) {
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

        $friendship->delete();

        return response()->json(['message' => 'Friend removed']);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1'],
        ]);

        $me = $request->user();

        $users = User::where('username', 'like', '%'.$validated['q'].'%')
            ->where('id', '!=', $me->id)
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
}
