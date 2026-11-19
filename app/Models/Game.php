<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Game extends Model
{
    protected $fillable = [
        'name',
        'status',
        'map_config_json',
        'state_json',
        'current_user_id',
        'turn_number',
        'round_number',
        'winner_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'turn_number' => 'integer',
        'round_number' => 'integer',
        'current_user_id' => 'integer',
        'created_by_user_id' => 'integer',
        'winner_user_id' => 'integer',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(GameInvite::class);
    }

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class);
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GameMessage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function currentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_user_id');
    }

    /**
     * A game no one has changed for this many days can be deleted by any member.
     */
    public const MEMBER_DELETE_IDLE_DAYS = 7;

    /**
     * Creator always can. The sole remaining sitting-in human can after everyone
     * else forfeits. Any human member can once the game is finished, or once
     * nobody has touched it for a week.
     */
    public function userCanDelete(int $userId): bool
    {
        if ($this->created_by_user_id == $userId) {
            return true;
        }

        if ($this->isHumanMember($userId) && $this->anyMemberMayDelete()) {
            return true;
        }

        $sittingInHumans = $this->players->filter(
            fn (GamePlayer $player) => ! $player->is_ai
                && $player->user_id !== null
                && ! $player->is_forfeited
        );

        return $sittingInHumans->count() === 1
            && $sittingInHumans->first()->user_id == $userId;
    }

    public function isHumanMember(int $userId): bool
    {
        return $this->players->contains(
            fn (GamePlayer $player) => ! $player->is_ai
                && $player->user_id !== null
                && $player->user_id == $userId
        );
    }

    /**
     * Finished games, and games with no activity for a week, are open for any member to delete.
     */
    public function anyMemberMayDelete(): bool
    {
        return $this->status === 'finished' || $this->isIdle();
    }

    public function isIdle(): bool
    {
        $touched = $this->lastTouchedAt();
        if ($touched === null) {
            return false;
        }

        return $touched->lessThanOrEqualTo(now()->subDays(self::MEMBER_DELETE_IDLE_DAYS));
    }

    /**
     * Latest change to the game, a seat, a turn (including a mid-turn save),
     * an invite, or chat. Opening the map without saving does not count.
     */
    public function lastTouchedAt(): ?Carbon
    {
        $latest = null;

        foreach ([
            $this->updated_at,
            $this->aggregateStamp('turns_max_updated_at', fn () => $this->turns()->max('updated_at')),
            $this->aggregateStamp('players_max_updated_at', fn () => $this->players()->max('updated_at')),
            $this->aggregateStamp('messages_max_created_at', fn () => $this->messages()->max('created_at')),
            $this->aggregateStamp('invites_max_updated_at', fn () => $this->invites()->max('updated_at')),
        ] as $stamp) {
            if ($stamp === null || $stamp === '') {
                continue;
            }

            $at = $stamp instanceof Carbon
                ? $stamp
                : Carbon::parse($stamp);

            if ($latest === null || $at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    private function aggregateStamp(string $attribute, callable $query): mixed
    {
        if (array_key_exists($attribute, $this->attributes)) {
            return $this->attributes[$attribute];
        }

        return $query();
    }
}
