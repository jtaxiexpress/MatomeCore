<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Widgets\ArticleTrendChart;
use App\Filament\Widgets\SystemStatsOverview;
use App\Models\App as AppModel;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_stats_widget_uses_current_tenant_data_only(): void
    {
        $tenantA = AppModel::factory()->create(['api_slug' => 'tenant-a']);
        $tenantB = AppModel::factory()->create(['api_slug' => 'tenant-b']);

        $categoryA = Category::factory()->for($tenantA, 'app')->create();
        $categoryB = Category::factory()->for($tenantB, 'app')->create();

        $activeSite = Site::factory()->for($tenantA, 'app')->create(['is_active' => true]);
        $stalledSite = Site::factory()->for($tenantA, 'app')->create(['is_active' => true]);
        $otherTenantSite = Site::factory()->for($tenantB, 'app')->create(['is_active' => true]);

        Article::factory()->for($tenantA, 'app')->for($activeSite, 'site')->for($categoryA, 'category')->create([
            'created_at' => now(),
            'published_at' => now(),
        ]);

        Article::factory()->for($tenantA, 'app')->for($activeSite, 'site')->for($categoryA, 'category')->create([
            'created_at' => now()->subDays(2),
            'published_at' => now()->subDays(2),
        ]);

        Article::factory()->for($tenantA, 'app')->for($stalledSite, 'site')->for($categoryA, 'category')->create([
            'created_at' => now()->subDays(10),
            'published_at' => now()->subDays(10),
        ]);

        Article::factory()->for($tenantB, 'app')->for($otherTenantSite, 'site')->for($categoryB, 'category')->create([
            'created_at' => now(),
            'published_at' => now(),
        ]);

        Filament::setTenant($tenantA, isQuiet: true);

        /** @var array<int, Stat> $stats */
        $stats = $this->callProtectedMethod(app(SystemStatsOverview::class), 'getStats');

        $valuesByLabel = collect($stats)
            ->mapWithKeys(fn ($stat): array => [$stat->getLabel() => (int) $stat->getValue()])
            ->all();

        $this->assertSame(1, $valuesByLabel['本日の取得記事数']);
        $this->assertSame(3, $valuesByLabel['総記事数']);
        $this->assertSame(2, $valuesByLabel['稼働中サイト数']);
        $this->assertSame(1, $valuesByLabel['更新停止サイト']);

        Filament::setTenant(null, isQuiet: true);
    }

    public function test_article_trend_chart_uses_current_tenant_data_only(): void
    {
        $tenantA = AppModel::factory()->create(['api_slug' => 'tenant-a']);
        $tenantB = AppModel::factory()->create(['api_slug' => 'tenant-b']);

        $categoryA = Category::factory()->for($tenantA, 'app')->create();
        $categoryB = Category::factory()->for($tenantB, 'app')->create();

        $siteA = Site::factory()->for($tenantA, 'app')->create();
        $siteB = Site::factory()->for($tenantB, 'app')->create();

        Article::factory()->for($tenantA, 'app')->for($siteA, 'site')->for($categoryA, 'category')->create([
            'created_at' => now(),
            'published_at' => now(),
        ]);

        Article::factory()->for($tenantA, 'app')->for($siteA, 'site')->for($categoryA, 'category')->create([
            'created_at' => now(),
            'published_at' => now(),
        ]);

        Article::factory()->for($tenantA, 'app')->for($siteA, 'site')->for($categoryA, 'category')->create([
            'created_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        Article::factory()->for($tenantB, 'app')->for($siteB, 'site')->for($categoryB, 'category')->create([
            'created_at' => now(),
            'published_at' => now(),
        ]);

        Filament::setTenant($tenantA, isQuiet: true);

        /** @var array{datasets: array<int, array{label: string, data: array<int, int>}>, labels: array<int, string>} $chart */
        $chart = $this->callProtectedMethod(app(ArticleTrendChart::class), 'getData');

        $this->assertCount(7, $chart['labels']);
        $this->assertSame(3, array_sum($chart['datasets'][0]['data']));
        $this->assertSame(2, $chart['datasets'][0]['data'][6]);

        Filament::setTenant(null, isQuiet: true);
    }

    private function callProtectedMethod(object $instance, string $method): mixed
    {
        $reflectionMethod = new \ReflectionMethod($instance, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($instance);
    }
}
