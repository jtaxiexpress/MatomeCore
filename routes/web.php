<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (app()->environment('local')) {
    // Dev helper: login as given user id and redirect to admin panel.
    // Only available in local environment for quick testing.
    Route::get('/_dev/login-as/{id}', function ($id) {
        $user = \App\Models\User::findOrFail($id);

        auth()->login($user);

        return redirect('/admin');
    });
}
