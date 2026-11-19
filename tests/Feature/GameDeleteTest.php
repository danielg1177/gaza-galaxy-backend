<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GameDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_can_delete_a_finished_game(): void
    {
        [$host, $guest, $game] = $this->match('finished');

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('games.0.can_delete', true);

        $this->actingAs($guest, 'sanctum')
            ->deleteJson('/api/games/'.$game->id)
            ->assertOk();

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
        $this->assertNotNull($host->id);
    }

    public function test_a_member_cannot_delete_an_active_game_touched_this_week(): void
    {
        [, $guest, $game] = $this->match('in_progress');

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('games.0.can_delete', false);

        $this->actingAs($guest, 'sanctum')
            ->deleteJson('/api/games/'.$game->id)
            ->assertForbidden();

        $this->assertDatabaseHas('games', ['id' => $game->id]);
    }

    public function test_a_member_can_delete_a_game_untouched_for_a_week(): void
    {
        [, $guest, $game] = $this->match('in_progress');
        $this->age($game, 8);

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('games.0.can_delete', true);

        $this->actingAs($guest, 'sanctum')
            ->deleteJson('/api/games/'.$game->id)
            ->assertOk();

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_a_recent_saved_turn_keeps_an_old_game_locked(): void
    {
        [$host, $guest, $game] = $this->match('in_progress');
        $this->age($game, 8);

        $turn = Turn::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'turn_number' => 1,
            'round_number' => 1,
            'in_progress_actions_json' => '{"partial_state_json":"{}","queued_orders":[]}',
        ]);
        DB::table('turns')->where('id', $turn->id)->update([
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($guest, 'sanctum')
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('games.0.can_delete', false);

        $this->actingAs($guest, 'sanctum')
            ->deleteJson('/api/games/'.$game->id)
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: Game}
     */
    private function match(string $status): array
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();

        $game = Game::create([
            'name' => 'Campaign',
            'status' => $status,
            'map_config_json' => '{}',
            'state_json' => '{}',
            'created_by_user_id' => $host->id,
            'current_user_id' => $host->id,
            'turn_number' => 1,
            'round_number' => 1,
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'name' => 'Host',
            'turn_order' => 0,
            'is_ai' => false,
        ]);
        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $guest->id,
            'name' => 'Guest',
            'turn_order' => 1,
            'is_ai' => false,
        ]);

        return [$host, $guest, $game];
    }

    private function age(Game $game, int $days): void
    {
        $at = now()->subDays($days);
        DB::table('games')->where('id', $game->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        DB::table('game_players')->where('game_id', $game->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
