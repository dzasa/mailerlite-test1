<?php

use App\Http\Controllers\Api\V1\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::post('v1/subscribers', SubscriberController::class)
    ->middleware('throttle:subscribers')
    ->name('api.v1.subscribers.store');
