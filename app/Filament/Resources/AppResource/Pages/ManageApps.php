<?php

namespace App\Filament\Resources\AppResource\Pages;

use App\Filament\Resources\AppResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageApps extends ManageRecords
{
    protected static string $resource = AppResource::class;

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
