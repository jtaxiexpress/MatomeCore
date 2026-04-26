<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

#[Signature('traffic:aggregate')]
#[Description('Aggregate 24h traffic metrics for articles and sites from Redis')]
class AggregateTrafficMetrics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting traffic metric aggregation from Redis...');

        DB::table('articles')->update(['daily_out_count' => 0]);
        DB::table('sites')->update(['daily_in_count' => 0, 'daily_out_count' => 0]);

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->processTraffic("traffic:in:{$today}", "traffic:in:{$yesterday}", 'sites', 'daily_in_count');
        $this->processTraffic("traffic:out:article:{$today}", "traffic:out:article:{$yesterday}", 'articles', 'daily_out_count');
        $this->processTraffic("traffic:out:site:{$today}", "traffic:out:site:{$yesterday}", 'sites', 'daily_out_count');

        // Score = (IN * 1.5) - OUT
        DB::table('sites')->update([
            'traffic_score' => DB::raw('FLOOR(daily_in_count * 1.5) - daily_out_count'),
        ]);

        $this->cleanupOldRedisKeys();

        $this->info('Traffic aggregation completed.');
    }

    private function processTraffic(string $todayKey, string $yesterdayKey, string $table, string $column): void
    {
        $todayData = Redis::hGetAll($todayKey) ?: [];
        $yesterdayData = Redis::hGetAll($yesterdayKey) ?: [];

        $counts = [];
        foreach ($todayData as $id => $count) {
            $counts[$id] = ($counts[$id] ?? 0) + (int) $count;
        }
        foreach ($yesterdayData as $id => $count) {
            $counts[$id] = ($counts[$id] ?? 0) + (int) $count;
        }

        if (empty($counts)) {
            return;
        }

        $chunks = array_chunk($counts, 1000, true);
        foreach ($chunks as $chunk) {
            $ids = [];
            $cases = [];
            foreach ($chunk as $id => $count) {
                $id = (int) $id;
                $ids[] = $id;
                $cases[] = "WHEN {$id} THEN {$count}";
            }

            if (! empty($ids)) {
                $casesSql = implode(' ', $cases);
                DB::table($table)
                    ->whereIn('id', $ids)
                    ->update([
                        $column => DB::raw("CASE id {$casesSql} ELSE {$column} END"),
                    ]);
            }
        }
    }

    private function cleanupOldRedisKeys(): void
    {
        // Keep today and yesterday. Delete older keys (up to 5 days ago to catch up missed runs)
        for ($i = 2; $i <= 5; $i++) {
            $date = now()->subDays($i)->toDateString();
            Redis::del("traffic:in:{$date}");
            Redis::del("traffic:out:article:{$date}");
            Redis::del("traffic:out:site:{$date}");
        }
    }
}
