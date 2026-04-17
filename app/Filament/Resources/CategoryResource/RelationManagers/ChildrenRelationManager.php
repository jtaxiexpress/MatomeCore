<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use App\Actions\ReassignArticlesAndDeleteCategoriesAction;
use App\Filament\Resources\Concerns\HandlesCategoryDeletionUi;
use App\Models\Category;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;

class ChildrenRelationManager extends RelationManager
{
    use HandlesCategoryDeletionUi;

    protected static string $relationship = 'children';

    protected static ?string $title = '子カテゴリー';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Hidden::make('app_id')
                    ->default(fn (RelationManager $livewire): int => (int) $livewire->getOwnerRecord()->app_id),
                TextInput::make('name')
                    ->label('カテゴリー名')
                    ->required()
                    ->maxLength(255),
                TextInput::make('api_slug')
                    ->label('APIスラッグ')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::slug($state) : null)
                    ->unique(
                        column: 'api_slug',
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, RelationManager $livewire): Unique {
                            return $rule->where('app_id', $livewire->getOwnerRecord()->app_id);
                        },
                    ),
                FileUpload::make('default_image_path')
                    ->label('フォールバック画像')
                    ->image()
                    ->imagePreviewHeight('80')
                    ->disk('public')
                    ->directory('category-images')
                    ->nullable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\ImageColumn::make('default_image_path')
                    ->label('デフォルト画像')
                    ->disk('public')
                    ->height(36),
                Tables\Columns\TextColumn::make('name')
                    ->label('カテゴリー名')
                    ->searchable(),
                Tables\Columns\TextColumn::make('api_slug')
                    ->label('APIスラッグ')
                    ->searchable()
                    ->badge(),
                Tables\Columns\TextColumn::make('articles_count')
                    ->label('取得記事数')
                    ->counts('articles')
                    ->badge(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('並び順')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('子カテゴリーを追加'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('子カテゴリーの編集')
                    ->modalSubmitActionLabel('更新する'),
                Tables\Actions\DeleteAction::make()
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalWidth('4xl')
                    ->modalHeading('子カテゴリーを削除')
                    ->modalDescription('紐づく記事を代替カテゴリへ移動してから削除します。')
                    ->modalSubmitActionLabel('削除する')
                    ->successNotification(null)
                    ->form(function (Category $record, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): array {
                        $deleteTargetIds = $deleteCategoriesAction->resolveDeletionCategoryIds(
                            appId: (int) $record->app_id,
                            categoryIds: [(int) $record->getKey()],
                        );
                        $articlesCount = $deleteCategoriesAction->countArticlesInCategories(
                            appId: (int) $record->app_id,
                            categoryIds: $deleteTargetIds,
                        );

                        return [
                            self::makeReplacementCategorySelect(
                                options: $deleteCategoriesAction->getReplacementCategoryOptions(
                                    appId: (int) $record->app_id,
                                    excludeCategoryIds: $deleteTargetIds,
                                ),
                                isRequired: $articlesCount > 0,
                            ),
                        ];
                    })
                    ->action(function (array $data, Category $record, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): void {
                        try {
                            $summary = $deleteCategoriesAction->execute(
                                categories: new EloquentCollection([$record]),
                                replacementCategoryId: self::resolveReplacementCategoryId($data),
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        self::notifyDeletionResult(
                            label: '子カテゴリ',
                            deletedCategoriesCount: $summary['deleted_categories_count'],
                            movedArticlesCount: $summary['moved_articles_count'],
                            includeDeletedCount: false,
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('選択した子カテゴリを削除')
                        ->requiresConfirmation()
                        ->modalWidth('4xl')
                        ->modalHeading('子カテゴリを一括削除')
                        ->modalDescription('紐づく記事を代替カテゴリへ移動してから削除します。')
                        ->modalSubmitActionLabel('削除する')
                        ->successNotification(null)
                        ->form(function (EloquentCollection $records, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): array {
                            $selectedCategoryIds = $records
                                ->pluck('id')
                                ->map(static fn (mixed $id): int => (int) $id)
                                ->values()
                                ->all();

                            $deleteTargetIds = $deleteCategoriesAction->resolveDeletionCategoryIds(
                                appId: (int) $this->getOwnerRecord()->app_id,
                                categoryIds: $selectedCategoryIds,
                            );
                            $articlesCount = $deleteCategoriesAction->countArticlesInCategories(
                                appId: (int) $this->getOwnerRecord()->app_id,
                                categoryIds: $deleteTargetIds,
                            );

                            return [
                                self::makeReplacementCategorySelect(
                                    options: $deleteCategoriesAction->getReplacementCategoryOptions(
                                        appId: (int) $this->getOwnerRecord()->app_id,
                                        excludeCategoryIds: $deleteTargetIds,
                                    ),
                                    isRequired: $articlesCount > 0,
                                ),
                            ];
                        })
                        ->action(function (EloquentCollection $records, array $data, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): void {
                            try {
                                $summary = $deleteCategoriesAction->execute(
                                    categories: $records,
                                    replacementCategoryId: self::resolveReplacementCategoryId($data),
                                );
                            } catch (InvalidArgumentException $exception) {
                                Notification::make()
                                    ->title($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            self::notifyDeletionResult(
                                label: '子カテゴリ',
                                deletedCategoriesCount: $summary['deleted_categories_count'],
                                movedArticlesCount: $summary['moved_articles_count'],
                                includeDeletedCount: true,
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false);
    }
}
