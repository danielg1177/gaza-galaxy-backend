<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMessage extends Model
{
    protected $fillable = ['game_id', 'sender_user_id', 'content', 'hidden_at'];

    protected $casts = [
        'game_id'        => 'integer',
        'sender_user_id' => 'integer',
        'hidden_at'      => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
