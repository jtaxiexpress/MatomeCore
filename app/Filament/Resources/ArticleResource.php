<?php

namespace App\Filament\Resources;

use App\Actions\ReprocessSelectedArticlesAction;
use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Models\Category;
use App\Notifications\FilamentDatabaseNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|\UnitEnum|null $navigationGroup = 'コンテンツ管理';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = '記事管理';

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
                                modifyQueryUsing: fn (Builder $query): Builder => self::scopeCategoryQueryToTenant($query),
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
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('記事の編集')
                    ->modalDescription('記事の内容やメタデータを変更します。')
                    ->modalSubmitActionLabel('更新する'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('reprocessSelectedArticles')
                        ->label('AIで再処理（タイトル+カテゴリ）')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalWidth('4xl')
                        ->modalHeading('選択した記事のタイトルとカテゴリをAIで再処理しますか？')
                        ->modalDescription('選択した記事をまとめてAIで再分類し、タイトルとカテゴリの両方を更新します。')
                        ->modalSubmitActionLabel('実行する')
                        ->action(function (Collection $records, ReprocessSelectedArticlesAction $reprocessSelectedArticlesAction): void {
                            $updatedCount = $reprocessSelectedArticlesAction->executeCombined($records);

                            if ($updatedCount === 0) {
                                Notification::make()
                                    ->title('AIで再処理できる記事がありませんでした')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title($updatedCount.'件の記事のタイトルとカテゴリを再処理しました')
                                ->success()
                                ->send();

                            self::sendAppReprocessNotification(
                                title: 'AI再処理が完了しました',
                                body: $updatedCount.'件の記事のタイトルとカテゴリを再処理しました',
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('rewriteSelectedArticleTitles')
                        ->label('AIでタイトルのみ再リライト')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalWidth('4xl')
                        ->modalHeading('選択した記事のタイトルのみをAIで再リライトしますか？')
                        ->modalDescription('選択した記事のカテゴリはそのままにして、タイトルだけをAIで整えます。')
                        ->modalSubmitActionLabel('実行する')
                        ->action(function (Collection $records, ReprocessSelectedArticlesAction $reprocessSelectedArticlesAction): void {
                            $updatedCount = $reprocessSelectedArticlesAction->executeTitleOnly($records);

                            if ($updatedCount === 0) {
                                Notification::make()
                                    ->title('AIで再リライトできる記事がありませんでした')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title($updatedCount.'件の記事のタイトルを再リライトしました')
                                ->success()
                                ->send();

                            self::sendAppReprocessNotification(
                                title: 'AIタイトル再リライトが完了しました',
                                body: $updatedCount.'件の記事のタイトルを再リライトしました',
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('reclassifySelectedArticleCategories')
                        ->label('AIでカテゴリのみ再振り分け')
                        ->icon('heroicon-o-tag')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalWidth('4xl')
                        ->modalHeading('選択した記事のカテゴリのみをAIで再振り分けしますか？')
                        ->modalDescription('選択した記事のタイトルはそのままにして、カテゴリだけをAIで見直します。')
                        ->modalSubmitActionLabel('実行する')
                        ->action(function (Collection $records, ReprocessSelectedArticlesAction $reprocessSelectedArticlesAction): void {
                            $updatedCount = $reprocessSelectedArticlesAction->executeCategoryOnly($records);

                            if ($updatedCount === 0) {
                                Notification::make()
                                    ->title('AIで再振り分けできる記事がありませんでした')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title($updatedCount.'件の記事のカテゴリを再振り分けしました')
                                ->success()
                                ->send();

                            self::sendAppReprocessNotification(
                                title: 'AIカテゴリ再振り分けが完了しました',
                                body: $updatedCount.'件の記事のカテゴリを再振り分けしました',
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('changeCategory')
                        ->label('カテゴリを一括変更')
                        ->icon('heroicon-o-arrows-right-left')
                        ->requiresConfirmation()
                        ->modalWidth('4xl')
                        ->modalHeading('選択した記事のカテゴリを変更')
                        ->modalDescription('選択した記事をまとめて別のカテゴリへ移動します。')
                        ->form([
                            Select::make('new_category_id')
                                ->label('移動先カテゴリ')
                                ->options(fn (): array => self::getTenantCategoryOptions())
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->toQuery()->update([
                                'category_id' => (int) $data['new_category_id'],
                            ]);

                            Notification::make()
                                ->title($records->count().'件の記事のカテゴリを変更しました')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('published_at', 'desc');
    }

    /**
     * @return array<int, string>
     */
    private static function getTenantCategoryOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return [];
        }

        return Category::query()
            ->whereBelongsTo($tenant, 'app')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    private static function scopeCategoryQueryToTenant(Builder $query): Builder
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereBelongsTo($tenant, 'app')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    private static function sendAppReprocessNotification(string $title, string $body): void
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant || ! $user) {
            return;
        }

        $payload = Notification::make()
            ->title($title)
            ->success()
            ->body($body)
            ->actions([
                Action::make('markAsRead')
                    ->label('既読にする')
                    ->button()
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();

        $payload['app_id'] = $tenant->getKey();

        $user->notify(new FilamentDatabaseNotification($payload));
        DatabaseNotificationsSent::dispatch($user);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageArticles::route('/'),
        ];
    }
}
