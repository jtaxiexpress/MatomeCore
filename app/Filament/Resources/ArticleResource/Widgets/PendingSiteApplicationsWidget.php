<?php

namespace App\Filament\Resources\ArticleResource\Widgets;

use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingSiteApplicationsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Site::query()
                    ->where('is_active', false)
                    ->where('app_id', Filament::getTenant()?->id)
                    ->latest()
            )
            ->heading('新規サイト申請一覧 (未承認)')
            ->description('当アプリへの相互リンク申請です。内容を確認し、問題なければ「承認」してください。')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('サイト名'),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->limit(30),
                Tables\Columns\TextColumn::make('contact_email')
                    ->label('連絡先'),
                Tables\Columns\TextColumn::make('contact_notes')
                    ->label('連絡事項')
                    ->limit(30),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('申請日時')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('承認する')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Site $record) => $record->update(['is_active' => true])),
            ]);
    }

    public static function canView(): bool
    {
        // Only show the widget if there are pending applications for this app.
        return Site::query()
            ->where('is_active', false)
            ->where('app_id', Filament::getTenant()?->id)
            ->exists();
    }
}
