<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'starts_with:ExponentPushToken'],
        ]);

        $request->user()->update(['expo_push_token' => $request->token]);

        return response()->json(['saved' => true]);
    }
}
