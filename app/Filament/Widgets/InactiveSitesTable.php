<?php

namespace App\Filament\Widgets;

use App\Models\Site;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class InactiveSitesTable extends BaseWidget
{
    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Site::query()
                    ->withMax('articles', 'created_at')
                    ->whereDoesntHave('articles', function (Builder $query) {
                        $query->where('created_at', '>=', now()->subDays(7));
                    })
            )
            ->heading('更新が止まっているサイト (7日以上未取得)')
            ->columns([
                TextColumn::make('name')
                    ->label('サイト名'),

                TextColumn::make('articles_max_created_at')
                    ->label('最終取得日')
                    ->dateTime('Y/m/d H:i')
                    ->badge()
                    ->color('danger')
                    ->default('-'),
            ]);
    }
}
