<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Article;
use App\Models\Category;
use App\Services\ArticleAiService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

class ReprocessSelectedArticlesAction
{
    public function __construct(
        private readonly ArticleAiService $aiService,
    ) {}

    public function executeCombined(EloquentCollection $records): int
    {
        return $this->processRecords($records, updateTitle: true, updateCategory: true);
    }

    public function executeTitleOnly(EloquentCollection $records): int
    {
        return $this->processRecords($records, updateTitle: true, updateCategory: false);
    }

    public function executeCategoryOnly(EloquentCollection $records): int
    {
        return $this->processRecords($records, updateTitle: false, updateCategory: true);
    }

    private function processRecords(EloquentCollection $records, bool $updateTitle, bool $updateCategory): int
    {
        $articles = $records
            ->filter(static fn (mixed $record): bool => $record instanceof Article)
            ->values();

        if ($articles->isEmpty()) {
            return 0;
        }

        $articles->loadMissing('app.categories');

        $updatedCount = 0;

        foreach ($articles->groupBy(static fn (Article $article): int => (int) $article->app_id) as $appArticles) {
            $firstArticle = $appArticles->first();

            if (! $firstArticle instanceof Article) {
                continue;
            }

            $app = $firstArticle->app;

            if (! $app) {
                continue;
            }

            $categories = $app->categories
                ->map(static fn (Category $category): array => [
                    'id' => (int) $category->getKey(),
                    'name' => $category->name,
                ])
                ->values()
                ->all();

            if ($categories === []) {
                Log::warning('[Article bulk AI] カテゴリが見つからないためスキップしました。', [
                    'app_id' => $app->getKey(),
                    'record_count' => $appArticles->count(),
                ]);

                continue;
            }

            foreach ($appArticles->chunk(10) as $chunk) {
                $batchArticles = $chunk
                    ->map(function (Article $article): ?array {
                        $sourceTitle = $this->resolveSourceTitle($article);

                        if ($sourceTitle === null) {
                            return null;
                        }

                        return [
                            'id' => (int) $article->getKey(),
                            'title' => $sourceTitle,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                if ($batchArticles === []) {
                    continue;
                }

                $aiResults = $this->aiService->classifyAndRewriteBatch(
                    articles: $batchArticles,
                    categories: $categories,
                    app: $app,
                );

                foreach ($chunk as $article) {
                    if (! $article instanceof Article) {
                        continue;
                    }

                    $result = $aiResults[(int) $article->getKey()] ?? null;

                    if (! $result instanceof \App\DTOs\AiAnalyzedData) {
                        continue;
                    }

                    $updates = [];

                    if ($updateCategory && isset($result->categoryId)) {
                        $updates['category_id'] = (int) $result->categoryId;
                    }

                    if ($updateTitle && isset($result->rewrittenTitle)) {
                        $rewrittenTitle = trim($result->rewrittenTitle);

                        if ($rewrittenTitle !== '') {
                            $updates['title'] = $rewrittenTitle;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    $article->update($updates);
                    $updatedCount++;
                }
            }
        }

        return $updatedCount;
    }

    private function resolveSourceTitle(Article $article): ?string
    {
        $originalTitle = trim((string) $article->original_title);

        if ($originalTitle !== '') {
            return $originalTitle;
        }

        $currentTitle = trim((string) $article->title);

        return $currentTitle !== '' ? $currentTitle : null;
    }
}
