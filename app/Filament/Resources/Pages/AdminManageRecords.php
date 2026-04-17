<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Concerns\HasAdminScreenAccess;
use App\Support\AdminScreen;
use Filament\Resources\Pages\ManageRecords;

abstract class AdminManageRecords extends ManageRecords
{
    use HasAdminScreenAccess;

    abstract protected static function adminScreen(): ?AdminScreen;

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccessAdminScreen(), 403);
    }
}
