<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('traffic:aggregate')]
#[Description('Aggregate 24h traffic metrics for articles and sites')]
class AggregateTrafficMetrics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting traffic metric aggregation...');

        $cutoff = now()->subHours(24);

        // 1. Reset existing counts
        DB::table('articles')->update(['daily_out_count' => 0]);
        DB::table('sites')->update(['daily_in_count' => 0, 'daily_out_count' => 0]);

        // 2. Aggregate OUT for Articles
        $articleOutCounts = DB::table('article_clicks')
            ->select('article_id', DB::raw('COUNT(*) as count'))
            ->where('clicked_at', '>=', $cutoff)
            ->groupBy('article_id')
            ->pluck('count', 'article_id');

        foreach ($articleOutCounts as $articleId => $count) {
            DB::table('articles')->where('id', $articleId)->update(['daily_out_count' => $count]);
        }

        // 3. Aggregate IN for Sites
        $siteInCounts = DB::table('site_ins')
            ->select('site_id', DB::raw('COUNT(*) as count'))
            ->where('visited_at', '>=', $cutoff)
            ->groupBy('site_id')
            ->pluck('count', 'site_id');

        foreach ($siteInCounts as $siteId => $count) {
            DB::table('sites')->where('id', $siteId)->update(['daily_in_count' => $count]);
        }

        // 4. Aggregate OUT for Sites
        $siteOutCounts = DB::table('article_clicks')
            ->join('articles', 'article_clicks.article_id', '=', 'articles.id')
            ->select('articles.site_id', DB::raw('COUNT(article_clicks.id) as count'))
            ->where('article_clicks.clicked_at', '>=', $cutoff)
            ->groupBy('articles.site_id')
            ->pluck('count', 'site_id');

        foreach ($siteOutCounts as $siteId => $count) {
            DB::table('sites')->where('id', $siteId)->update(['daily_out_count' => $count]);
        }

        // 5. Calculate Traffic Score
        // Score = (IN * 1.5) - OUT
        DB::table('sites')->update([
            'traffic_score' => DB::raw('FLOOR(daily_in_count * 1.5) - daily_out_count'),
        ]);

        $this->info('Traffic aggregation completed.');
    }
}
