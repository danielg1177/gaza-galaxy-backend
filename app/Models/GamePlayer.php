<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePlayer extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'turn_order',
        'name',
        'is_ai',
        'home_planet_id',
        'is_eliminated',
    ];

    protected $casts = [
        'is_ai' => 'boolean',
        'is_eliminated' => 'boolean',
        'turn_order' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
