<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sso\Http\Controllers\AuthorizationController;
use Sso\Http\Controllers\CheckpointController;
use Sso\Http\Controllers\ProviderController;

Route::get('/providers', ProviderController::class)->name('providers');
Route::get('/checkpoint', CheckpointController::class)->name('checkpoint');
Route::get('/{provider}/redirect', [AuthorizationController::class, 'login'])->where('provider', '[a-z0-9-]+')->name('redirect');
Route::get('/{provider}/callback', [AuthorizationController::class, 'callback'])->where('provider', '[a-z0-9-]+')->name('callback');
