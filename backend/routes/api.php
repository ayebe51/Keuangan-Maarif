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

        // Tenant-Scoped Accounting Module
        Route::middleware(['tenant.require'])->group(function () {
            // Fiscal Periods
            Route::get('/fiscal-periods', [\App\Http\Controllers\Api\FiscalPeriodController::class, 'index'])->name('fiscal-periods.index');
            Route::post('/fiscal-periods', [\App\Http\Controllers\Api\FiscalPeriodController::class, 'store'])->name('fiscal-periods.store');
            Route::post('/fiscal-periods/{id}/close', [\App\Http\Controllers\Api\FiscalPeriodController::class, 'close'])->name('fiscal-periods.close');
            Route::post('/fiscal-periods/{id}/reopen', [\App\Http\Controllers\Api\FiscalPeriodController::class, 'reopen'])->name('fiscal-periods.reopen');

            // Chart of Accounts (COA)
            Route::get('/accounts', [\App\Http\Controllers\Api\AccountController::class, 'index'])->name('accounts.index');
            Route::get('/accounts/tree', [\App\Http\Controllers\Api\AccountController::class, 'tree'])->name('accounts.tree');
            Route::post('/accounts', [\App\Http\Controllers\Api\AccountController::class, 'store'])->name('accounts.store');
            Route::put('/accounts/{id}', [\App\Http\Controllers\Api\AccountController::class, 'update'])->name('accounts.update');
            Route::get('/accounts/mappings', [\App\Http\Controllers\Api\AccountController::class, 'mappings'])->name('accounts.mappings');
            Route::post('/accounts/mappings', [\App\Http\Controllers\Api\AccountController::class, 'storeMapping'])->name('accounts.store-mapping');

            // Journal Entries
            Route::get('/journal-entries', [\App\Http\Controllers\Api\JournalEntryController::class, 'index'])->name('journal-entries.index');
            Route::get('/journal-entries/{id}', [\App\Http\Controllers\Api\JournalEntryController::class, 'show'])->name('journal-entries.show');
            Route::post('/journal-entries', [\App\Http\Controllers\Api\JournalEntryController::class, 'store'])->name('journal-entries.store');
            Route::post('/journal-entries/{id}/post', [\App\Http\Controllers\Api\JournalEntryController::class, 'post'])->name('journal-entries.post');
            Route::post('/journal-entries/{id}/reverse', [\App\Http\Controllers\Api\JournalEntryController::class, 'reverse'])->name('journal-entries.reverse');

            // Opening Balances
            Route::get('/opening-balances', [\App\Http\Controllers\Api\OpeningBalanceController::class, 'index'])->name('opening-balances.index');
            Route::post('/opening-balances', [\App\Http\Controllers\Api\OpeningBalanceController::class, 'store'])->name('opening-balances.store');
            Route::post('/opening-balances/{id}/process', [\App\Http\Controllers\Api\OpeningBalanceController::class, 'process'])->name('opening-balances.process');
        });
    });
});
