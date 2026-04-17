<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\SiteResource;
use App\Filament\Widgets\ArticleTrendChart;
use App\Filament\Widgets\InactiveSitesTable;
use App\Filament\Widgets\SystemStatsOverview;
use App\Http\Middleware\ShareTenantLogContext;
use App\Livewire\Filament\ScopedDatabaseNotifications;
use App\Models\App;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->login()
            ->tenant(App::class, 'api_slug', 'app')
            ->tenantSwitcher()
            ->tenantMiddleware([
                ShareTenantLogContext::class,
            ], isPersistent: true)
            ->colors([
                'primary' => Color::Sky,
            ])
            ->font('Inter')
            ->brandName('MatomeCore App')
            ->resources([
                SiteResource::class,
                CategoryResource::class,
                ArticleResource::class,
            ])
            ->pages([
                Pages\Dashboard::class,
            ])
            ->databaseNotifications(livewireComponent: ScopedDatabaseNotifications::class)
            ->databaseNotificationsPolling('30s')
            ->userMenuItems([
                MenuItem::make()
                    ->label('システム管理 (Adminパネル) へ')
                    ->url('/admin')
                    ->icon('heroicon-o-cog-8-tooth')
                    ->visible(fn (): bool => auth()->user()?->is_admin ?? false),
                'logout' => fn (Action $action): Action => $action->hidden(),
            ])
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): View => view('filament.app-sidebar-footer'),
            )
            ->widgets([
                SystemStatsOverview::class,
                ArticleTrendChart::class,
                InactiveSitesTable::class,
            ])
            ->navigationItems([
                NavigationItem::make('ログビューア')
                    ->url(fn (): string => route('log-viewer.index'))
                    ->icon('heroicon-o-document-text')
                    ->group('コンテンツ管理')
                    ->sort(4)
                    ->openUrlInNewTab(),
            ])
            ->navigationGroups([
                'コンテンツ管理',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
