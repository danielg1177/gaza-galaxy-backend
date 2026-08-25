<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\Process;

class GameService
{
    public function startGame(Game $game): void
    {
        // If the client already provided a fully-generated state_json at creation
        // time, skip the engine script — just activate the game using that state.
        if (! blank($game->state_json)) {
            $state = json_decode($game->state_json, true);
            if ($state === null) {
                throw new \RuntimeException('Stored game state is invalid JSON');
            }

            preg_match('/player-(\d+)/', $state['currentPlayerId'] ?? '', $matches);
            $turnOrder = isset($matches[1]) ? (int) $matches[1] : 0;

            $firstPlayer = GamePlayer::where('game_id', $game->id)
                ->where('turn_order', $turnOrder)
                ->first();

            $game->update([
                'status' => 'in_progress',
                'current_user_id' => $firstPlayer?->user_id,
            ]);

            return;
        }

        // Fallback: generate state by running the engine init script.
        $result = Process::run([
            'node',
            base_path('engine/init-game.js'),
            $game->map_config_json,
        ]);

        if ($result->failed()) {
            throw new \RuntimeException('Game engine failed: ' . $result->errorOutput());
        }

        $state = json_decode($result->output(), true);

        if ($state === null) {
            throw new \RuntimeException('Game engine returned invalid JSON');
        }

        preg_match('/player-(\d+)/', $state['currentPlayerId'], $matches);
        $turnOrder = (int) $matches[1];

        $firstPlayer = GamePlayer::where('game_id', $game->id)
            ->where('turn_order', $turnOrder)
            ->first();

        $game->update([
            'state_json' => $result->output(),
            'status' => 'in_progress',
            'current_user_id' => $firstPlayer->user_id,
        ]);
    }
}
