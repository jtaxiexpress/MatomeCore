<?php

namespace App\Filament\Widgets;

use App\Models\Article;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ArticleTrendChart extends ChartWidget
{
    protected ?string $heading = '過去7日間の記事取得トレンド';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $dates = collect(range(6, 0))
            ->map(fn (int $daysAgo): Carbon => today()->subDays($daysAgo));

        $labels = $dates
            ->map(fn (Carbon $date): string => $date->format('m/d'))
            ->all();

        $tenant = Filament::getTenant();

        if (! $tenant) {
            $data = array_fill(0, count($labels), 0);

            return [
                'datasets' => [
                    [
                        'label' => '取得記事数',
                        'data' => $data,
                    ],
                ],
                'labels' => $labels,
            ];
        }

        $countsByDate = Article::query()
            ->whereBelongsTo($tenant, 'app')
            ->whereDate('created_at', '>=', today()->subDays(6))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $data = $dates
            ->map(fn (Carbon $date): int => (int) ($countsByDate[$date->toDateString()] ?? 0))
            ->all();

        return [
            'datasets' => [
                [
                    'label' => '取得記事数',
                    'data' => $data,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
