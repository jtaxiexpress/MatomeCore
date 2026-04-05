<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use App\Models\App;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

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
                    ]),
                Section::make('所属アプリ')
                    ->schema([
                        Select::make('app_id')
                            ->label('関連アプリ')
                            ->relationship('app', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            // app_id が変わったら parent_id をリセット
                            ->afterStateUpdated(fn ($set) => $set('parent_id', null))
                            ->live(),

                        // ─────────────────────────────────────────────
                        // 親カテゴリ選択（同じアプリのカテゴリのみ表示）
                        // ・app_id 未選択の場合は選択肢を空に
                        // ・編集時は自分自身を除外
                        // ─────────────────────────────────────────────
                        Select::make('parent_id')
                            ->label('親カテゴリ')
                            ->nullable()
                            ->placeholder('（なし＝ルートカテゴリ）')
                            ->searchable()
                            ->options(function ($get, ?Category $record): array {
                                $appId = $get('app_id');
                                if (! $appId) {
                                    return [];
                                }

                                return Category::query()
                                    ->where('app_id', $appId)
                                    // 編集時は自分自身を選択肢から除外
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
                        \Filament\Forms\Components\Repeater::make('children')
                            ->relationship()
                            ->label('')
                            ->schema([
                                TextInput::make('name')->label('カテゴリ名')->required()->maxLength(255),
                                FileUpload::make('default_image_path')
                                    ->label('フォールバック画像')
                                    ->image()
                                    ->imagePreviewHeight('80')
                                    ->disk('public')
                                    ->directory('category-images')
                                    ->nullable(),
                            ])
                            // 保存時に現在のアプリIDを子にも自動反映させる
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $livewire) {
                                // 親（このカテゴリ自身）が属するアプリIDを子にも設定
                                $data['app_id'] = $livewire->data['app_id'] ?? 1;
                                return $data;
                            })
                            ->orderColumn('sort_order') // これによりドラッグ＆ドロップで子の並び順が保存されます
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
                        \Carbon\Carbon::parse($state) >= now()->subDays(3) => 'success',
                        \Carbon\Carbon::parse($state) >= now()->subDays(7) => 'warning',
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
                TextColumn::make('app.name')
                    ->label('アプリ')
                    ->sortable()
                    ->badge()
                    ->color('info')
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
                    ->modalDescription('カテゴリー名や関連アプリを変更します。')
                    ->modalSubmitActionLabel('更新する'),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNull('parent_id')->withMax('articles', 'created_at'))
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false);

    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCategories::route('/'),
        ];
    }
}
