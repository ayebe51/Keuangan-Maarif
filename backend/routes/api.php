<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

    // Protected routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // Auth endpoints
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/switch-organization', [AuthController::class, 'switchOrganization'])->name('auth.switch-organization');

        // Organization Management
        Route::apiResource('organizations', OrganizationController::class);

        // User Management
        Route::apiResource('users', UserController::class);
    });
});
