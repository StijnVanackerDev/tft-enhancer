<?php

use App\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('players/lookup', [PlayerController::class, 'lookup'])
    ->middleware('throttle:20,1')
    ->name('players.lookup');

Route::post('players/{player}/refresh', [PlayerController::class, 'refresh'])
    ->middleware('throttle:10,1')
    ->name('players.refresh');

Route::get('players/{platform}/{riotId}', [PlayerController::class, 'show'])
    ->where('riotId', '.+')
    ->name('players.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
