<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Site;
use App\Models\User;
use App\Notifications\FilamentDatabaseNotification;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Builder;

class SendArticleFetchResultNotificationAction
{
    public function execute(
        Site $site,
        string $fetchSource,
        int $savedCount = 0,
        int $missedCount = 0,
        ?string $detail = null,
        bool $failed = false,
    ): void {
        $site->loadMissing('app');

        if (! $site->app_id) {
            return;
        }

        $recipients = User::query()
            ->where(function (Builder $query) use ($site): void {
                $query->where('is_admin', true)
                    ->orWhereHas('apps', function (Builder $appQuery) use ($site): void {
                        $appQuery->whereKey($site->app_id);
                    });
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $sourceLabel = $this->sourceLabel($fetchSource);
        $title = sprintf('%s - %s', $site->name, $sourceLabel);
        $body = $this->buildBody($site, $sourceLabel, $savedCount, $missedCount, $detail);

        $notification = FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->actions([
                Action::make('markAsRead')
                    ->label('既読にする')
                    ->button()
                    ->markAsRead(),
            ]);

        if ($failed) {
            $notification->danger();
        } elseif ($savedCount > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $payload = $notification->getDatabaseMessage();
        $payload['app_id'] = $site->app_id;
        $payload['source'] = $fetchSource;

        foreach ($recipients as $recipient) {
            $recipient->notify(new FilamentDatabaseNotification($payload));
            DatabaseNotificationsSent::dispatch($recipient);
        }
    }

    private function sourceLabel(string $fetchSource): string
    {
        return match ($fetchSource) {
            'rss' => 'RSS新規記事取得',
            'fetch_past_sitemap', 'fetch_past_html' => '過去記事一括取得',
            default => '記事取得',
        };
    }

    private function buildBody(
        Site $site,
        string $sourceLabel,
        int $savedCount,
        int $missedCount,
        ?string $detail,
    ): string {
        $lines = [
            $site->app?->name ? 'アプリ: '.$site->app->name : null,
            'サイト: '.$site->name,
            '取得元: '.$sourceLabel,
        ];

        if (filled($detail)) {
            $lines[] = $detail;
        } elseif ($savedCount === 0 && $missedCount === 0) {
            $lines[] = '新規記事はありませんでした。';
        } else {
            $lines[] = sprintf('保存 %s件 / AI漏れ %s件', number_format($savedCount), number_format($missedCount));
        }

        return implode("\n", array_values(array_filter($lines, static fn (?string $line): bool => filled($line))));
    }
}
