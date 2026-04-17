<?php

declare(strict_types=1);

namespace App\Livewire\Filament;

use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ScopedDatabaseNotifications extends DatabaseNotifications
{
    public function getNotificationsQuery(): Builder|Relation
    {
        $query = parent::getNotificationsQuery();

        $panel = Filament::getCurrentPanel();

        if ($panel?->getId() !== 'app') {
            return $query;
        }

        $tenant = Filament::getTenant();

        if (! $tenant) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('data->app_id', $tenant->getKey());
    }

    public function getTrigger(): ?View
    {
        return view('filament.components.scoped-database-notifications-trigger');
    }
}
