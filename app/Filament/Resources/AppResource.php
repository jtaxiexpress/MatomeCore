<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppResource\Pages;
use App\Filament\Resources\AppResource\RelationManagers;
use App\Filament\Resources\Concerns\AuthorizesAdminScreenResource;
use App\Models\App;
use App\Support\AdminScreen;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AppResource extends Resource
{
    use AuthorizesAdminScreenResource;

    protected static ?string $model = App::class;

    protected static string|\UnitEnum|null $navigationGroup = 'プラットフォーム管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'アプリ管理';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::AppManagement;
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                self::getGeneralDetailsSection(),
                self::getAppearanceSection(),
                self::getStatusSection(),
                self::getScheduleSection(),
                self::getAiSettingsSection(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::getTableColumns())
            ->filters([])
            ->actions(self::getTableActions())
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->recordUrl(fn (App $record): ?string => Filament::getPanel('app')->getUrl($record))
            ->modifyQueryUsing(fn (Builder $query) => $query->withMax('articles', 'created_at'))
            ->defaultSort('created_at', 'desc');
    }

    private static function getGeneralDetailsSection(): Section
    {
        return Section::make('アプリ基本情報')
            ->description('運用画面・公開APIで使う識別情報を設定します。')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('アプリ名')
                    ->helperText('一覧やAPIレスポンスで利用される名称です。')
                    ->required()
                    ->maxLength(255),
                TextInput::make('api_slug')
                    ->label('APIスラッグ')
                    ->helperText('公開API URLに使用する識別子です。半角英数とハイフンを推奨します。')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::slug($state) : null)
                    ->unique(ignoreRecord: true),
            ])
            ->extraAttributes(self::getPrimaryCardClasses());
    }

    private static function getAppearanceSection(): Section
    {
        return Section::make('外観設定')
            ->description('アプリ一覧に表示するアイコンとテーマカラーを選択します。')
            ->columns(2)
            ->schema([
                FileUpload::make('icon_path')
                    ->label('アプリアイコン')
                    ->helperText('SVG / PNG / JPG / WEBP を推奨します。編集画面上部でプレビューされます。')
                    ->image()
                    ->imagePreviewHeight('96')
                    ->acceptedFileTypes([
                        'image/svg+xml',
                        'image/png',
                        'image/jpeg',
                        'image/webp',
                    ])
                    ->disk('public')
                    ->directory('app-icons')
                    ->visibility('public')
                    ->nullable(),
                Select::make('theme_color')
                    ->label('テーマカラー (Hex)')
                    ->helperText('Apple HIGを意識した、落ち着いたプリセットカラーから選択します。')
                    ->placeholder('プリセットから選択')
                    ->options(App::themeColorOptions())
                    ->native(false)
                    ->searchable()
                    ->nullable(),
            ])
            ->extraAttributes(self::getPrimaryCardClasses());
    }

    private static function getStatusSection(): Section
    {
        return Section::make('公開設定')
            ->description('公開/停止の切り替えをここで行います。')
            ->secondary()
            ->compact()
            ->schema([
                Toggle::make('is_active')
                    ->label('有効ステータス')
                    ->helperText('オフにすると公開APIのアプリ一覧から除外されます。')
                    ->default(true)
                    ->inline(false),
            ])
            ->extraAttributes(self::getSidebarCardClasses());
    }

    private static function getAiSettingsSection(): Section
    {
        return Section::make('AI設定（アプリ別オーバーライド）')
            ->description('未入力の場合はシステム全体のデフォルト設定が使用されます。')
            ->collapsed()
            ->schema([
                Textarea::make('ai_prompt_template')
                    ->label('アプリ固有のリライトルール（差分・テイスト）')
                    ->helperText('（任意）システム共通プロンプトに追加して指示したい、このアプリ特有のルール（例: 2ch風の煽りタイトルにして、ミリタリー系なので少し固い表現にして等）のみを入力してください。※{categories}などの変数の記述や、JSON出力の指示は不要です。')
                    ->rows(8)
                    ->columnSpanFull(),
                Repeater::make('custom_scrape_rules')
                    ->label('ドメイン別スクレイプルール')
                    ->helperText('対象ドメインごとに、記事一覧ブロックとリンクのCSSセレクタを設定します。')
                    ->schema([
                        TextInput::make('domain')
                            ->label('対象ドメイン')
                            ->placeholder('example.com')
                            ->required()
                            ->maxLength(255)
                            ->regex('/^[a-z0-9.-]+$/')
                            ->distinct()
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtolower(trim($state)) : null),
                        TextInput::make('list_item_selector')
                            ->label('記事ブロックセレクタ')
                            ->placeholder('article, .post, .entry')
                            ->required()
                            ->maxLength(500),
                        TextInput::make('link_selector')
                            ->label('リンクセレクタ')
                            ->placeholder('a')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->columns(3)
                    ->default([])
                    ->collapsed()
                    ->collapsible()
                    ->reorderable(false)
                    ->columnSpanFull()
                    ->addActionLabel('ルールを追加'),
            ])
            ->extraAttributes(self::getPrimaryCardClasses());
    }

    private static function getScheduleSection(): Section
    {
        return Section::make('スケジュール設定')
            ->description('このアプリの記事自動取得スケジュールを設定します。')
            ->collapsed()
            ->columns(2)
            ->schema([
                Select::make('fetch_frequency')
                    ->label('取得頻度')
                    ->options([
                        '1_hour' => '1時間に1回',
                        '3_hours' => '3時間に1回（デフォルト）',
                        '12_hours' => '12時間に1回',
                        'daily' => '1日1回（時間を指定）',
                    ])
                    ->default('3_hours')
                    ->required()
                    ->live(),
                TimePicker::make('fetch_time')
                    ->label('実行時刻')
                    ->helperText('「1日1回」選択時のみ有効です。')
                    ->seconds(false)
                    ->visible(fn ($get) => $get('fetch_frequency') === 'daily'),
            ])
            ->extraAttributes(self::getPrimaryCardClasses());
    }

    /**
     * @return array<int, ImageColumn|TextColumn|ColorColumn>
     */
    private static function getTableColumns(): array
    {
        return [
            ImageColumn::make('icon_path')
                ->label('アイコン')
                ->disk('public')
                ->circular()
                ->height(36),
            TextColumn::make('name')->label('アプリ名')->searchable(),
            TextColumn::make('api_slug')->label('APIスラッグ')->searchable()->badge(),
            TextColumn::make('sites_count')->counts('sites')->label('登録サイト数')->badge(),
            TextColumn::make('articles_count')->counts('articles')->label('取得記事数')->badge()->sortable(),
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
            ColorColumn::make('theme_color')->label('テーマカラー')->searchable(),
        ];
    }

    /**
     * @return array<int, EditAction>
     */
    private static function getTableActions(): array
    {
        return [
            EditAction::make()
                ->modalWidth('5xl')
                ->modalHeading('アプリ情報の編集')
                ->modalDescription('アプリ名・外観・運用設定を更新します。')
                ->modalSubmitActionLabel('更新する'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getPrimaryCardClasses(): array
    {
        return [
            'class' => 'rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-700',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getSidebarCardClasses(): array
    {
        return [
            'class' => 'rounded-xl bg-gray-50 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900/70 dark:ring-gray-700',
        ];
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
