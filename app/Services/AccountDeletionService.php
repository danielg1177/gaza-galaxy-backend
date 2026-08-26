<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameInvite;
use App\Models\GamePlayer;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountDeletionService
{
    public function __construct(private NotificationService $notificationService) {}

    public function delete(User $user): void
    {
        /** @var list<array{user: User, title: string, body: string, data: array<string, mixed>}> $notifications */
        $notifications = [];

        DB::transaction(function () use ($user, &$notifications) {
            $user->load(['gamePlayers.game.players.user']);

            foreach ($user->gamePlayers as $player) {
                $game = $player->game;
                if ($game === null) {
                    continue;
                }

                if ($game->status === 'waiting_for_players') {
                    if ($game->created_by_user_id == $user->id) {
                        $this->deleteWaitingGame($game, $user, $notifications);
                    } elseif ($this->isOpenLobby($game)) {
                        $player->update([
                            'user_id' => null,
                            'name' => 'Open',
                        ]);
                    } else {
                        $this->cancelPendingInvitesForInvitee($game, $user, $notifications);
                        $game->refresh();
                        $game->load('players.user');
                        if ($game->exists) {
                            $this->anonymizeAndTransfer($game, $player, $user);
                        }
                    }
                } elseif ($game->status === 'in_progress') {
                    $this->permanentlyLeaveInProgress($game, $player, $user, $notifications);
                } else {
                    $this->anonymizeAndTransfer($game, $player, $user);
                }
            }

            $remainingHosted = Game::where('created_by_user_id', $user->id)->get();
            foreach ($remainingHosted as $game) {
                $game->load('players.user');
                if ($game->status === 'waiting_for_players') {
                    $this->deleteWaitingGame($game, $user, $notifications);
                    continue;
                }
                $this->transferCreator($game, $user->id);
            }

            $user->tokens()->delete();
            $user->forceFill([
                'expo_push_token' => null,
                'web_push_subscription' => null,
            ])->save();
            $user->delete();
        });

        foreach ($notifications as $notification) {
            $this->notificationService->sendPushNotification(
                $notification['user'],
                $notification['title'],
                $notification['body'],
                $notification['data'],
            );
        }
    }

    /**
     * @param list<array{user: User, title: string, body: string, data: array<string, mixed>}> $notifications
     */
    private function deleteWaitingGame(Game $game, User $departing, array &$notifications): void
    {
        $game->load('players.user');
        $gameName = $game->name;
        $wasLobby = $this->isOpenLobby($game);
        $others = $game->players->filter(
            fn (GamePlayer $player) => ! $player->is_ai
                && $player->user_id !== null
                && $player->user_id != $departing->id
                && $player->user !== null
        );

        $game->delete();

        foreach ($others as $other) {
            $notifications[] = [
                'user' => $other->user,
                'title' => $gameName,
                'body' => $wasLobby ? 'The lobby was cancelled.' : 'The game was cancelled.',
                'data' => ['game_id' => $game->id, 'event' => 'game_cancelled'],
            ];
        }
    }

    /**
     * @param list<array{user: User, title: string, body: string, data: array<string, mixed>}> $notifications
     */
    private function cancelPendingInvitesForInvitee(Game $game, User $departing, array &$notifications): void
    {
        $pending = GameInvite::where('game_id', $game->id)
            ->where('invitee_id', $departing->id)
            ->where('status', 'pending')
            ->exists();

        if (! $pending) {
            return;
        }

        GameInvite::where('game_id', $game->id)
            ->where('invitee_id', $departing->id)
            ->where('status', 'pending')
            ->update(['status' => 'declined']);

        GameInvite::where('game_id', $game->id)
            ->where('status', 'pending')
            ->update(['status' => 'declined']);

        $game->status = 'finished';
        $game->current_user_id = null;
        $game->save();

        $game->load('createdBy');
        if ($game->createdBy !== null && $game->createdBy->id != $departing->id) {
            $notifications[] = [
                'user' => $game->createdBy,
                'title' => config('app.name'),
                'body' => "An invite was declined. {$game->name} has been cancelled.",
                'data' => ['game_id' => $game->id, 'event' => 'game_cancelled'],
            ];
        }
    }

    /**
     * @param list<array{user: User, title: string, body: string, data: array<string, mixed>}> $notifications
     */
    private function permanentlyLeaveInProgress(
        Game $game,
        GamePlayer $player,
        User $departing,
        array &$notifications,
    ): void {
        if (! $player->is_eliminated) {
            $player->is_forfeited = true;
        }
        $player->name = 'Former Player';
        $player->save();

        $wasCurrent = $game->current_user_id == $departing->id;

        if ($wasCurrent) {
            Turn::where('game_id', $game->id)
                ->where('user_id', $departing->id)
                ->where('turn_number', $game->turn_number)
                ->where('round_number', $game->round_number)
                ->whereNull('resulting_state_json')
                ->update(['in_progress_actions_json' => null]);
        }

        $game->load('players.user');
        $next = $this->nextPlayingHuman($game, (int) $player->turn_order);
        $state = json_decode($game->state_json ?? '', true);
        if (! is_array($state)) {
            $state = [];
        }

        $playerId = 'player-'.$player->turn_order;
        if (isset($state['players']) && is_array($state['players'])) {
            foreach ($state['players'] as &$statePlayer) {
                if (($statePlayer['id'] ?? null) !== $playerId) {
                    continue;
                }
                $statePlayer['name'] = 'Former Player';
                if (! ($statePlayer['isEliminated'] ?? false)) {
                    $statePlayer['isForfeited'] = true;
                }
            }
            unset($statePlayer);
        }

        $this->appendForfeitNotice($state, $playerId, $game);

        if ($next === null) {
            $state['status'] = 'finished';
            $state['winnerId'] = null;
            $game->state_json = json_encode($state);
            $game->status = 'finished';
            $game->current_user_id = null;
            $game->winner_user_id = null;
            $this->transferCreator($game, $departing->id);
            $game->save();

            foreach ($this->otherHumans($game, $departing->id) as $other) {
                $notifications[] = [
                    'user' => $other,
                    'title' => $game->name,
                    'body' => 'A player left. The campaign has ended.',
                    'data' => ['game_id' => $game->id, 'event' => 'game_ended'],
                ];
            }

            return;
        }

        if ($wasCurrent) {
            $state['currentPlayerId'] = 'player-'.$next->turn_order;
            $game->current_user_id = $next->user_id;
        }

        $game->state_json = json_encode($state);
        $this->transferCreator($game, $departing->id);
        $game->save();

        if ($wasCurrent && $next->user !== null) {
            $notifications[] = [
                'user' => $next->user,
                'title' => config('app.name'),
                'body' => "It's your turn in {$game->name}!",
                'data' => ['game_id' => $game->id, 'event' => 'your_turn'],
            ];
        }
    }

    private function anonymizeAndTransfer(Game $game, GamePlayer $player, User $departing): void
    {
        $player->name = 'Former Player';
        $player->save();

        $state = json_decode($game->state_json ?? '', true);
        if (is_array($state) && isset($state['players']) && is_array($state['players'])) {
            $playerId = 'player-'.$player->turn_order;
            foreach ($state['players'] as &$statePlayer) {
                if (($statePlayer['id'] ?? null) === $playerId) {
                    $statePlayer['name'] = 'Former Player';
                }
            }
            unset($statePlayer);
            $game->state_json = json_encode($state);
        }

        $this->transferCreator($game, $departing->id);
        $game->save();
    }

    private function transferCreator(Game $game, int $departingUserId): void
    {
        if ($game->created_by_user_id != $departingUserId) {
            return;
        }

        $next = $game->players
            ->filter(
                fn (GamePlayer $player) => ! $player->is_ai
                    && $player->user_id !== null
                    && $player->user_id != $departingUserId
            )
            ->sortBy('turn_order')
            ->first();

        $game->created_by_user_id = $next?->user_id;
    }

    private function nextPlayingHuman(Game $game, int $afterTurnOrder): ?GamePlayer
    {
        $players = $game->players->sortBy('turn_order')->values();
        $count = $players->count();
        if ($count === 0) {
            return null;
        }

        for ($offset = 1; $offset <= $count; $offset++) {
            /** @var GamePlayer $candidate */
            $candidate = $players[($afterTurnOrder + $offset) % $count];
            if (
                ! $candidate->is_ai
                && $candidate->user_id !== null
                && ! $candidate->is_eliminated
                && ! $candidate->is_forfeited
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<User>
     */
    private function otherHumans(Game $game, int $departingUserId): array
    {
        return $game->players
            ->filter(
                fn (GamePlayer $player) => ! $player->is_ai
                    && $player->user_id !== null
                    && $player->user_id != $departingUserId
                    && $player->user !== null
            )
            ->map(fn (GamePlayer $player) => $player->user)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function appendForfeitNotice(array &$state, string $playerId, Game $game): void
    {
        $roundNumber = $state['roundNumber'] ?? $game->round_number;
        $turnNumber = $state['turnNumber'] ?? $game->turn_number;
        $id = "forfeit-{$playerId}-r{$roundNumber}-t{$turnNumber}";
        $existing = $state['commanderStatusNotices'] ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }
        foreach ($existing as $notice) {
            if (is_array($notice) && ($notice['id'] ?? null) === $id) {
                return;
            }
        }
        $existing[] = [
            'id' => $id,
            'kind' => 'forfeit',
            'playerId' => $playerId,
            'playerName' => 'Former Player',
            'acknowledgedByPlayerIds' => [],
        ];
        $state['commanderStatusNotices'] = $existing;
    }

    private function isOpenLobby(Game $game): bool
    {
        return $game->status === 'waiting_for_players' && blank($game->state_json);
    }
}
