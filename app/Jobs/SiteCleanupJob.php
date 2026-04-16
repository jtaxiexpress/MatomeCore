<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SiteCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1. 安全装置として、nullやバグ日付(1970年等)を弾き、7日以上前から失敗しているサイトを取得
        $deadSites = Site::with('app')
            ->whereNotNull('failing_since')
            ->where('failing_since', '>=', '2020-01-01')
            ->where('failing_since', '<=', now()->subDays(7))
            ->get();

        foreach ($deadSites as $site) {
            Log::withContext([
                'site_id' => $site->id,
                'app_id' => $site->app_id,
                'app_slug' => (string) data_get($site, 'app.api_slug'),
            ]);

            Log::info("閉鎖サイト削除開始: サイトID {$site->id} ({$site->name})");

            // 2. データベースのロック・クラッシュを防ぐため、1000件ずつ分割して記事を物理削除
            Article::where('site_id', $site->id)->chunkById(1000, function ($articles) {
                foreach ($articles as $article) {
                    $article->delete();
                }
            });

            // 3. 紐づく記事がすべて消えた後、サイト自体も削除する
            $site->delete();

            Log::info("閉鎖サイトおよび紐づく全記事の削除が完了しました。 (サイトID: {$site->id})");
        }
    }
}
