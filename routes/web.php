<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionRunController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SharedActionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\EnsureCurrentUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/favorites/{module}', [DashboardController::class, 'toggleFavorite'])->name('favorites.toggle');
    // Shared actions (actions/*.yml in the project): view and edit the YAML, run.
    Route::get('/actions/shared/{slug}', [SharedActionController::class, 'edit'])->name('actions.shared.show');
    Route::put('/actions/shared/{slug}', [SharedActionController::class, 'update'])->middleware('can:actions.shared.edit')->name('actions.shared.update');
    Route::prefix('actions/shared/{slug}')->name('actions.shared.')->controller(ActionRunController::class)->group(function () {
        Route::get('/run', 'createShared')->name('run.form');
        Route::post('/run', 'storeShared')->name('run');
    });
    Route::post('/mentions/{ticket}/resolve', [DashboardController::class, 'resolveMention'])->whereNumber('ticket')->name('mentions.resolve');
    Route::post('/mentions/{ticket}/dismiss', [DashboardController::class, 'dismissMention'])->whereNumber('ticket')->name('mentions.dismiss');
    Route::post('/mentions/restore', [DashboardController::class, 'restoreMentions'])->name('mentions.restore');

    Route::post('/settings/reset-home', [DashboardController::class, 'resetHome'])->name('settings.reset-home');

    Route::prefix('ticket')->name('tickets.')->controller(TicketController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{ticket}', 'show')->whereNumber('ticket')->name('show');
        Route::patch('/{ticket}', 'update')->whereNumber('ticket')->name('update');
        Route::post('/{ticket}/comments', 'comment')->whereNumber('ticket')->name('comment');
    });

    // Tags page: tags, departments and people, for those allowed to manage them.
    Route::prefix('tags')->middleware('can:people.manage')->group(function () {
        Route::resource('departments', DepartmentController::class)->except(['index', 'show']);
        Route::resource('people', PersonController::class)->only(['create', 'store', 'edit', 'update'])->parameters(['people' => 'person']);
    });
    Route::resource('tags', TagController::class)->except('show')->middleware('can:people.manage')->whereNumber('tag');

    // Personal pages: /{username}/settings and /{username}/actions.
    Route::prefix('{user:username}')
        ->middleware(EnsureCurrentUser::class)
        ->scopeBindings()
        ->group(function () {
            Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

            Route::resource('actions', ActionController::class)->except('show')->names('actions');
            Route::get('/actions/{action}/run', [ActionRunController::class, 'create'])->name('actions.custom.run.form');
            Route::post('/actions/{action}/run', [ActionRunController::class, 'store'])->name('actions.custom.run');
        });
});
