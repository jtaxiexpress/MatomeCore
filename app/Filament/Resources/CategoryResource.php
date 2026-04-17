<?php

namespace App\Filament\Resources;

use App\Actions\ReassignArticlesAndDeleteCategoriesAction;
use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Filament\Resources\Concerns\HandlesCategoryDeletionUi;
use App\Models\Category;
use Carbon\Carbon;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;

class CategoryResource extends Resource
{
    use HandlesCategoryDeletionUi;

    protected static ?string $model = Category::class;

    protected static string|\UnitEnum|null $navigationGroup = 'コンテンツ管理';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'カテゴリー管理';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('カテゴリ設定')
                    ->schema([
                        TextInput::make('name')->label('カテゴリ名')->required()->maxLength(255),
                        TextInput::make('api_slug')
                            ->label('APIスラッグ')
                            ->helperText('公開API URLに使用する識別子です。')
                            ->required()
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::slug($state) : null)
                            ->unique(
                                column: 'api_slug',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule): Unique {
                                    $tenant = Filament::getTenant();

                                    if (! $tenant) {
                                        return $rule;
                                    }

                                    return $rule->where('app_id', $tenant->getKey());
                                },
                            ),
                        Select::make('parent_id')
                            ->label('親カテゴリ')
                            ->nullable()
                            ->placeholder('（なし＝ルートカテゴリ）')
                            ->searchable()
                            ->options(function (?Category $record): array {
                                $tenant = Filament::getTenant();

                                if (! $tenant) {
                                    return [];
                                }

                                return Category::query()
                                    ->where('app_id', $tenant->getKey())
                                    ->when(
                                        $record?->id,
                                        fn ($q) => $q->where('id', '!=', $record->id)
                                    )
                                    ->orderBy('sort_order')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }),
                    ]),
                Section::make('デフォルト画像')
                    ->description('記事サムネイルが取得できなかった場合に表示するカテゴリ代替画像')
                    ->schema([
                        FileUpload::make('default_image_path')
                            ->label('フォールバック画像')
                            ->image()
                            ->imagePreviewHeight('120')
                            ->disk('public')
                            ->directory('category-images')
                            ->nullable(),
                    ]),
                Section::make('サブカテゴリ（子階層）')
                    ->description('この親カテゴリに属する子カテゴリを追加・並び替えできます。')
                    ->schema([
                        Repeater::make('children')
                            ->relationship(
                                modifyQueryUsing: fn (Builder $query): Builder => $query->withCount('articles'),
                            )
                            ->label('')
                            ->schema([
                                TextInput::make('name')->label('カテゴリ名')->required()->maxLength(255),
                                TextInput::make('api_slug')
                                    ->label('APIスラッグ')
                                    ->required()
                                    ->maxLength(255)
                                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::slug($state) : null),
                                FileUpload::make('default_image_path')
                                    ->label('フォールバック画像')
                                    ->image()
                                    ->imagePreviewHeight('80')
                                    ->disk('public')
                                    ->directory('category-images')
                                    ->nullable(),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $tenant = Filament::getTenant();

                                if ($tenant) {
                                    $data['app_id'] = $tenant->getKey();
                                }

                                return $data;
                            })
                            ->orderColumn('sort_order')
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?HtmlString => self::buildChildItemLabel($state))
                            ->deleteAction(fn (FilamentAction $action): FilamentAction => self::configureChildDeleteAction($action))
                            ->addActionLabel('子カテゴリを追加'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('カテゴリ名')
                    ->searchable(),
                TextColumn::make('api_slug')
                    ->label('APIスラッグ')
                    ->searchable()
                    ->badge(),
                TextColumn::make('articles_count')
                    ->counts('articles')
                    ->label('取得記事数')
                    ->badge()
                    ->sortable(),
                TextColumn::make('articles_max_created_at')
                    ->label('最終取得日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'danger',
                        Carbon::parse($state) >= now()->subDays(3) => 'success',
                        Carbon::parse($state) >= now()->subDays(7) => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('children_count')
                    ->counts('children')
                    ->label('子カテゴリ数')
                    ->badge(),
                ImageColumn::make('default_image_path')
                    ->label('デフォルト画像')
                    ->disk('public')
                    ->height(40),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('parent.name')
                    ->label('親カテゴリ')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort_order')
                    ->label('並び順')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('更新日')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('カテゴリー情報の編集')
                    ->modalDescription('カテゴリー情報を変更します。')
                    ->modalSubmitActionLabel('更新する'),
                DeleteAction::make()
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalWidth('4xl')
                    ->modalHeading('カテゴリーを削除')
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
                            label: 'カテゴリ',
                            deletedCategoriesCount: $summary['deleted_categories_count'],
                            movedArticlesCount: $summary['moved_articles_count'],
                            includeDeletedCount: false,
                        );
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('選択したカテゴリを削除')
                        ->requiresConfirmation()
                        ->modalWidth('4xl')
                        ->modalHeading('カテゴリを一括削除')
                        ->modalDescription('紐づく記事を代替カテゴリへ移動してから削除します。')
                        ->modalSubmitActionLabel('削除する')
                        ->successNotification(null)
                        ->form(function (EloquentCollection $records, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): array {
                            $firstRecord = $records->first();

                            if (! $firstRecord instanceof Category) {
                                return [
                                    self::makeReplacementCategorySelect(options: [], isRequired: false),
                                ];
                            }

                            $selectedCategoryIds = $records
                                ->pluck('id')
                                ->map(static fn (mixed $id): int => (int) $id)
                                ->values()
                                ->all();

                            $deleteTargetIds = $deleteCategoriesAction->resolveDeletionCategoryIds(
                                appId: (int) $firstRecord->app_id,
                                categoryIds: $selectedCategoryIds,
                            );
                            $articlesCount = $deleteCategoriesAction->countArticlesInCategories(
                                appId: (int) $firstRecord->app_id,
                                categoryIds: $deleteTargetIds,
                            );

                            return [
                                self::makeReplacementCategorySelect(
                                    options: $deleteCategoriesAction->getReplacementCategoryOptions(
                                        appId: (int) $firstRecord->app_id,
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
                                label: 'カテゴリ',
                                deletedCategoriesCount: $summary['deleted_categories_count'],
                                movedArticlesCount: $summary['moved_articles_count'],
                                includeDeletedCount: true,
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('parent_id')->withMax('articles', 'created_at'))
            ->reorderable('sort_order')
            ->defaultSort('created_at', 'desc')
            ->paginated(false);

    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChildrenRelationManager::class,
        ];
    }

    private static function configureChildDeleteAction(FilamentAction $action): FilamentAction
    {
        return $action
            ->requiresConfirmation()
            ->modalWidth('4xl')
            ->modalHeading('子カテゴリを削除')
            ->modalDescription('紐づく記事がある場合は代替カテゴリへ移動してから削除します。')
            ->modalSubmitActionLabel('削除する')
            ->form(function (array $arguments, Repeater $component, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): array {
                $targetCategory = self::resolveRepeaterChildCategory($component, $arguments);

                if (! $targetCategory instanceof Category) {
                    return [];
                }

                $deleteTargetIds = $deleteCategoriesAction->resolveDeletionCategoryIds(
                    appId: (int) $targetCategory->app_id,
                    categoryIds: [(int) $targetCategory->getKey()],
                );
                $articlesCount = $deleteCategoriesAction->countArticlesInCategories(
                    appId: (int) $targetCategory->app_id,
                    categoryIds: $deleteTargetIds,
                );

                return [
                    self::makeReplacementCategorySelect(
                        options: $deleteCategoriesAction->getReplacementCategoryOptions(
                            appId: (int) $targetCategory->app_id,
                            excludeCategoryIds: $deleteTargetIds,
                        ),
                        isRequired: $articlesCount > 0,
                    ),
                ];
            })
            ->action(function (array $arguments, array $data, Repeater $component, ReassignArticlesAndDeleteCategoriesAction $deleteCategoriesAction): void {
                $targetCategory = self::resolveRepeaterChildCategory($component, $arguments);

                if ($targetCategory instanceof Category) {
                    try {
                        $summary = $deleteCategoriesAction->execute(
                            categories: new EloquentCollection([$targetCategory]),
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
                }

                self::removeRepeaterItemFromState($component, $arguments);
            });
    }

    private static function resolveRepeaterChildCategory(Repeater $component, array $arguments): ?Category
    {
        $categoryId = self::resolveRepeaterChildCategoryId($component, $arguments);

        if (! $categoryId) {
            return null;
        }

        $ownerRecord = $component->getRecord();

        if (! $ownerRecord instanceof Category) {
            return Category::query()->find($categoryId);
        }

        return Category::query()
            ->where('app_id', (int) $ownerRecord->app_id)
            ->whereKey($categoryId)
            ->first();
    }

    private static function resolveRepeaterChildCategoryId(Repeater $component, array $arguments): ?int
    {
        $itemKey = $arguments['item'] ?? null;

        if (! is_string($itemKey) || $itemKey === '') {
            return null;
        }

        $itemState = ($component->getRawState() ?? [])[$itemKey] ?? null;

        if (is_array($itemState) && filled($itemState['id'] ?? null)) {
            return (int) $itemState['id'];
        }

        if (str_starts_with($itemKey, 'record-')) {
            $recordId = (int) Str::after($itemKey, 'record-');

            return $recordId > 0 ? $recordId : null;
        }

        return null;
    }

    private static function removeRepeaterItemFromState(Repeater $component, array $arguments): void
    {
        $itemKey = $arguments['item'] ?? null;

        if (! is_string($itemKey) || $itemKey === '') {
            return;
        }

        $items = $component->getRawState();

        if (! is_array($items)) {
            $items = [];
        }

        unset($items[$itemKey]);

        $component->rawState($items);
        $component->callAfterStateUpdated();
        $component->shouldPartiallyRenderAfterActionsCalled() ? $component->partiallyRender() : null;
    }

    private static function buildChildItemLabel(array $state): ?HtmlString
    {
        $name = trim((string) ($state['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $articlesCount = (int) ($state['articles_count'] ?? 0);

        return new HtmlString(
            e($name).' <span class="fi-badge fi-size-xs fi-color-primary"><span class="fi-badge-label-ctn"><span class="fi-badge-label">'.$articlesCount.'件</span></span></span>'
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
