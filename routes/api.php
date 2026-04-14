<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PublicFeedController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('v1.')
    ->scopeBindings()
    ->middleware('throttle:public-feed')
    ->group(function (): void {
        Route::get('/apps', [PublicFeedController::class, 'apps'])
            ->name('apps.index');

        Route::get('/apps/{app:api_slug}/categories', [PublicFeedController::class, 'categories'])
            ->name('apps.categories.index');

        Route::get('/apps/{app:api_slug}/categories/{category:api_slug}/articles', [PublicFeedController::class, 'articles'])
            ->name('apps.categories.articles.index');
    });
