<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ExceptionAlerts;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\QueueMonitorResource;
use App\Filament\Resources\SiteResource;
use App\Filament\Widgets\ArticleTrendChart;
use App\Filament\Widgets\InactiveSitesTable;
use App\Filament\Widgets\SystemStatsOverview;
use App\Http\Middleware\ShareTenantLogContext;
use App\Models\App as AppModel;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelNavigationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_viewer_returns_to_admin_panel(): void
    {
        $this->assertSame(rtrim((string) config('app.url', ''), '/').'/admin', config('log-viewer.back_to_system_url'));
        $this->assertSame('システム管理画面に戻る', config('log-viewer.back_to_system_label'));
    }

    public function test_admin_panel_user_menu_links_to_app_panel(): void
    {
        $panel = (new AdminPanelProvider($this->app))->panel(Panel::make());
        $userMenuItems = $this->getPanelUserMenuItems($panel);

        $this->assertCount(1, $userMenuItems);
        $this->assertInstanceOf(MenuItem::class, $userMenuItems[0]);
        $this->assertSame('アプリ管理 (Appパネル) へ', $userMenuItems[0]->getLabel());
        $this->assertSame('/app', $userMenuItems[0]->getUrl());
    }

    public function test_app_panel_user_menu_shows_admin_panel_link_for_admin_user(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());
        $userMenuItems = $this->getPanelUserMenuItems($panel);

        $this->assertArrayHasKey('logout', $userMenuItems);
        $this->assertIsCallable($userMenuItems['logout']);

        $menuItem = collect($userMenuItems)
            ->first(fn ($item): bool => $item instanceof MenuItem);

        $this->assertInstanceOf(MenuItem::class, $menuItem);
        $this->assertSame('システム管理 (Adminパネル) へ', $menuItem->getLabel());
        $this->assertSame('/admin', $menuItem->getUrl());
        $this->assertTrue($menuItem->isVisible());
    }

    public function test_app_panel_user_menu_hides_admin_panel_link_for_non_admin_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());
        $userMenuItems = $this->getPanelUserMenuItems($panel);

        $menuItem = collect($userMenuItems)
            ->first(fn ($item): bool => $item instanceof MenuItem);

        $this->assertInstanceOf(MenuItem::class, $menuItem);
        $this->assertFalse($menuItem->isVisible());
    }

    public function test_app_panel_home_is_not_fixed_to_admin_tenant(): void
    {
        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());

        $this->assertNull($panel->getHomeUrl());
    }

    public function test_app_panel_registers_operational_dashboard_widgets(): void
    {
        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());

        $this->assertSame([
            SystemStatsOverview::class,
            ArticleTrendChart::class,
            InactiveSitesTable::class,
        ], $panel->getWidgets());
    }

    public function test_app_dashboard_does_not_show_account_widget_ui(): void
    {
        $user = User::factory()->admin()->create();
        AppModel::factory()->create([
            'name' => 'App 3',
            'api_slug' => 'app-3',
        ]);

        $this->actingAs($user)
            ->get('/app/app-3')
            ->assertOk()
            ->assertDontSee('ようこそ', false)
            ->assertDontSee('ログアウト', false)
            ->assertDontSee('fi-account-widget', false);
    }

    public function test_app_panel_has_log_viewer_navigation_item(): void
    {
        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());
        $navigationItems = $panel->getNavigationItems();

        $this->assertCount(1, $navigationItems);
        $this->assertInstanceOf(NavigationItem::class, $navigationItems[0]);
        $this->assertSame('ログビューア', $navigationItems[0]->getLabel());
        $this->assertSame(route('log-viewer.index'), $navigationItems[0]->getUrl());
        $this->assertSame('コンテンツ管理', $navigationItems[0]->getGroup());
        $this->assertTrue($navigationItems[0]->shouldOpenUrlInNewTab());
    }

    public function test_app_panel_navigation_labels_match_requested_content_ia(): void
    {
        $this->assertSame('サイト管理', SiteResource::getNavigationLabel());
        $this->assertSame(1, SiteResource::getNavigationSort());
        $this->assertSame('カテゴリー管理', CategoryResource::getNavigationLabel());
        $this->assertSame(2, CategoryResource::getNavigationSort());
        $this->assertSame('記事管理', ArticleResource::getNavigationLabel());
        $this->assertSame(3, ArticleResource::getNavigationSort());
    }

    public function test_admin_panel_places_jobs_above_notification_rules(): void
    {
        $this->assertSame('通知ルール管理', ExceptionAlerts::getNavigationLabel());
        $this->assertSame('システム設定', ExceptionAlerts::getNavigationGroup());
        $this->assertSame(5, ExceptionAlerts::getNavigationSort());
        $this->assertSame('Jobs', QueueMonitorResource::getNavigationLabel());
        $this->assertLessThan(
            ExceptionAlerts::getNavigationSort(),
            QueueMonitorResource::getNavigationSort(),
        );
    }

    public function test_app_panel_registers_tenant_log_context_middleware(): void
    {
        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());

        $this->assertContains(ShareTenantLogContext::class, $panel->getTenantMiddleware());
    }

    public function test_app_history_page_renders_back_link_to_admin_tenant(): void
    {
        $user = User::factory()->admin()->create();
        AppModel::factory()->create([
            'name' => 'History',
            'api_slug' => 'history',
        ]);

        AppModel::factory()->create([
            'name' => 'Admin',
            'api_slug' => 'admin',
        ]);

        $this->actingAs($user)
            ->get('/app/history')
            ->assertOk()
            ->assertSee('Adminに戻る', false)
            ->assertSee('href="/admin"', false);
    }

    public function test_app_page_hides_sidebar_back_link_for_non_admin_user(): void
    {
        $app = AppModel::factory()->create([
            'name' => 'Member App',
            'api_slug' => 'member-app',
        ]);

        $user = User::factory()->create(['is_admin' => false]);
        $user->apps()->attach($app);

        $this->actingAs($user)
            ->get('/app/member-app')
            ->assertOk()
            ->assertDontSee('Adminに戻る', false)
            ->assertDontSee('href="/admin"', false);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function getPanelUserMenuItems(Panel $panel): array
    {
        $property = new \ReflectionProperty($panel, 'userMenuItems');
        $property->setAccessible(true);

        /** @var array<int|string, mixed> $userMenuItems */
        $userMenuItems = $property->getValue($panel);

        return $userMenuItems;
    }
}
