<?php

namespace App\Filament\Widgets;

use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class InactiveSitesTable extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        $query = Site::query();

        if ($tenant) {
            $query->whereBelongsTo($tenant, 'app');
        } else {
            $query->whereRaw('1 = 0');
        }

        return $table
            ->query(
                $query
                    ->withMax('articles', 'created_at')
                    ->whereDoesntHave('articles', function (Builder $query) {
                        $query->where('created_at', '>=', now()->subDays(7));
                    })
            )
            ->heading('更新が止まっているサイト (7日以上未取得)')
            ->emptyStateHeading('すべて正常に稼働しています')
            ->emptyStateDescription('更新が停止しているサイトはありません。')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->defaultSort('articles_max_created_at', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label('サイト名'),

                TextColumn::make('articles_max_created_at')
                    ->label('最終取得日')
                    ->dateTime('Y/m/d H:i')
                    ->badge()
                    ->color('danger')
                    ->default('-')
                    ->sortable(),
            ]);
    }
}
