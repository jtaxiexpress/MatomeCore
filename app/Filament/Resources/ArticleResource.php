<?php

namespace App\Filament\Resources;

use App\Actions\CategorizeArticleAction;
use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

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
                            ->url()
                            // 空欄でも表示側でカテゴリのデフォルト画像が自動適用される旨を案内
                            ->helperText('※空欄の場合、一覧表やアプリ側では「カテゴリのデフォルト画像」が自動的に適用されます。'),
                    ]),
                Section::make('メタデータ')
                    ->schema([
                        Select::make('category_id')
                            ->label('カテゴリ')
                            ->relationship(
                                name: 'category',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query): Builder {
                                    $tenant = Filament::getTenant();

                                    if (! $tenant) {
                                        return $query->whereRaw('1 = 0');
                                    }

                                    return $query
                                        ->whereBelongsTo($tenant, 'app')
                                        ->orderBy('sort_order')
                                        ->orderBy('name');
                                },
                            )
                            ->searchable()
                            ->preload(),
                        Select::make('site_id')
                            ->label('配信元サイト')
                            ->relationship(
                                name: 'site',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query): Builder {
                                    $tenant = Filament::getTenant();

                                    if (! $tenant) {
                                        return $query->whereRaw('1 = 0');
                                    }

                                    return $query
                                        ->whereBelongsTo($tenant, 'app')
                                        ->orderBy('name');
                                },
                            )
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
                // Filament の defaultImageUrl() でフォールバックを実装
                // display_thumbnail_url アクセサは API 側で引き続き使用するため削除しない
                ImageColumn::make('thumbnail_url')
                    ->label('画像')
                    ->square()
                    ->defaultImageUrl(function (Article $record): ?string {
                        $path = $record->category?->default_image_path;
                        if (empty($path)) {
                            return null;
                        }

                        return str_starts_with($path, 'http')
                            ? $path
                            : Storage::url($path);
                    }),
                TextColumn::make('title')
                    ->label('タイトル')
                    ->searchable()
                    ->limit(40)
                    // thumbnail_url が空の時はカテゴリデフォルト画像使用中であることを明示
                    ->description(fn (Article $record): ?string => empty($record->thumbnail_url)
                        ? '💡 カテゴリのデフォルト画像を表示中'
                        : null
                    )
                    // 代替画像表示中は warning 色で視覚的に区別する
                    ->color(fn (Article $record): ?string => empty($record->thumbnail_url)
                        ? 'warning'
                        : null
                    ),
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
