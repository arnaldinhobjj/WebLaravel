<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/favorites/{module}', [DashboardController::class, 'toggleFavorite'])->name('favorites.toggle');
Route::post('/actions/{action}/run', [DashboardController::class, 'runAction'])->name('actions.run');
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
