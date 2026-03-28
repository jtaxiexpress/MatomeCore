<?php

namespace App\Filament\Resources\AppResource\RelationManagers;

use App\Models\Category;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Resources\CategoryResource;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $title = 'カテゴリ';
    protected static ?string $modelLabel = 'カテゴリ';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('カテゴリ名')
                    ->required()
                    ->maxLength(255),

                // ─────────────────────────────────────────
                // 親カテゴリ選択フィールド
                // ・同じアプリ（app_id）に属するカテゴリのみ表示
                // ・編集時は自分自身を選択肢から除外
                // ─────────────────────────────────────────
                Forms\Components\Select::make('parent_id')
                    ->label('親カテゴリ')
                    ->nullable()
                    ->placeholder('（なし＝ルートカテゴリ）')
                    ->searchable()
                    ->options(function (RelationManager $livewire, ?Model $record): array {
                        return Category::query()
                            // 同じアプリのカテゴリのみ絞り込む
                            ->where('app_id', $livewire->getOwnerRecord()->id)
                            // 編集時は自分自身を除外（子に自分を設定できないようにする）
                            ->when(
                                $record?->id,
                                fn ($query) => $query->where('id', '!=', $record->id)
                            )
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    }),

                Forms\Components\Section::make('サブカテゴリ（子階層）')
                    ->description('この親カテゴリに属する子カテゴリを追加・並び替えできます。')
                    ->schema([
                        Forms\Components\Repeater::make('children')
                            ->relationship()
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('name')->label('カテゴリ名')->required()->maxLength(255),
                            ])
                            // 保存時に現在のアプリIDを子にも自動反映させる
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $livewire) {
                                $data['app_id'] = $livewire->getOwnerRecord()->id;
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                // 親カテゴリ名を先頭に表示（親なしの場合は「—」を表示）
                Tables\Columns\TextColumn::make('parent.name')
                    ->label('親カテゴリ')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('カテゴリ名')
                    ->searchable()
                    ->formatStateUsing(fn ($record) => $record->parent_id ? '　└ ' . $record->name : $record->name)
                    ->sortable(),

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
                    ->modalHeading('カテゴリーを追加')
                    ->modalDescription('このアプリの子カテゴリーを新規に追加します。')
                    ->modalSubmitActionLabel('追加する'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('カテゴリーの編集')
                    ->modalDescription('このアプリのカテゴリー情報を編集します。')
                    ->modalSubmitActionLabel('更新する'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNull('parent_id'))
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false);
    }
}
