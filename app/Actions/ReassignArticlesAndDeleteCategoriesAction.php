<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReassignArticlesAndDeleteCategoriesAction
{
    /**
     * @param  EloquentCollection<int, Category>  $categories
     * @return array{moved_articles_count:int, deleted_categories_count:int, affected_categories_count:int, had_articles:bool}
     */
    public function execute(EloquentCollection $categories, ?int $replacementCategoryId = null): array
    {
        /** @var EloquentCollection<int, Category> $selectedCategories */
        $selectedCategories = $categories
            ->filter(fn (mixed $category): bool => $category instanceof Category)
            ->unique(fn (Category $category): int => (int) $category->getKey())
            ->values();

        if ($selectedCategories->isEmpty()) {
            return [
                'moved_articles_count' => 0,
                'deleted_categories_count' => 0,
                'affected_categories_count' => 0,
            ];
        }

        $appIds = $selectedCategories
            ->pluck('app_id')
            ->filter()
            ->unique()
            ->values();

        if ($appIds->count() !== 1) {
            throw new InvalidArgumentException('削除対象カテゴリの所属アプリが一致していません。');
        }

        $appId = (int) $appIds->first();
        $selectedCategoryIds = $selectedCategories
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $categoryIdsToDelete = $this->resolveDeletionCategoryIds($appId, $selectedCategoryIds);
        $articlesCount = $this->countArticlesInCategories($appId, $categoryIdsToDelete);
        $shouldReassignArticles = $articlesCount > 0;

        if ($shouldReassignArticles) {
            if ($replacementCategoryId === null) {
                throw new InvalidArgumentException('削除対象に記事があるため、代替カテゴリを選択してください。');
            }

            if (in_array($replacementCategoryId, $categoryIdsToDelete, true)) {
                throw new InvalidArgumentException('移動先カテゴリに削除対象のカテゴリは選択できません。');
            }

            $replacementCategoryExists = Category::query()
                ->where('app_id', $appId)
                ->whereKey($replacementCategoryId)
                ->exists();

            if (! $replacementCategoryExists) {
                throw new InvalidArgumentException('移動先カテゴリが見つかりません。');
            }
        }

        $movedArticlesCount = 0;
        $deletedCategoriesCount = 0;

        DB::transaction(function () use (
            $appId,
            $shouldReassignArticles,
            $replacementCategoryId,
            $selectedCategoryIds,
            $categoryIdsToDelete,
            &$movedArticlesCount,
            &$deletedCategoriesCount,
        ): void {
            if ($shouldReassignArticles) {
                $movedArticlesCount = Article::query()
                    ->where('app_id', $appId)
                    ->whereIn('category_id', $categoryIdsToDelete)
                    ->update([
                        'category_id' => $replacementCategoryId,
                    ]);
            }

            $deletedCategoriesCount = Category::query()
                ->where('app_id', $appId)
                ->whereIn('id', $selectedCategoryIds)
                ->delete();
        });

        return [
            'moved_articles_count' => $movedArticlesCount,
            'deleted_categories_count' => $deletedCategoriesCount,
            'affected_categories_count' => count($categoryIdsToDelete),
            'had_articles' => $shouldReassignArticles,
        ];
    }

    /**
     * @param  list<int>  $categoryIds
     */
    public function countArticlesInCategories(int $appId, array $categoryIds): int
    {
        $normalizedCategoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

        if ($normalizedCategoryIds === []) {
            return 0;
        }

        return Article::query()
            ->where('app_id', $appId)
            ->whereIn('category_id', $normalizedCategoryIds)
            ->count();
    }

    /**
     * @param  list<int>  $categoryIds
     * @return list<int>
     */
    public function resolveDeletionCategoryIds(int $appId, array $categoryIds): array
    {
        $normalizedCategoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

        if ($normalizedCategoryIds === []) {
            return [];
        }

        $allCategoryIds = $normalizedCategoryIds;
        $pendingParentIds = $normalizedCategoryIds;

        while ($pendingParentIds !== []) {
            $childCategoryIds = Category::query()
                ->where('app_id', $appId)
                ->whereIn('parent_id', $pendingParentIds)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            $newChildCategoryIds = array_values(array_diff($childCategoryIds, $allCategoryIds));

            if ($newChildCategoryIds === []) {
                break;
            }

            $allCategoryIds = array_values(array_unique(array_merge($allCategoryIds, $newChildCategoryIds)));
            $pendingParentIds = $newChildCategoryIds;
        }

        sort($allCategoryIds);

        return $allCategoryIds;
    }

    /**
     * @param  list<int>  $excludeCategoryIds
     * @return array<int, string>
     */
    public function getReplacementCategoryOptions(int $appId, array $excludeCategoryIds = []): array
    {
        $normalizedExcludeIds = array_values(array_unique(array_map('intval', $excludeCategoryIds)));

        return Category::query()
            ->where('app_id', $appId)
            ->when(
                $normalizedExcludeIds !== [],
                fn ($query) => $query->whereNotIn('id', $normalizedExcludeIds),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }
}
