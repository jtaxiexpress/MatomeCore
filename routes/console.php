<?php

use App\Jobs\CheckDeadLinksJob;
use App\Models\App;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Queue Monitor等の自動クリーンアップ（毎日深夜3時）
Schedule::command('model:prune')->dailyAt('03:00');

// 失敗したジョブ履歴を1ヶ月(720時間)で削除（毎日深夜3時30分）
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:30');

// 毎日深夜4時に、500件だけリンク切れをチェックする
Schedule::job(new CheckDeadLinksJob)->dailyAt('04:00');

// 10分おきにトラフィック(勢い)を集計する
Schedule::command('traffic:aggregate')->everyTenMinutes()->withoutOverlapping();

// 毎朝9時にカテゴリ別の日次レポートを送信する
Schedule::command('app:send-daily-category-report')
    ->dailyAt('09:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(120);

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
