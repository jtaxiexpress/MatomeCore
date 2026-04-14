<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageSites extends ManageRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth('4xl')
                ->modalHeading('サイトを新規登録')
                ->modalDescription('クローラーの対象となる新しいサイト情報を入力してください。')
                ->modalSubmitActionLabel('登録する'),
        ];
    }
}
