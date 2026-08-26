<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\Game;
use App\Models\GameMessage;
use App\Models\MessageReport;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $blockedIds = Friendship::blockedUserIds($me->id);

        $messages = $game->messages()
            ->with('sender')
            ->whereNull('hidden_at')
            ->when(count($blockedIds) > 0, function ($query) use ($blockedIds) {
                $query->where(function ($inner) use ($blockedIds) {
                    $inner->whereNull('sender_user_id')
                        ->orWhereNotIn('sender_user_id', $blockedIds);
                });
            })
            ->orderBy('id')
            ->get()
            ->map(fn (GameMessage $message) => $this->serializeMessage($game, $message));

        if ($messages->isNotEmpty()) {
            $player->update(['last_read_message_id' => $messages->last()['id']]);
        }

        $blockedReason = $this->messagingBlockedReason($game, $me->id);

        return response()->json([
            'messages' => $messages,
            'can_send' => $blockedReason === null,
            'cannot_send_reason' => $blockedReason,
        ]);
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        $me = $request->user();
        $player = $game->players()->where('user_id', $me->id)->first();
        if (!$player) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $blockedReason = $this->messagingBlockedReason($game, $me->id);
        if ($blockedReason !== null) {
            return response()->json(['message' => $blockedReason], 422);
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
            ->each(function ($otherPlayer) use ($game, $senderName, $truncated, $me) {
                if ($otherPlayer->user === null) {
                    return;
                }
                if (Friendship::isBlocked($me->id, (int) $otherPlayer->user_id)) {
                    return;
                }
                $this->notificationService->sendPushNotification(
                    $otherPlayer->user,
                    $game->name,
                    "{$senderName}: {$truncated}"
                );
            });

        return response()->json(['message' => $this->serializeMessage($game, $msg)], 201);
    }

    public function report(Request $request, Game $game, GameMessage $message): JsonResponse
    {
        $me = $request->user();
        $player = $game->players()->where('user_id', $me->id)->first();
        if (!$player) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($message->game_id !== $game->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($message->sender_user_id == $me->id) {
            return response()->json(['message' => 'Cannot report your own message'], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $existing = MessageReport::where('reporter_user_id', $me->id)
            ->where('message_id', $message->id)
            ->first();
        if ($existing !== null) {
            return response()->json(['reported' => true]);
        }

        $message->load('sender');
        $report = MessageReport::create([
            'game_id' => $game->id,
            'message_id' => $message->id,
            'reporter_user_id' => $me->id,
            'reported_user_id' => $message->sender_user_id,
            'content_snapshot' => $message->content,
            'sender_username_snapshot' => $message->sender?->username,
            'game_name_snapshot' => $game->name,
            'reason' => $validated['reason'] ?? null,
            'status' => 'open',
        ]);

        $this->mailReport($report, $me->username);

        return response()->json(['reported' => true], 201);
    }

    private function messagingBlockedReason(Game $game, int $meId): ?string
    {
        $otherHumanIds = $game->players()
            ->where('is_ai', false)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $meId)
            ->pluck('user_id');

        if ($otherHumanIds->isEmpty()) {
            return null;
        }

        if ($otherHumanIds->every(fn ($id) => Friendship::isBlocked($meId, (int) $id))) {
            return 'You cannot send messages in this game. Communication with the other player is blocked.';
        }

        return null;
    }

    /**
     * @return array{id: int, senderUserId: int|null, senderName: string, content: string, createdAt: string}
     */
    private function serializeMessage(Game $game, GameMessage $message): array
    {
        if ($message->sender_user_id === null) {
            $senderName = 'Former Player';
        } else {
            $senderName = $game->players()
                ->where('user_id', $message->sender_user_id)
                ->value('name')
                ?? $message->sender?->username
                ?? 'Former Player';
        }

        return [
            'id'           => $message->id,
            'senderUserId' => $message->sender_user_id,
            'senderName'   => $senderName,
            'content'      => $message->content,
            'createdAt'    => $message->created_at->toIso8601String(),
        ];
    }

    private function mailReport(MessageReport $report, string $reporterUsername): void
    {
        $to = config('mail.moderation_address');
        if (! is_string($to) || $to === '') {
            return;
        }

        $body = implode("\n", [
            'A chat message was reported in Gaza Galaxy.',
            '',
            'Report ID: '.$report->id,
            'Game ID: '.$report->game_id.' ('.$report->game_name_snapshot.')',
            'Message ID: '.$report->message_id,
            'Reporter: '.$reporterUsername.' (user '.$report->reporter_user_id.')',
            'Sender: '.($report->sender_username_snapshot ?? 'unknown').' (user '.$report->reported_user_id.')',
            'Reason: '.($report->reason ?: '(none)'),
            '',
            'Message:',
            $report->content_snapshot,
            '',
            'Act within about a day:',
            '  php artisan moderation:hide-message '.$report->message_id,
            '  php artisan moderation:delete-account '.$report->reported_user_id.' --force',
        ]);

        try {
            Mail::raw($body, function ($message) use ($to, $report) {
                $message->to($to)->subject('Gaza Galaxy message report #'.$report->id);
            });
        } catch (\Throwable $e) {
            Log::warning('[Moderation] Report mail failed: '.$e->getMessage());
        }
    }
}
