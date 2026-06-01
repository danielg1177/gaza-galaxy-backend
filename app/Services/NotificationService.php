<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            } catch (\Throwable $e) {
                Log::warning('[Push] Expo push failed for user '.$user->id.': '.$e->getMessage());
            }
        }

        $this->sendWebPushNotification($user, $title, $body, $data);
    }

    public function sendWebPushNotification(\App\Models\User $user, string $title, string $body, array $data = []): array
    {
        if (blank($user->web_push_subscription)) {
            return ['sent' => false, 'error' => 'No web push subscription saved.'];
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
            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    $reason = $report->getReason();
                    Log::warning('[Push] Web push failed for user '.$user->id.': '.$reason);

                    return ['sent' => false, 'error' => $reason];
                }
            }

            return ['sent' => true];
        } catch (\Throwable $e) {
            Log::warning('[Push] Web push error for user '.$user->id.': '.$e->getMessage());

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }
}
