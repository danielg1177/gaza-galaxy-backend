<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    protected $fillable = ['requester_id', 'addressee_id', 'status'];

    protected $casts = [
        'status' => 'string',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    public static function isBlocked(int $userA, int $userB): bool
    {
        if ($userA === $userB) {
            return false;
        }

        return static::where('status', 'blocked')
            ->where(function ($query) use ($userA, $userB) {
                $query->where(function ($pair) use ($userA, $userB) {
                    $pair->where('requester_id', $userA)->where('addressee_id', $userB);
                })->orWhere(function ($pair) use ($userA, $userB) {
                    $pair->where('requester_id', $userB)->where('addressee_id', $userA);
                });
            })
            ->exists();
    }

    public static function hasBlocked(int $blockerId, int $blockedId): bool
    {
        if ($blockerId === $blockedId) {
            return false;
        }

        return static::where('status', 'blocked')
            ->where('requester_id', $blockerId)
            ->where('addressee_id', $blockedId)
            ->exists();
    }

    /**
     * @return list<int>
     */
    public static function blockedUserIds(int $userId): array
    {
        return static::where('status', 'blocked')
            ->where(function ($query) use ($userId) {
                $query->where('requester_id', $userId)->orWhere('addressee_id', $userId);
            })
            ->get(['requester_id', 'addressee_id'])
            ->map(fn (self $friendship) => $friendship->requester_id == $userId
                ? (int) $friendship->addressee_id
                : (int) $friendship->requester_id)
            ->unique()
            ->values()
            ->all();
    }

    public static function between(int $userA, int $userB): Builder
    {
        return static::where(function ($query) use ($userA, $userB) {
            $query->where(function ($pair) use ($userA, $userB) {
                $pair->where('requester_id', $userA)->where('addressee_id', $userB);
            })->orWhere(function ($pair) use ($userA, $userB) {
                $pair->where('requester_id', $userB)->where('addressee_id', $userA);
            });
        });
    }
}
