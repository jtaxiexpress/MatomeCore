<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard as AdminDashboard;
use App\Filament\Pages\ExceptionAlerts;
use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\AppResource;
use App\Filament\Resources\Users\UserResource;
use App\Livewire\Filament\ScopedDatabaseNotifications;
use App\Support\AdminScreen;
use Croustibat\FilamentJobsMonitor\FilamentJobsMonitorPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->brandName('ゆにこーんアンテナ Admin')
            ->homeUrl('/admin')
            ->resources([
                AppResource::class,
                UserResource::class,
            ])
            ->pages([
                AdminDashboard::class,
                SystemSettings::class,
                ExceptionAlerts::class,
            ])
            ->databaseNotifications(livewireComponent: ScopedDatabaseNotifications::class)
            ->databaseNotificationsPolling('30s')
            ->userMenuItems([
                MenuItem::make()
                    ->label('アプリ管理 (Appパネル) へ')
                    ->url('/app')
                    ->icon('heroicon-o-squares-2x2'),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->navigationItems([
                NavigationItem::make('ログビューア')
                    ->url(fn (): string => route('log-viewer.index'))
                    ->icon('heroicon-o-document-text')
                    ->group('監視')
                    ->sort(1)
                    ->visible(fn (): bool => auth()->user()?->canAccessAdminScreen(AdminScreen::LogViewer) ?? false),
            ])
            ->navigationGroups([
                'プラットフォーム管理',
                'システム設定',
                '監視',
            ])
            ->plugin(FilamentJobsMonitorPlugin::make())
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
