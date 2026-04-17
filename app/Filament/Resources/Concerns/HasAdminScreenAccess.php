<?php

namespace App\Filament\Resources\Concerns;

use App\Support\AdminScreen;

trait HasAdminScreenAccess
{
    abstract protected static function adminScreen(): ?AdminScreen;

    protected static function canAccessAdminScreen(): bool
    {
        $screen = static::adminScreen();

        if ($screen === null) {
            return false;
        }

        $user = auth()->user();

        return $user?->canAccessAdminScreen($screen) ?? false;
    }
}
