<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/favorites/{module}', [DashboardController::class, 'toggleFavorite'])->name('favorites.toggle');
Route::post('/actions/{action}/run', [DashboardController::class, 'runAction'])->name('actions.run');
Route::post('/mentions/{ticket}/resolve', [DashboardController::class, 'resolveMention'])->whereNumber('ticket')->name('mentions.resolve');
Route::post('/mentions/{ticket}/dismiss', [DashboardController::class, 'dismissMention'])->whereNumber('ticket')->name('mentions.dismiss');
Route::post('/mentions/restore', [DashboardController::class, 'restoreMentions'])->name('mentions.restore');
