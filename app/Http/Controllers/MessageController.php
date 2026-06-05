<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameMessage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

    public function index(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $player = $game->players()->where('user_id', $me->id)->first();
        if (!$player) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = $game->messages()
            ->with('sender')
            ->orderBy('id')
            ->get()
            ->map(function (GameMessage $m) use ($game) {
                $senderName = $game->players()
                    ->where('user_id', $m->sender_user_id)
                    ->value('name') ?? $m->sender->username;

                return [
                    'id'           => $m->id,
                    'senderUserId' => $m->sender_user_id,
                    'senderName'   => $senderName,
                    'content'      => $m->content,
                    'createdAt'    => $m->created_at->toIso8601String(),
                ];
            });

        if ($messages->isNotEmpty()) {
            $player->update(['last_read_message_id' => $messages->last()['id']]);
        }

        return response()->json(['messages' => $messages]);
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $player = $game->players()->where('user_id', $me->id)->first();
        if (!$player) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:500'],
        ]);

        $msg = GameMessage::create([
            'game_id'        => $game->id,
            'sender_user_id' => $me->id,
            'content'        => $validated['content'],
        ]);

        $player->update(['last_read_message_id' => $msg->id]);

        $senderName = $player->name ?? $me->username;
        $truncated = mb_strlen($validated['content']) > 80
            ? mb_substr($validated['content'], 0, 80) . '...'
            : $validated['content'];

        $game->players()
            ->where('user_id', '!=', $me->id)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->each(function ($otherPlayer) use ($game, $senderName, $truncated) {
                if ($otherPlayer->user) {
                    $this->notificationService->sendPushNotification(
                        $otherPlayer->user,
                        $game->name,
                        "{$senderName}: {$truncated}"
                    );
                }
            });

        return response()->json(['message' => [
            'id'           => $msg->id,
            'senderUserId' => $msg->sender_user_id,
            'senderName'   => $senderName,
            'content'      => $msg->content,
            'createdAt'    => $msg->created_at->toIso8601String(),
        ]], 201);
    }
}
