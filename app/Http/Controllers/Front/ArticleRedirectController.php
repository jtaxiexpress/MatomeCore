<?php

declare(strict_types=1);

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Article;
use App\Models\ArticleClick;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ArticleRedirectController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, App $app, Article $article): RedirectResponse
    {
        // Require that the article belongs to one of the app's sites
        // OR just record the click since the article ID is valid.
        // It's safer to check the relation if we want strict tenancy, but
        // for speed, we can just record the click. Let's do a simple check.
        if ($article->site->app_id !== $app->id) {
            abort(404);
        }

        // Record the click asynchronously or synchronously.
        // For accurate metrics, we'll record synchronously here.
        ArticleClick::create([
            'article_id' => $article->id,
            'clicked_at' => now(),
        ]);

        return redirect()->away($article->url);
    }
}
