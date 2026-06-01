<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function currentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_user_id');
    }
}
