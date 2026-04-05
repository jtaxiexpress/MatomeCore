<?php

namespace App\Filament\Widgets;

use App\Models\Article;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SystemStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $pendingJobsCount = DB::table('jobs')->count();
        $failedJobsCount = DB::table('failed_jobs')->count();

        return [
            Stat::make('本日の取得記事数', Article::whereDate('created_at', today())->count())
                ->description('今日新しく保存された記事数')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('success'),

            Stat::make('待機中のジョブ', $pendingJobsCount)
                ->description('処理待ちのキュー')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingJobsCount >= 1 ? 'warning' : 'success'),

            Stat::make('失敗したジョブ', $failedJobsCount)
                ->description('エラー発生数')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($failedJobsCount >= 1 ? 'danger' : 'success'),
        ];
    }
}
