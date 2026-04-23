<?php

use App\Http\Controllers\Front\ArticleRedirectController;
use App\Http\Controllers\Front\FeedController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Frontend Routes
|--------------------------------------------------------------------------
*/

Route::livewire('/', 'pages::cross-app-home')
    ->name('front.index');

Route::livewire('/apply', 'pages::site-application')
    ->name('front.apply');

Route::livewire('/ranking', 'pages::site-ranking')
    ->name('front.ranking');

Route::livewire('/about', 'pages::rss-list')
    ->name('front.rss-index');

Route::livewire('/sites', 'pages::sites-index')
    ->name('front.sites-index');

Route::livewire('/s/{app:api_slug}', 'pages::home')
    ->name('front.home');

Route::get('/rss', [FeedController::class, 'index'])
    ->name('front.rss.index');

Route::get('/s/{app:api_slug}/rss', [FeedController::class, 'app'])
    ->name('front.rss.app');

Route::get('/s/{app:api_slug}/c/{category:api_slug}/rss', [FeedController::class, 'category'])
    ->name('front.rss.category');

Route::get('/s/{app:api_slug}/go/{article}', ArticleRedirectController::class)
    ->name('front.go');

/*
|--------------------------------------------------------------------------
| Dev Helpers
|--------------------------------------------------------------------------
*/

if (app()->environment('local')) {
    // Dev helper: login as given user id and redirect to admin panel.
    // Only available in local environment for quick testing.
    Route::get('/_dev/login-as/{id}', function ($id) {
        $user = User::findOrFail($id);

        auth()->login($user);

        return redirect('/admin');
    });
}
