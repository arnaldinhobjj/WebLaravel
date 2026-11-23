<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionRunController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\EnsureCurrentUser;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/favorites/{module}', [DashboardController::class, 'toggleFavorite'])->name('favorites.toggle');
// Shared actions (actions/*.yml in the project).
Route::prefix('actions/shared/{slug}')->name('actions.shared.')->controller(ActionRunController::class)->group(function () {
    Route::get('/', 'showShared')->name('show');
    Route::get('/run', 'createShared')->name('run.form');
    Route::post('/run', 'storeShared')->name('run');
});
Route::post('/mentions/{ticket}/resolve', [DashboardController::class, 'resolveMention'])->whereNumber('ticket')->name('mentions.resolve');
Route::post('/mentions/{ticket}/dismiss', [DashboardController::class, 'dismissMention'])->whereNumber('ticket')->name('mentions.dismiss');
Route::post('/mentions/restore', [DashboardController::class, 'restoreMentions'])->name('mentions.restore');

Route::post('/profile/switch/{user}', [DashboardController::class, 'switchUser'])->name('profile.switch');
Route::post('/settings/reset-home', [DashboardController::class, 'resetHome'])->name('settings.reset-home');

Route::prefix('ticket')->name('tickets.')->controller(TicketController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{ticket}', 'show')->whereNumber('ticket')->name('show');
    Route::patch('/{ticket}', 'update')->whereNumber('ticket')->name('update');
    Route::post('/{ticket}/comments', 'comment')->whereNumber('ticket')->name('comment');
});

// Personal pages: /{username}/settings and /{username}/actions.
Route::prefix('{user:username}')
    ->middleware(EnsureCurrentUser::class)
    ->scopeBindings()
    ->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::resource('actions', ActionController::class)->except('show')->names('actions');
        Route::get('/actions/{action}/run', [ActionRunController::class, 'create'])->name('actions.custom.run.form');
        Route::post('/actions/{action}/run', [ActionRunController::class, 'store'])->name('actions.custom.run');
    });
