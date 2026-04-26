<?php

namespace App\Filament\Resources\QueueMonitorResource\Pages;

use App\Filament\Resources\Concerns\AuthorizesAdminScreenPage;
use App\Support\AdminScreen;
use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource\Pages\ListPendingJobs as BaseListPendingJobs;

class ListPendingJobs extends BaseListPendingJobs
{
    use AuthorizesAdminScreenPage;

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::JobManagement;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return parent::canAccess($parameters) && static::canAccessAdminScreen();
    }

    public function getSubNavigation(): array
    {
        $items = [
            ListQueueMonitors::class,
            ListPendingJobs::class,
        ];

        return $this->generateNavigationItems($items);
    }
}
