<?php

namespace App\Filament\Resources\QueueMonitorResource\Pages;

use App\Filament\Resources\Concerns\HasAdminScreenAccess;
use App\Support\AdminScreen;
use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource\Pages\ListQueueMonitors as BaseListQueueMonitors;

class ListQueueMonitors extends BaseListQueueMonitors
{
    use HasAdminScreenAccess;

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::JobManagement;
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccessAdminScreen(), 403);
    }
}
