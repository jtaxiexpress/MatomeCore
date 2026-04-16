<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Notifications\DailyCategorySummaryNotification;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendDailyCategoryReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_daily_category_report_with_expected_format(): void
    {
        config(['services.slack.report_webhook_url' => 'https://hooks.slack.test/services/report-webhook']);

        Notification::fake();

        $app = AppModel::factory()->create();
        $site = Site::factory()->for($app)->create();

        $history = Category::factory()->for($app)->create(['name' => '歴史']);
        $baseball = Category::factory()->for($app)->create(['name' => '野球']);
        $economy = Category::factory()->for($app)->create(['name' => '経済']);

        Article::factory()->for($app)->for($site)->for($history)->count(2)->create([
            'published_at' => now(),
            'created_at' => now()->subHours(6),
            'updated_at' => now()->subHours(6),
        ]);

        Article::factory()->for($app)->for($site)->for($history)->count(3)->create([
            'published_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        Article::factory()->for($app)->for($site)->for($baseball)->count(1)->create([
            'published_at' => now(),
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        Article::factory()->for($app)->for($site)->for($baseball)->count(2)->create([
            'published_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        Article::factory()->for($app)->for($site)->for($economy)->count(4)->create([
            'published_at' => now()->subDays(4),
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ]);

        $this->artisan('app:send-daily-category-report')
            ->assertExitCode(Command::SUCCESS);

        Notification::assertSentOnDemand(
            DailyCategorySummaryNotification::class,
            function (DailyCategorySummaryNotification $notification, array $channels, object $notifiable): bool {
                $body = $notification->buildBody();

                return in_array('slack', $channels, true)
                    && ($notifiable->routes['slack'] ?? null) === 'https://hooks.slack.test/services/report-webhook'
                    && str_contains($body, '歴史: 2件 / 合計5件')
                    && str_contains($body, '野球: 1件 / 合計3件')
                    && ! str_contains($body, '経済:');
            }
        );
    }

    public function test_it_sends_empty_report_when_no_recent_articles_exist(): void
    {
        config(['services.slack.report_webhook_url' => 'https://hooks.slack.test/services/report-webhook']);

        Notification::fake();

        $app = AppModel::factory()->create();
        $site = Site::factory()->for($app)->create();
        $history = Category::factory()->for($app)->create(['name' => '歴史']);

        Article::factory()->for($app)->for($site)->for($history)->count(2)->create([
            'published_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $this->artisan('app:send-daily-category-report')
            ->assertExitCode(Command::SUCCESS);

        Notification::assertSentOnDemand(
            DailyCategorySummaryNotification::class,
            function (DailyCategorySummaryNotification $notification): bool {
                return str_contains($notification->buildBody(), 'No new articles in the last 24 hours.');
            }
        );
    }

    public function test_it_fails_when_report_webhook_is_missing(): void
    {
        config(['services.slack.report_webhook_url' => null]);

        Notification::fake();

        $this->artisan('app:send-daily-category-report')
            ->assertExitCode(Command::FAILURE);

        Notification::assertNothingSent();
    }
}
