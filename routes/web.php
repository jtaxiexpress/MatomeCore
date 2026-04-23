<?php

use App\Http\Controllers\Front\ArticleRedirectController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Frontend Routes
|--------------------------------------------------------------------------
*/

Route::livewire('/', 'pages::cross-app-home')
    ->name('front.index');

Route::livewire('/s/{app:api_slug}', 'pages::home')
    ->name('front.home');

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
