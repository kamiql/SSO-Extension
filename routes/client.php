<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sso\Http\Controllers\ConnectionController;

Route::get('/connections', [ConnectionController::class, 'index'])->name('connections.index');
Route::delete('/connections/{provider}', [ConnectionController::class, 'destroy'])->where('provider', '[a-z0-9-]+')->name('connections.destroy');
