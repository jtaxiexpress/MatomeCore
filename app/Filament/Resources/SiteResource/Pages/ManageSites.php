<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Models\App;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageSites extends ManageRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth('4xl')
                ->modalHeading('サイトを新規登録')
                ->modalDescription('クローラーの対象となる新しいサイト情報を入力してください。')
                ->modalSubmitActionLabel('登録する'),
        ];
    }

    /**
     * アプリごとのタブを動的に生成します。
     * 「すべて」タブは意図的に設けず、各アプリのデータのみを表示します。
     */
    public function getTabs(): array
    {
        return App::orderBy('id')
            ->get()
            ->mapWithKeys(fn (App $app): array => [
                // キーはURLのクエリ文字列に使われる識別子
                'app_' . $app->id => Tab::make($app->name)
                    ->modifyQueryUsing(
                        fn (Builder $query) => $query->where('app_id', $app->id)
                    ),
            ])
            ->all();
    }

    /**
     * 最初のアプリのタブをデフォルトで選択状態にします。
     * アプリが1件も登録されていない場合は null を返します。
     */
    public function getDefaultActiveTab(): string|int|null
    {
        $firstApp = App::orderBy('id')->first();

        return $firstApp ? 'app_' . $firstApp->id : null;
    }
}
