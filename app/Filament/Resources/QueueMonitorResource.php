<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\AuthorizesAdminScreenResource;
use App\Filament\Resources\QueueMonitorResource\Pages\ListPendingJobs;
use App\Filament\Resources\QueueMonitorResource\Pages\ListQueueMonitors;
use App\Support\AdminScreen;
use Croustibat\FilamentJobsMonitor\Models\QueueJob;
use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource as BaseQueueMonitorResource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class QueueMonitorResource extends BaseQueueMonitorResource
{
    use AuthorizesAdminScreenResource;

    protected static ?string $navigationLabel = 'ジョブ管理';

    protected static string|\UnitEnum|null $navigationGroup = '監視';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'ジョブ';

    protected static ?string $pluralModelLabel = '処理履歴';

    protected static function adminScreen(): ?AdminScreen
    {
        return AdminScreen::JobManagement;
    }

    public static function getNavigationLabel(): string
    {
        return 'ジョブ管理';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return '監視';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getPages(): array
    {
        $pages = parent::getPages();

        $pages['index'] = ListQueueMonitors::route('/');

        if (QueueJob::isSupported()) {
            $pages['pending'] = ListPendingJobs::route('/pending');
        } else {
            unset($pages['pending']);
        }

        return $pages;
    }

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
