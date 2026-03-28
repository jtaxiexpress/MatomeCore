<?php

namespace App\Filament\Resources;

use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource as BaseQueueMonitorResource;
use Filament\Tables\Table;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;

class QueueMonitorResource extends BaseQueueMonitorResource
{
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
                    })
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
