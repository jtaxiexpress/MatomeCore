<?php

namespace App\Filament\Widgets;

use App\Models\Article;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class SystemStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return [];
        }

        $todayArticlesCount = Article::query()
            ->whereBelongsTo($tenant, 'app')
            ->whereDate('created_at', today())
            ->count();

        $totalArticlesCount = Article::query()
            ->whereBelongsTo($tenant, 'app')
            ->count();

        $activeSitesCount = Site::query()
            ->whereBelongsTo($tenant, 'app')
            ->where('is_active', true)
            ->count();

        $stalledSitesCount = Site::query()
            ->whereBelongsTo($tenant, 'app')
            ->where('is_active', true)
            ->whereDoesntHave('articles', function (Builder $query): void {
                $query->where('created_at', '>=', now()->subDays(7));
            })
            ->count();

        return [
            Stat::make('本日の取得記事数', $todayArticlesCount)
                ->description('今日新しく保存された記事数')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('success'),

            Stat::make('総記事数', $totalArticlesCount)
                ->description('このアプリに保存されている記事の総数')
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('primary'),

            Stat::make('稼働中サイト数', $activeSitesCount)
                ->description('クローリング有効のサイト数')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('success'),

            Stat::make('更新停止サイト', $stalledSitesCount)
                ->description('7日以上記事取得がない有効サイト')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($stalledSitesCount >= 1 ? 'warning' : 'success'),
        ];
    }
}
