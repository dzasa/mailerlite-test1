<?php

use App\Http\Controllers\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SubscriberController::class, 'index'])->name('home');
Route::inertia('subscribers/create', 'subscribers/Create')->name('subscribers.create');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
