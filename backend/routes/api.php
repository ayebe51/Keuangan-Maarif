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

            // Master Data: Bank Accounts
            Route::apiResource('bank-accounts', \App\Http\Controllers\Api\BankAccountController::class);

            // Master Data: Counterparties & Aliases
            Route::post('/counterparties/resolve', [\App\Http\Controllers\Api\CounterpartyController::class, 'resolve'])->name('counterparties.resolve');
            Route::get('/counterparties/resolve', [\App\Http\Controllers\Api\CounterpartyController::class, 'resolve']);
            Route::post('/counterparties/{id}/aliases', [\App\Http\Controllers\Api\CounterpartyController::class, 'addAlias'])->name('counterparties.add-alias');
            Route::delete('/counterparties/{id}/aliases/{aliasId}', [\App\Http\Controllers\Api\CounterpartyController::class, 'removeAlias'])->name('counterparties.remove-alias');
            Route::apiResource('counterparties', \App\Http\Controllers\Api\CounterpartyController::class);

            // Master Data: Transaction Categories
            Route::apiResource('transaction-categories', \App\Http\Controllers\Api\TransactionCategoryController::class);

            // Master Data: Funds & Allocations
            Route::get('/funds/{id}/summary', [\App\Http\Controllers\Api\FundController::class, 'summary'])->name('funds.summary');
            Route::post('/funds/{id}/allocate', [\App\Http\Controllers\Api\FundController::class, 'allocate'])->name('funds.allocate');
            Route::post('/funds/allocations/{allocationId}/commit', [\App\Http\Controllers\Api\FundController::class, 'commit'])->name('funds.commit');
            Route::post('/funds/allocations/{allocationId}/disburse', [\App\Http\Controllers\Api\FundController::class, 'disburse'])->name('funds.disburse');
            Route::post('/funds/allocations/{allocationId}/return', [\App\Http\Controllers\Api\FundController::class, 'returnFunds'])->name('funds.return');
            Route::apiResource('funds', \App\Http\Controllers\Api\FundController::class);

            // Master Data: Programs
            Route::apiResource('programs', \App\Http\Controllers\Api\ProgramController::class);

            // Phase 5: Bank Ingestion & Statement Import Engine
            Route::post('/bank-imports', [\App\Http\Controllers\Api\BankImportController::class, 'store'])->name('bank-imports.store');
            Route::get('/bank-imports', [\App\Http\Controllers\Api\BankImportController::class, 'index'])->name('bank-imports.index');
            Route::get('/bank-imports/{id}', [\App\Http\Controllers\Api\BankImportController::class, 'show'])->name('bank-imports.show');
            Route::get('/bank-imports/{id}/raw-sources', [\App\Http\Controllers\Api\BankImportController::class, 'rawSources'])->name('bank-imports.raw-sources');
            Route::get('/bank-imports/{id}/exceptions', [\App\Http\Controllers\Api\BankImportController::class, 'exceptions'])->name('bank-imports.exceptions');

            // Phase 5: Normalized Bank Transactions
            Route::get('/bank-transactions', [\App\Http\Controllers\Api\BankTransactionController::class, 'index'])->name('bank-transactions.index');
            Route::get('/bank-transactions/{id}', [\App\Http\Controllers\Api\BankTransactionController::class, 'show'])->name('bank-transactions.show');
        });
    });
});
