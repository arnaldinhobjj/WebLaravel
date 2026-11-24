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
    Route::get('/actions/shared/{slug}', [SharedActionController::class, 'edit'])->middleware('can:shared_actions.view')->name('actions.shared.show');
    Route::put('/actions/shared/{slug}', [SharedActionController::class, 'update'])->middleware('can:shared_actions.edit')->name('actions.shared.update');
    Route::prefix('actions/shared/{slug}')->name('actions.shared.')->middleware('can:shared_actions.run')->controller(ActionRunController::class)->group(function () {
        Route::get('/run', 'createShared')->name('run.form');
        Route::post('/run', 'storeShared')->name('run');
    });
    Route::post('/mentions/{ticket}/resolve', [DashboardController::class, 'resolveMention'])->whereNumber('ticket')->name('mentions.resolve');
    Route::post('/mentions/{ticket}/dismiss', [DashboardController::class, 'dismissMention'])->whereNumber('ticket')->name('mentions.dismiss');
    Route::post('/mentions/restore', [DashboardController::class, 'restoreMentions'])->name('mentions.restore');

    Route::post('/settings/reset-home', [DashboardController::class, 'resetHome'])->name('settings.reset-home');

    Route::prefix('ticket')->name('tickets.')->controller(TicketController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:tickets.view')->name('index');
        Route::get('/create', 'create')->middleware('can:tickets.create')->name('create');
        Route::post('/', 'store')->middleware('can:tickets.create')->name('store');
        Route::get('/{ticket}', 'show')->whereNumber('ticket')->middleware('can:tickets.view')->name('show');
        Route::patch('/{ticket}', 'update')->whereNumber('ticket')->middleware('can:tickets.view')->name('update');
        Route::post('/{ticket}/comments', 'comment')->whereNumber('ticket')->middleware('can:tickets.reply')->name('comment');
    });

    // Tags page: tags, departments and people, for those allowed to manage them.
    Route::prefix('tags')->group(function () {
        Route::get('/departments/create', [DepartmentController::class, 'create'])->middleware('can:departments.create')->name('departments.create');
        Route::post('/departments', [DepartmentController::class, 'store'])->middleware('can:departments.create')->name('departments.store');
        Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->middleware('can:departments.edit')->name('departments.edit');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->middleware('can:departments.edit')->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->middleware('can:departments.delete')->name('departments.destroy');

        Route::get('/people/create', [PersonController::class, 'create'])->middleware('can:people.create')->name('people.create');
        Route::post('/people', [PersonController::class, 'store'])->middleware('can:people.create')->name('people.store');
        Route::get('/people/{person}/edit', [PersonController::class, 'edit'])->middleware('can:people.edit')->name('people.edit');
        Route::put('/people/{person}', [PersonController::class, 'update'])->middleware('can:people.edit')->name('people.update');

        Route::get('/', [TagController::class, 'index'])->middleware('can:tags-page')->name('tags.index');
        Route::get('/create', [TagController::class, 'create'])->middleware('can:tags.create')->name('tags.create');
        Route::post('/', [TagController::class, 'store'])->middleware('can:tags.create')->name('tags.store');
        Route::get('/{tag}/edit', [TagController::class, 'edit'])->whereNumber('tag')->middleware('can:tags.edit')->name('tags.edit');
        Route::put('/{tag}', [TagController::class, 'update'])->whereNumber('tag')->middleware('can:tags.edit')->name('tags.update');
        Route::delete('/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->middleware('can:tags.delete')->name('tags.destroy');
    });

    // Personal pages: /{username}/settings and /{username}/actions.
    Route::prefix('{user:username}')
        ->middleware(EnsureCurrentUser::class)
        ->scopeBindings()
        ->group(function () {
            Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

            Route::get('/actions', [ActionController::class, 'index'])->middleware('can:actions-page')->name('actions.index');
            Route::get('/actions/create', [ActionController::class, 'create'])->middleware('can:actions.create')->name('actions.create');
            Route::post('/actions', [ActionController::class, 'store'])->middleware('can:actions.create')->name('actions.store');
            Route::get('/actions/{action}/edit', [ActionController::class, 'edit'])->middleware('can:actions.edit')->name('actions.edit');
            Route::put('/actions/{action}', [ActionController::class, 'update'])->middleware('can:actions.edit')->name('actions.update');
            Route::delete('/actions/{action}', [ActionController::class, 'destroy'])->middleware('can:actions.delete')->name('actions.destroy');
            Route::get('/actions/{action}/run', [ActionRunController::class, 'create'])->middleware('can:actions.run')->name('actions.custom.run.form');
            Route::post('/actions/{action}/run', [ActionRunController::class, 'store'])->middleware('can:actions.run')->name('actions.custom.run');
        });
});
