<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageArticles extends ManageRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth('4xl')
                ->modalHeading('記事の新規作成')
                ->modalDescription('手動で新しい記事を追加します。')
                ->modalSubmitActionLabel('登録する'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ArticleResource\Widgets\PendingSiteApplicationsWidget::class,
        ];
    }
}
