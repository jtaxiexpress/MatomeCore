<?php

declare(strict_types=1);

namespace App\Filament\Resources\Concerns;

use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

trait HandlesCategoryDeletionUi
{
    /**
     * @param  array<int, string>  $options
     */
    private static function makeReplacementCategorySelect(array $options, bool $isRequired): Select
    {
        return Select::make('replacement_category_id')
            ->label('代替カテゴリ')
            ->helperText($isRequired ? '削除対象に記事があるため、移動先を選択してください。' : '削除対象に記事がないため、選択は不要です。')
            ->options($options)
            ->searchable()
            ->required($isRequired);
    }

    private static function resolveReplacementCategoryId(array $data): ?int
    {
        $replacementCategoryId = $data['replacement_category_id'] ?? null;

        if (! filled($replacementCategoryId)) {
            return null;
        }

        return (int) $replacementCategoryId;
    }

    private static function notifyDeletionResult(
        string $label,
        int $deletedCategoriesCount,
        int $movedArticlesCount,
        bool $includeDeletedCount = false,
    ): void {
        if (! $includeDeletedCount) {
            $title = $movedArticlesCount > 0
                ? $label.'を削除しました（'.$movedArticlesCount.'件の記事を移動）'
                : $label.'を削除しました';

            Notification::make()
                ->title($title)
                ->success()
                ->send();

            return;
        }

        $title = $movedArticlesCount > 0
            ? $deletedCategoriesCount.'件の'.$label.'を削除し、'.$movedArticlesCount.'件の記事を移動しました'
            : $deletedCategoriesCount.'件の'.$label.'を削除しました';

        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }
}
