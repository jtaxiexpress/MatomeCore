<?php

namespace App\Console\Commands;

use App\Models\App;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class CrawlAllSitesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:crawl-all-sites {--app_id= : 特定のAppIDのみを対象にする（省略時は全App）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl all active sites (optionally scoped to a single App) with sleep intervals and error isolation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appId = $this->option('app_id');
        $query = Site::where('is_active', true);

        if ($appId) {
            $app = App::find($appId);
            if (! $app) {
                $this->error("App ID {$appId} が見つかりません。");
                Log::warning("CrawlAllSitesCommand: App ID {$appId} not found.");

                return 1;
            }
            $this->info("App [{$app->name}] のサイトをクロール開始します...");
            Log::info("CrawlAllSitesCommand: Starting scheduled crawl for App [{$app->name}] (ID: {$appId})");
            $query->where('app_id', $appId);
        } else {
            $this->info('Starting scheduled crawl for all active sites...');
            Log::info('CrawlAllSitesCommand: Starting scheduled crawl for all active sites.');
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->info('No active sites found. Exiting.');
            Log::info('CrawlAllSitesCommand: No active sites found. Exiting.');

            return 0;
        }

        Log::info("CrawlAllSitesCommand: Found {$sites->count()} active sites. Processing...");

        $delayIndex = 0;
        foreach ($sites as $site) {
            $this->info('--------------------------------------------------');
            $this->info("Processing Site ID: {$site->id} | Name: {$site->name} | Type: {$site->crawler_type}");
            Log::info("CrawlAllSitesCommand: Queueing Site ID: {$site->id} | Name: {$site->name}");

            try {
                // 2秒ずつ間隔を空けてキューに積む
                $delay = now()->addSeconds($delayIndex * 2);
                \App\Jobs\CrawlSiteJob::dispatch($site->id)->delay($delay);
                $this->info("Queued for execution at {$delay->toDateTimeString()}");
                $delayIndex++;
            } catch (\Exception $e) {
                $this->error("Failed to queue Site ID {$site->id}: ".$e->getMessage());
                Log::error("CrawlAllSitesCommand: Failed to queue Site {$site->id} - ".$e->getMessage());
            }
        }

        $this->info('--------------------------------------------------');
        $this->info('All sites have been successfully processed.');
        Log::info('CrawlAllSitesCommand: All sites have been successfully processed.');

        return 0;
    }
}
