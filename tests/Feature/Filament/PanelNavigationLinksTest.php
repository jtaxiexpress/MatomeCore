<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use Filament\Navigation\MenuItem;
use Filament\Panel;
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
