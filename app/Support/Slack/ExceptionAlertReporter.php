<?php

declare(strict_types=1);

namespace App\Support\Slack;

use App\Models\User;
use App\Notifications\FilamentDatabaseNotification;
use App\Notifications\SystemExceptionAlertNotification;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

        $cacheKey = 'slack-alert:fingerprint:'.$this->fingerprint($exception);

        // 同一エラーの連投を防ぎ、ノイズを抑える
        if (! Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return;
        }

        $this->sendSlackNotification($exception);
        $this->sendAdminDatabaseNotifications($exception);
    }

    private function sendSlackNotification(Throwable $exception): void
    {
        $webhookUrl = (string) config('services.slack.alert_webhook_url', '');

        if ($webhookUrl === '') {
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

    private function sendAdminDatabaseNotifications(Throwable $exception): void
    {
        $adminUsers = User::query()
            ->where('is_admin', true)
            ->get();

        if ($adminUsers->isEmpty()) {
            return;
        }

        $payload = FilamentNotification::make()
            ->title('システム例外を検知しました')
            ->danger()
            ->body(Str::limit($exception::class.': '.$exception->getMessage(), 500))
            ->actions([
                Action::make('markAsRead')
                    ->label('既読にする')
                    ->button()
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();

        $payload['app_id'] = null;

        foreach ($adminUsers as $adminUser) {
            $adminUser->notify(new FilamentDatabaseNotification($payload));
            DatabaseNotificationsSent::dispatch($adminUser);
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
