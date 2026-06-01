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

    public function storeSubscription(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'subscription'               => ['required', 'array'],
            'subscription.endpoint'      => ['required', 'string', 'url'],
            'subscription.keys'          => ['required', 'array'],
            'subscription.keys.p256dh'   => ['required', 'string'],
            'subscription.keys.auth'     => ['required', 'string'],
        ]);
        $request->user()->update([
            'web_push_subscription' => json_encode($request->input('subscription')),
        ]);
        return response()->json(['saved' => true]);
    }
}
