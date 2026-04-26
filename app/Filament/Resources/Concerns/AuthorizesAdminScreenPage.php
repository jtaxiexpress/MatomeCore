<?php

namespace App\Filament\Resources\Concerns;

trait AuthorizesAdminScreenPage
{
    use HasAdminScreenAccess;

    public static function canAccess(): bool
    {
        return static::canAccessAdminScreen();
    }
}
