<?php

namespace App\Filament\Resources;

use App\Actions\CategorizeArticleAction;
use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                            ->afterStateUpdated(function (Set $set) {
                                $set('category_id', null);
                                $set('site_id', null);
                            })
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('category_id')
                            ->label('カテゴリ')
                            ->options(fn (Get $get) => Category::where('app_id', $get('app_id'))->pluck('name', 'id'))
                            ->placeholder(fn (Get $get) => $get('app_id') ? 'カテゴリを選択' : 'まずアプリを選択してください')
                            ->searchable()
                            ->preload(),
                        Select::make('site_id')
                            ->label('配信元サイト')
                            ->options(fn (Get $get) => Site::where('app_id', $get('app_id'))->pluck('name', 'id'))
                            ->placeholder(fn (Get $get) => $get('app_id') ? 'サイトを選択' : 'まずアプリを選択してください')
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
                TextColumn::make('fetch_source')->label('取得元')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'fetch_past_sitemap' => '過去記事一括(サイトマップ)',
                        'fetch_past_html' => '過去記事一括(HTML解析)',
                        'rss' => '新規記事取得(RSS)',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'fetch_past_sitemap' => 'warning',
                        'fetch_past_html' => 'danger',
                        'rss' => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('published_at')->label('公開日時')->dateTime('Y/n/j H:i')->sortable(),
                TextColumn::make('created_at')->label('作成日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_checked_at')
                    ->label('リンク確認日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ])
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageArticles::route('/'),
        ];
    }
}
