<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

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
use Filament\Widgets\AccountWidget;
use Tests\TestCase;

class PanelNavigationLinksTest extends TestCase
{
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

    public function test_app_panel_user_menu_links_to_admin_panel(): void
    {
        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());
        $userMenuItems = $this->getPanelUserMenuItems($panel);

        $this->assertCount(1, $userMenuItems);
        $this->assertInstanceOf(MenuItem::class, $userMenuItems[0]);
        $this->assertSame('システム管理 (Adminパネル) へ', $userMenuItems[0]->getLabel());
        $this->assertSame('/admin', $userMenuItems[0]->getUrl());
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
            AccountWidget::class,
            SystemStatsOverview::class,
            ArticleTrendChart::class,
            InactiveSitesTable::class,
        ], $panel->getWidgets());
    }

    public function test_app_panel_has_log_viewer_navigation_item(): void
    {
        $panel = (new AppPanelProvider($this->app))->panel(Panel::make());
        $navigationItems = $panel->getNavigationItems();

        $this->assertCount(1, $navigationItems);
        $this->assertInstanceOf(NavigationItem::class, $navigationItems[0]);
        $this->assertSame('ログビューア', $navigationItems[0]->getLabel());
        $this->assertSame(route('log-viewer.index'), $navigationItems[0]->getUrl());
        $this->assertSame('システム管理', $navigationItems[0]->getGroup());
        $this->assertTrue($navigationItems[0]->shouldOpenUrlInNewTab());
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

    /**
     * @return array<int, MenuItem>
     */
    private function getPanelUserMenuItems(Panel $panel): array
    {
        $property = new \ReflectionProperty($panel, 'userMenuItems');
        $property->setAccessible(true);

        /** @var array<int, MenuItem> $userMenuItems */
        $userMenuItems = $property->getValue($panel);

        return $userMenuItems;
    }
}
