<?php

namespace App\Filament\Resources\AppResource\RelationManagers;

use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = 'サイト';

    protected static ?string $modelLabel = 'サイト';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('サイト名')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('url')
                    ->label('サイトURL')
                    ->required()
                    ->maxLength(255)
                    ->url(),
                Forms\Components\TextInput::make('rss_url')
                    ->label('RSS URL')
                    ->placeholder('https://example.com/feed')
                    ->maxLength(255)
                    ->url(),
                Forms\Components\Toggle::make('is_active')
                    ->label('クローリング有効')
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withMax('articles', 'created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('サイト名')->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('articles_count')->counts('articles')->label('記事数')->badge()->sortable(),
                Tables\Columns\TextColumn::make('articles_max_created_at')
                    ->label('最終取得日')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'danger',
                        Carbon::parse($state) >= now()->subDays(3) => 'success',
                        Carbon::parse($state) >= now()->subDays(7) => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\ToggleColumn::make('is_active')->label('ステータス'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('サイトを追加')
                    ->modalDescription('このアプリから配信するサイトを新規に追加します。')
                    ->modalSubmitActionLabel('追加する'),
            ])
            ->actions([
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('サイトの編集')
                    ->modalDescription('配信対象のサイト情報を編集します。')
                    ->modalSubmitActionLabel('更新する'),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
