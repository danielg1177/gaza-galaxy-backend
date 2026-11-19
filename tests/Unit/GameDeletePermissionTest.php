<?php

namespace Tests\Unit;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GameDeletePermissionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_any_member_can_delete_a_finished_game(): void
    {
        $game = $this->gameWithPlayers('finished', 1, [2, 3]);

        $this->assertTrue($game->userCanDelete(2));
        $this->assertTrue($game->userCanDelete(3));
        $this->assertTrue($game->userCanDelete(1));
        $this->assertFalse($game->userCanDelete(9));
    }

    public function test_any_member_can_delete_a_game_untouched_for_a_week(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $game = $this->gameWithPlayers('in_progress', 1, [2]);
        $this->stamp($game, '2026-11-11 12:00:00');

        $this->assertTrue($game->isIdle());
        $this->assertTrue($game->userCanDelete(2));
    }

    public function test_activity_within_the_week_keeps_an_active_game_restricted(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $game = $this->gameWithPlayers('in_progress', 1, [2, 3]);
        $this->stamp($game, '2026-11-01 12:00:00', [
            'messages_max_created_at' => '2026-11-17 12:00:00',
        ]);

        $this->assertFalse($game->isIdle());
        $this->assertFalse($game->userCanDelete(2));
        $this->assertTrue($game->userCanDelete(1));
    }

    public function test_a_recent_mid_turn_save_counts_as_a_touch(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $game = $this->gameWithPlayers('in_progress', 1, [2, 3]);
        $this->stamp($game, '2026-11-01 12:00:00', [
            'turns_max_updated_at' => '2026-11-18 11:00:00',
        ]);

        $this->assertFalse($game->userCanDelete(3));
    }

    public function test_sole_sitting_in_human_can_still_delete_an_active_game(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $game = $this->gameWithPlayers('in_progress', 1, [2], [1]);
        $this->stamp($game, '2026-11-17 12:00:00');

        $this->assertTrue($game->userCanDelete(2));
    }

    /**
     * @param  list<int>  $memberIds
     * @param  list<int>  $forfeitedIds
     */
    private function gameWithPlayers(string $status, int $creatorId, array $memberIds, array $forfeitedIds = []): Game
    {
        $ids = array_values(array_unique(array_merge([$creatorId], $memberIds)));
        $players = collect($ids)->map(fn (int $id) => new GamePlayer([
            'user_id' => $id,
            'is_ai' => false,
            'is_forfeited' => in_array($id, $forfeitedIds, true),
        ]));

        $game = new Game([
            'status' => $status,
            'created_by_user_id' => $creatorId,
        ]);
        $game->setRelation('players', $players);

        return $game;
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function stamp(Game $game, string $at, array $overrides = []): void
    {
        $game->updated_at = Carbon::parse($at);
        $game->setRawAttributes(array_merge($game->getAttributes(), [
            'turns_max_updated_at' => $overrides['turns_max_updated_at'] ?? $at,
            'players_max_updated_at' => $overrides['players_max_updated_at'] ?? $at,
            'messages_max_created_at' => $overrides['messages_max_created_at'] ?? null,
            'invites_max_updated_at' => $overrides['invites_max_updated_at'] ?? null,
        ]), true);
    }
}
