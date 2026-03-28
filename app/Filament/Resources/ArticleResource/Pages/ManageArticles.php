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

    public function getTabs(): array
    {
        $tabs = [
            'all' => \Filament\Schemas\Components\Tabs\Tab::make('すべて'),
        ];

        $apps = \App\Models\App::all();
        foreach ($apps as $app) {
            $tabs[$app->id] = \Filament\Schemas\Components\Tabs\Tab::make($app->name)
                ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('app_id', $app->id));
        }

        return $tabs;
    }
}
