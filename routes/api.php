<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\TurnController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::post('/push-token', [PushTokenController::class, 'update']);
    Route::post('/push-subscription', [PushTokenController::class, 'storeSubscription']);
    Route::post('/test-web-push', [PushTokenController::class, 'testWebPush']);

    Route::get('/friends', [FriendController::class, 'index']);
    Route::get('/friends/requests', [FriendController::class, 'requests']);
    Route::post('/friends/request', [FriendController::class, 'request']);
    Route::post('/friends/requests/{friendship}/accept', [FriendController::class, 'accept']);
    Route::post('/friends/requests/{friendship}/decline', [FriendController::class, 'decline']);
    Route::delete('/friends/{friendship}', [FriendController::class, 'destroy']);
    Route::get('/users/search', [FriendController::class, 'search']);

    Route::get('/games', [GameController::class, 'index']);
    Route::post('/games', [GameController::class, 'store']);
    Route::get('/games/{game}', [GameController::class, 'show']);
    Route::patch('/games/{game}', [GameController::class, 'update']);
    Route::delete('/games/{game}', [GameController::class, 'destroy']);
    Route::post('/games/{game}/forfeit', [GameController::class, 'forfeit']);
    Route::post('/games/{game}/rejoin', [GameController::class, 'rejoin']);
    Route::post('/games/{game}/end', [GameController::class, 'endGame']);
    Route::post('/games/{game}/turn/save', [TurnController::class, 'save']);
    Route::post('/games/{game}/turn/submit', [TurnController::class, 'submit']);
    Route::post('/games/{game}/turn/abandon', [TurnController::class, 'abandon']);
    Route::get('/games/{game}/messages', [\App\Http\Controllers\MessageController::class, 'index']);
    Route::post('/games/{game}/messages', [\App\Http\Controllers\MessageController::class, 'store']);

    Route::get('/invites', [InviteController::class, 'index']);
    Route::post('/invites/{invite}/accept', [InviteController::class, 'accept']);
    Route::post('/invites/{invite}/decline', [InviteController::class, 'decline']);
});
