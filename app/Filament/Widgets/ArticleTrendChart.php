<?php

namespace App\Filament\Widgets;

use App\Models\Article;
use Filament\Widgets\ChartWidget;

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
        $data = [];
        $labels = [];

        // Generate data for the past 7 days (including today)
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $labels[] = $date->format('m/d');

            $data[] = Article::whereDate('created_at', $date)->count();
        }

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
