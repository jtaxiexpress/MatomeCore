<?php

declare(strict_types=1);

namespace App\Support\Slack;

use App\Notifications\SystemExceptionAlertNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ExceptionAlertReporter
{
    public function __construct(
        private readonly ExceptionAlertClassifier $classifier,
    ) {}

    public function report(Throwable $exception): void
    {
        if (! $this->classifier->shouldNotify($exception)) {
            return;
        }

        $webhookUrl = (string) config('services.slack.alert_webhook_url', '');
        if ($webhookUrl === '') {
            return;
        }

        $cacheKey = 'slack-alert:fingerprint:'.$this->fingerprint($exception);

        // 同一エラーの連投を防ぎ、ノイズを抑える
        if (! Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return;
        }

        try {
            Notification::route('slack', $webhookUrl)
                ->notify(new SystemExceptionAlertNotification($exception));
        } catch (Throwable $notificationException) {
            Log::warning('[SlackAlert] Failed to send exception alert.', [
                'exception' => $notificationException::class,
                'message' => $notificationException->getMessage(),
            ]);
        }
    }

    private function fingerprint(Throwable $exception): string
    {
        return hash('sha256', implode('|', [
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            (string) $exception->getLine(),
        ]));
    }
}
