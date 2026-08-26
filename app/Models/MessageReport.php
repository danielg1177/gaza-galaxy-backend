<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReport extends Model
{
    protected $fillable = [
        'game_id',
        'message_id',
        'reporter_user_id',
        'reported_user_id',
        'content_snapshot',
        'sender_username_snapshot',
        'game_name_snapshot',
        'reason',
        'status',
    ];

    protected $casts = [
        'game_id' => 'integer',
        'message_id' => 'integer',
        'reporter_user_id' => 'integer',
        'reported_user_id' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(GameMessage::class, 'message_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }
}