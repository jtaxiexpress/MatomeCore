<?php

namespace App\Filament\Resources\AppResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

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
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('サイト名'),
                Tables\Columns\TextColumn::make('url')->label('URL')->limit(30),
                Tables\Columns\ToggleColumn::make('is_active')->label('ステータス'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('サイトを追加')
                    ->modalDescription('このアプリから配信するサイトを新規に追加します。')
                    ->modalSubmitActionLabel('追加する'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('サイトの編集')
                    ->modalDescription('配信対象のサイト情報を編集します。')
                    ->modalSubmitActionLabel('更新する'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
