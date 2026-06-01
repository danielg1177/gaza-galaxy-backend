<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotificationService
{
    public function sendPushNotification(\App\Models\User $user, string $title, string $body, array $data = []): void
    {
        $expoPushToken = $user->expo_push_token ?? '';

        if (! blank($expoPushToken)) {
            try {
                Http::post('https://exp.host/--/api/v2/push/send', [
                    'to' => $expoPushToken,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ]);
            } catch (\Throwable) {
                // fire-and-forget
            }
        }

        $this->sendWebPushNotification($user, $title, $body, $data);
    }

    private function sendWebPushNotification(\App\Models\User $user, string $title, string $body, array $data = []): void
    {
        if (blank($user->web_push_subscription)) {
            return;
        }
        try {
            $sub = json_decode($user->web_push_subscription, true);
            $subscription = Subscription::create($sub);
            $auth = [
                'VAPID' => [
                    'subject'    => config('services.vapid.subject'),
                    'publicKey'  => config('services.vapid.public_key'),
                    'privateKey' => config('services.vapid.private_key'),
                ],
            ];
            $webPush = new WebPush($auth);
            $payload = json_encode(['title' => $title, 'body' => $body, 'data' => $data]);
            $webPush->queueNotification($subscription, $payload);
            $webPush->flush();
        } catch (\Throwable) {
            // Fire-and-forget — swallow all delivery errors.
        }
    }
}
