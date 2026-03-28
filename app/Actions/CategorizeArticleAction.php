<?php

namespace App\Actions;

use App\Models\Article;
use App\Services\ArticleAiService;

class CategorizeArticleAction
{
    public function execute(Article $article): void
    {
        $categories = $article->app->categories()->get();

        if ($categories->isEmpty()) {
            return;
        }

        $categoriesOptions = $categories->map(fn($cat) => ['id' => $cat->id, 'name' => $cat->name])->toArray();
        
        $aiService = new ArticleAiService();
        $response = $aiService->classifyAndRewrite($article->title, $categoriesOptions);
        
        $categoryId = $response['category_id'];
        $rewrittenTitle = $response['rewritten_title'];

        if ($categories->contains('id', $categoryId)) {
            $article->update([
                'category_id' => $categoryId,
                'title' => $rewrittenTitle,
            ]);
        }
    }
}
