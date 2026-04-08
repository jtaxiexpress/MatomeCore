<?php

namespace App\Filament\Resources;

use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource as BaseQueueMonitorResource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class QueueMonitorResource extends BaseQueueMonitorResource
{
    protected static ?string $navigationLabel = 'ジョブ監視';

    protected static ?string $modelLabel = 'ジョブ';

    protected static ?string $pluralModelLabel = '処理履歴';

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->poll('2s')
            ->headerActions([
                Action::make('clear_queue')
                    ->label('Pendingキューの全消去')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('待機中キューの削除')
                    ->modalDescription('本当に待機中のキューをすべて削除しますか？（※現在実行中のジョブは停止されません、またFailedの履歴は消えません）')
                    ->modalSubmitActionLabel('全消去する')
                    ->action(function () {
                        Artisan::call('queue:clear');
                        Notification::make()
                            ->title('待機中のキューをクリアしました')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
