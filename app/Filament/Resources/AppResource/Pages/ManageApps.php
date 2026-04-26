<?php

namespace App\Filament\Resources\AppResource\Pages;

use App\Filament\Resources\AppResource;
use App\Filament\Resources\Pages\AdminManageRecords;
use App\Support\AdminScreen;
use Filament\Actions;

class ManageApps extends AdminManageRecords
{
    protected static string $resource = AppResource::class;

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::AppManagement;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth('4xl')
                ->modalHeading('アプリ情報の新規作成')
                ->modalDescription('管理する新しいまとめアプリを追加します。')
                ->modalSubmitActionLabel('登録する'),
        ];
    }
}
