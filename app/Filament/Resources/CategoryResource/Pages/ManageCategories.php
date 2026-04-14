<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth('4xl')
                ->modalHeading('カテゴリーを新規作成')
                ->modalDescription('アプリに関連付ける新しいカテゴリーを作成します。')
                ->modalSubmitActionLabel('登録する'),
        ];
    }
}
