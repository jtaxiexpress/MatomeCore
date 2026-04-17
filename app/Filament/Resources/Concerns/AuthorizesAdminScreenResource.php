<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

trait AuthorizesAdminScreenResource
{
    use HasAdminScreenAccess;

    public static function canAccess(): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canViewAny(): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canCreate(): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canForceDeleteAny(): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canRestore(Model $record): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canRestoreAny(): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canReplicate(Model $record): bool
    {
        return static::canAccessAdminScreen();
    }

    public static function canReorder(): bool
    {
        return static::canAccessAdminScreen();
    }
}
