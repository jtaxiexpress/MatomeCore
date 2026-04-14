<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\UnitEnum|null $navigationGroup = 'マスター管理';

    protected static ?int $navigationSort = 2;

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
                            ->relationship()
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
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
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
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('parent_id')->withMax('articles', 'created_at'))
            ->reorderable('sort_order')
            ->defaultSort('created_at', 'desc')
            ->paginated(false);

    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
