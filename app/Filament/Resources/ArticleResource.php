<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Actions\CategorizeArticleAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('記事コンテンツ')
                    ->schema([
                        TextInput::make('title')
                            ->label('記事タイトル')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('url')
                            ->label('元記事URL')
                            ->required()
                            ->maxLength(255)
                            ->url(),
                        TextInput::make('thumbnail_url')
                            ->label('サムネイル画像URL')
                            ->maxLength(255)
                            ->url(),
                    ]),
                Section::make('メタデータ')
                    ->schema([
                        Select::make('app_id')
                            ->label('配信アプリ')
                            ->relationship('app', 'name')
                            ->live()
                            ->afterStateUpdated(fn (\Filament\Schemas\Components\Utilities\Set $set) => $set('category_id', null))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('category_id')
                            ->label('カテゴリ')
                            ->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => \App\Models\Category::where('app_id', $get('app_id'))->pluck('name', 'id'))
                            ->placeholder(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('app_id') ? 'カテゴリを選択' : 'まずアプリを選択してください')
                            ->searchable()
                            ->preload(),
                        Select::make('site_id')
                            ->label('配信元サイト')
                            ->relationship('site', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        DateTimePicker::make('published_at')
                            ->label('公開日時')
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_url')->label('画像')->square(),
                TextColumn::make('title')->label('タイトル')->searchable()->limit(40),
                TextColumn::make('app.name')->label('アプリ')->sortable()->badge()->color('info'),
                TextColumn::make('category.name')->label('カテゴリ')->sortable()->badge(),
                TextColumn::make('site.name')->label('配信元')->sortable(),
                TextColumn::make('fetch_source')->label('取得元')->badge()->searchable(),
                TextColumn::make('published_at')->label('公開日時')->dateTime('Y/n/j H:i')->sortable(),
                TextColumn::make('created_at')->label('作成日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Action::make('categorize')
                    ->label('AI自動分類')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->action(function (Article $record, CategorizeArticleAction $action) {
                        $action->execute($record);
                        Notification::make()
                            ->title('AIによるカテゴリ分類が完了しました')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('この記事をAIで自動分類しますか？'),
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('記事の編集')
                    ->modalDescription('記事の内容やメタデータを変更します。')
                    ->modalSubmitActionLabel('更新する'),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageArticles::route('/'),
        ];
    }
}
