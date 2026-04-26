<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Pages\AdminManageRecords;
use App\Filament\Resources\Users\UserResource;
use App\Support\AdminScreen;
use Filament\Actions\CreateAction;

class ManageUsers extends AdminManageRecords
{
    protected static string $resource = UserResource::class;

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::UserManagement;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
