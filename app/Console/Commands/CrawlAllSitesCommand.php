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

        if ($appId) {
            $app = App::find($appId);
            if (! $app) {
                $this->error("App ID {$appId} が見つかりません。");
                return 1;
            }
            $this->info("App [{$app->name}] のサイトをクロール開始します...");
            $sites = Site::where('is_active', true)->where('app_id', $appId)->get();
        } else {
            $this->info('Starting scheduled crawl for all active sites...');
            $sites = Site::where('is_active', true)->get();
        }

        if ($sites->isEmpty()) {
            $this->info('No active sites found. Exiting.');
            return 0;
        }

        foreach ($sites as $site) {
            $this->info('--------------------------------------------------');
            $this->info("Processing Site ID: {$site->id} | Name: {$site->name} | Type: {$site->crawler_type}");

            try {
                Artisan::call('app:crawl-site', ['site_id' => $site->id], $this->output);
            } catch (\Exception $e) {
                $this->error("Failed to process Site ID {$site->id}: " . $e->getMessage());
                Log::error("CrawlAllSitesCommand: Site {$site->id} failed - " . $e->getMessage());
            }

            $this->info('Sleeping for 2 seconds to prevent server overload...');
            sleep(2);
        }

        $this->info('--------------------------------------------------');
        $this->info('All sites have been successfully processed.');
        return 0;
    }
}
