<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\App;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth('4xl')
                ->modalHeading('カテゴリーを新規作成')
                ->modalDescription('アプリに関連付ける新しいカテゴリーを作成します。')
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
