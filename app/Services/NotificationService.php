<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class NotificationService
{
    public function sendPushNotification(string $expoPushToken, string $title, string $body, array $data = []): void
    {
        if (blank($expoPushToken)) {
            return;
        }

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
}
