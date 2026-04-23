<?php

namespace App\Filament\Resources\AppResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SiteApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = '新規サイト申請';

    protected static ?string $modelLabel = '新規サイト申請';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('サイト名')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('url')
                    ->label('サイトURL')
                    ->required()
                    ->maxLength(255)
                    ->url(),
                Forms\Components\TextInput::make('rss_url')
                    ->label('RSS URL')
                    ->maxLength(255)
                    ->url(),
                Forms\Components\TextInput::make('contact_email')
                    ->label('連絡先メールアドレス')
                    ->email()
                    ->maxLength(255),
                Forms\Components\Textarea::make('contact_notes')
                    ->label('連絡事項')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->label('クローリング有効（承認）')
                    ->inline(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)->latest())
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('サイト名')->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('contact_email')
                    ->label('連絡先')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('申請日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('承認する')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['is_active' => true])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approveBulk')
                        ->label('選択を承認')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
