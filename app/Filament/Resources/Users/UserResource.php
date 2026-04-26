<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Concerns\AuthorizesAdminScreenResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\AdminScreen;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    use AuthorizesAdminScreenResource;

    protected static ?string $model = User::class;

    protected static string|\UnitEnum|null $navigationGroup = 'システム設定';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'ユーザー管理';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::UserManagement;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ユーザー名')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('メールアドレス')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('パスワード')
                    ->password()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->maxLength(255)
                    ->helperText('編集時に空欄の場合はパスワードを変更しません。'),
                Toggle::make('is_admin')
                    ->label('システム管理者（全アプリアクセス）')
                    ->default(false)
                    ->live()
                    ->inline(false),
                Select::make('admin_screen_permissions')
                    ->label('アクセス可能画面')
                    ->options(AdminScreen::selectableOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => (bool) $get('is_admin'))
                    ->dehydratedWhenHidden()
                    ->helperText('ダッシュボードとログイン画面は権限不要です。system admin のときだけ、必要な画面を選択してください。')
                    ->columnSpanFull(),
                Select::make('apps')
                    ->label('アクセス可能アプリ')
                    ->relationship('apps', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('管理者でないユーザーに対して有効です。管理者は全アプリへアクセスできます。')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('ユーザー名')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('メールアドレス')
                    ->searchable(),
                IconColumn::make('is_admin')
                    ->label('管理者')
                    ->boolean(),
                TextColumn::make('apps_count')
                    ->label('所属アプリ数')
                    ->counts('apps')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('4xl')
                    ->modalHeading('ユーザー情報の編集')
                    ->modalSubmitActionLabel('更新する'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
