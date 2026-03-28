<?php

use App\Models\App;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Appごとのダイナミックスケジュール登録
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
try {
    if (Schema::hasTable('apps')) {
        $apps = App::where('is_active', true)->get();

        foreach ($apps as $app) {
            $event = Schedule::command("app:crawl-all-sites --app_id={$app->id}");

            match ($app->fetch_frequency) {
                '1_hour' => $event->hourly(),
                '3_hours' => $event->everyThreeHours(),
                '12_hours' => $event->twiceDaily(0, 12),
                'daily' => $event->dailyAt($app->fetch_time ? Carbon::parse($app->fetch_time)->format('H:i') : '00:00'),
                default => $event->everyThreeHours(),
            };
        }
    }
} catch (Throwable $e) {
    // データベース接続エラー時やマイグレーション前はスケジュール登録をスキップする
    // これにより、view:clear や migrate などのコマンドがDB接続エラーで止まるのを防ぎます
}
