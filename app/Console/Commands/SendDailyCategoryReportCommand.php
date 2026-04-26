<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Notifications\DailyCategorySummaryNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendDailyCategoryReportCommand extends Command
{
    protected $signature = 'app:send-daily-category-report';

    protected $description = 'カテゴリごとの新規記事数と累計記事数をSlackへ送信します';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $webhookUrl = (string) config('services.slack.report_webhook_url', '');

        if ($webhookUrl === '') {
            $this->error('SLACK_REPORT_WEBHOOK_URL is not configured.');

            return self::FAILURE;
        }

        $windowEnd = CarbonImmutable::now();
        $windowStart = $windowEnd->subDay();

        $recentCounts = Article::query()
            ->whereNotNull('category_id')
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('category_id, COUNT(*) as recent_count')
            ->groupBy('category_id')
            ->orderByDesc('recent_count')
            ->get();

        if ($recentCounts->isEmpty()) {
            if (! $this->sendReportNotification(
                webhookUrl: $webhookUrl,
                windowStart: $windowStart,
                windowEnd: $windowEnd,
                lines: [],
                totalNewCount: 0,
            )) {
                $this->warn('Failed to send daily category report to Slack. See logs for details.');

                return self::SUCCESS;
            }

            $this->info('No new articles in the last 24 hours. Empty report sent.');

            return self::SUCCESS;
        }

        $categoryIds = $recentCounts
            ->pluck('category_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $totalCounts = Article::query()
            ->whereIn('category_id', $categoryIds)
            ->selectRaw('category_id, COUNT(*) as total_count')
            ->groupBy('category_id')
            ->pluck('total_count', 'category_id');

        $categoryNames = Category::query()
            ->whereIn('id', $categoryIds)
            ->pluck('name', 'id');

        $totalNewCount = 0;
        $lines = [];

        foreach ($recentCounts as $recentCount) {
            $categoryId = (int) $recentCount->category_id;
            $newCount = (int) $recentCount->recent_count;

            if ($newCount <= 0) {
                continue;
            }

            $totalNewCount += $newCount;

            $categoryName = (string) ($categoryNames[$categoryId] ?? "Category ID {$categoryId}");
            $categoryTotalCount = (int) ($totalCounts[$categoryId] ?? 0);

            $lines[] = sprintf(
                '%s: %s件 / 合計%s件',
                $categoryName,
                number_format($newCount),
                number_format($categoryTotalCount),
            );
        }

        if (! $this->sendReportNotification(
            webhookUrl: $webhookUrl,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            lines: $lines,
            totalNewCount: $totalNewCount,
        )) {
            $this->warn('Failed to send daily category report to Slack. See logs for details.');

            return self::SUCCESS;
        }

        $this->info('Daily category report sent to Slack.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function sendReportNotification(
        string $webhookUrl,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        array $lines,
        int $totalNewCount,
    ): bool {
        try {
            Notification::route('slack', $webhookUrl)
                ->notify(new DailyCategorySummaryNotification(
                    windowStart: $windowStart,
                    windowEnd: $windowEnd,
                    lines: $lines,
                    totalNewCount: $totalNewCount,
                ));

            return true;
        } catch (Throwable $exception) {
            Log::error('[SendDailyCategoryReportCommand] Failed to send report notification to Slack.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'line_count' => count($lines),
                'total_new_count' => $totalNewCount,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
            ]);

            return false;
        }
    }
}
