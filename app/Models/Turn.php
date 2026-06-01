<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Turn extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'turn_number',
        'round_number',
        'in_progress_actions_json',
        'submitted_actions_json',
        'resulting_state_json',
        'events_json',
    ];

    protected $casts = [
        'turn_number' => 'integer',
        'round_number' => 'integer',
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
