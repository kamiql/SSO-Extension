<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sso\Http\Controllers\AuthorizationController;

Route::get('/{provider}/link', [AuthorizationController::class, 'link'])->where('provider', '[a-z0-9-]+')->name('link');
