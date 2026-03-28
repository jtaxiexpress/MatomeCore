<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppResource\Pages;
use App\Filament\Resources\AppResource\RelationManagers;
use App\Models\App;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AppResource extends Resource
{
    protected static ?string $model = App::class;
    protected static string|\UnitEnum|null $navigationGroup = 'マスター管理';
    protected static ?int $navigationSort = 1;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('アプリ基本情報')
                    ->description('管理するまとめアプリのメイン設定です。')
                    ->schema([
                        TextInput::make('name')
                            ->label('アプリ名')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('theme_color')
                            ->label('テーマカラー (Hex)')
                            ->placeholder('#000000')
                            ->maxLength(255),
                    ]),
                Section::make('ステータス')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('有効ステータス')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('AI設定（アプリ別オーバーライド）')
                    ->description('未入力の場合はシステム全体のデフォルト設定が使用されます。')
                    ->collapsed()
                    ->schema([
                        TextInput::make('ollama_model')
                            ->label('Ollamaモデル名')
                            ->placeholder('例: qwen3.5:9b')
                            ->helperText('※未入力の場合はシステム全体のデフォルト設定が使用されます。')
                            ->maxLength(255),
                        TextInput::make('gemini_model')
                            ->label('Geminiモデル名')
                            ->placeholder('例: gemini-1.5-flash-lite')
                            ->helperText('※未入力の場合はシステム全体のデフォルト設定が使用されます。')
                            ->maxLength(255),
                        Textarea::make('ai_prompt_template')
                            ->label('AIプロンプトテンプレート')
                            ->helperText('※未入力の場合はシステム全体のデフォルト設定が使用されます。{categories}と{title}のプレースホルダーを必ず含めてください。')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
                Section::make('スケジュール設定')
                    ->description('このアプリの記事自動取得スケジュールを設定します。')
                    ->collapsed()
                    ->schema([
                        Select::make('fetch_frequency')
                            ->label('取得頻度')
                            ->options([
                                '1_hour'  => '1時間に1回',
                                '3_hours' => '3時間に1回（デフォルト）',
                                '12_hours' => '12時間に1回',
                                'daily'   => '1日1回（時間を指定）',
                            ])
                            ->default('3_hours')
                            ->required()
                            ->live(),
                        TimePicker::make('fetch_time')
                            ->label('実行時刻')
                            ->helperText('「1日1回」選択時のみ有効です。')
                            ->seconds(false)
                            ->visible(fn ($get) => $get('fetch_frequency') === 'daily'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('アプリ名')->searchable(),
                ColorColumn::make('theme_color')->label('テーマカラー')->searchable(),
                \Filament\Tables\Columns\ToggleColumn::make('is_active')->label('ステータス'),
                TextColumn::make('created_at')->label('作成日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新日')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('アプリ情報の編集')
                    ->modalDescription('アプリ名やステータスを変更します。')
                    ->modalSubmitActionLabel('更新する'),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CategoriesRelationManager::class,
            RelationManagers\SitesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageApps::route('/'),
        ];
    }
}
