<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankImportController;
use App\Http\Controllers\Api\BankTransactionController;
use App\Http\Controllers\Api\BusinessTransactionController;
use App\Http\Controllers\Api\BusinessTransactionPostingController;
use App\Http\Controllers\Api\ClassificationRuleController;
use App\Http\Controllers\Api\CounterpartyController;
use App\Http\Controllers\Api\FiscalPeriodController;
use App\Http\Controllers\Api\FundController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\TransactionCategoryController;
use App\Http\Controllers\Api\TransactionClassificationController;
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
            Route::get('/fiscal-periods', [FiscalPeriodController::class, 'index'])->name('fiscal-periods.index');
            Route::post('/fiscal-periods', [FiscalPeriodController::class, 'store'])->name('fiscal-periods.store');
            Route::post('/fiscal-periods/{id}/close', [FiscalPeriodController::class, 'close'])->name('fiscal-periods.close');
            Route::post('/fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopen'])->name('fiscal-periods.reopen');

            // Chart of Accounts (COA)
            Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::get('/accounts/tree', [AccountController::class, 'tree'])->name('accounts.tree');
            Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
            Route::put('/accounts/{id}', [AccountController::class, 'update'])->name('accounts.update');
            Route::get('/accounts/mappings', [AccountController::class, 'mappings'])->name('accounts.mappings');
            Route::post('/accounts/mappings', [AccountController::class, 'storeMapping'])->name('accounts.store-mapping');

            // Journal Entries
            Route::get('/journal-entries', [JournalEntryController::class, 'index'])->name('journal-entries.index');
            Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show'])->name('journal-entries.show');
            Route::post('/journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store');
            Route::post('/journal-entries/{id}/post', [JournalEntryController::class, 'post'])->name('journal-entries.post');
            Route::post('/journal-entries/{id}/reverse', [JournalEntryController::class, 'reverse'])->name('journal-entries.reverse');

            // Opening Balances
            Route::get('/opening-balances', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
            Route::post('/opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
            Route::post('/opening-balances/{id}/process', [OpeningBalanceController::class, 'process'])->name('opening-balances.process');

            // Master Data: Bank Accounts
            Route::apiResource('bank-accounts', BankAccountController::class);

            // Master Data: Counterparties & Aliases
            Route::post('/counterparties/resolve', [CounterpartyController::class, 'resolve'])->name('counterparties.resolve');
            Route::get('/counterparties/resolve', [CounterpartyController::class, 'resolve']);
            Route::post('/counterparties/{id}/aliases', [CounterpartyController::class, 'addAlias'])->name('counterparties.add-alias');
            Route::delete('/counterparties/{id}/aliases/{aliasId}', [CounterpartyController::class, 'removeAlias'])->name('counterparties.remove-alias');
            Route::apiResource('counterparties', CounterpartyController::class);

            // Master Data: Transaction Categories
            Route::apiResource('transaction-categories', TransactionCategoryController::class);

            // Master Data: Funds & Allocations
            Route::get('/funds/{id}/summary', [FundController::class, 'summary'])->name('funds.summary');
            Route::post('/funds/{id}/allocate', [FundController::class, 'allocate'])->name('funds.allocate');
            Route::post('/funds/allocations/{allocationId}/commit', [FundController::class, 'commit'])->name('funds.commit');
            Route::post('/funds/allocations/{allocationId}/disburse', [FundController::class, 'disburse'])->name('funds.disburse');
            Route::post('/funds/allocations/{allocationId}/return', [FundController::class, 'returnFunds'])->name('funds.return');
            Route::apiResource('funds', FundController::class);

            // Master Data: Programs
            Route::apiResource('programs', ProgramController::class);

            // Phase 5: Bank Ingestion & Statement Import Engine
            Route::post('/bank-imports', [BankImportController::class, 'store'])->name('bank-imports.store');
            Route::get('/bank-imports', [BankImportController::class, 'index'])->name('bank-imports.index');
            Route::get('/bank-imports/{id}', [BankImportController::class, 'show'])->name('bank-imports.show');
            Route::get('/bank-imports/{id}/raw-sources', [BankImportController::class, 'rawSources'])->name('bank-imports.raw-sources');
            Route::get('/bank-imports/{id}/exceptions', [BankImportController::class, 'exceptions'])->name('bank-imports.exceptions');

            // Phase 5: Normalized Bank Transactions
            Route::get('/bank-transactions', [BankTransactionController::class, 'index'])->name('bank-transactions.index');
            Route::get('/bank-transactions/{id}', [BankTransactionController::class, 'show'])->name('bank-transactions.show');

            // Phase 6: Classification Rules Engine
            Route::post('/classification-rules/reorder', [ClassificationRuleController::class, 'reorder'])->name('classification-rules.reorder');
            Route::apiResource('classification-rules', ClassificationRuleController::class);

            // Phase 6: Transaction Classification Pipeline & Review Queue
            Route::post('/transactions/classify-batch', [TransactionClassificationController::class, 'classifyBatch'])->name('transactions.classify-batch');
            Route::post('/transactions/{id}/classify', [TransactionClassificationController::class, 'classifySingle'])->name('transactions.classify');
            Route::get('/transactions/unclassified', [TransactionClassificationController::class, 'getUnclassified'])->name('transactions.unclassified');
            Route::get('/transactions/suggestions', [TransactionClassificationController::class, 'getSuggestions'])->name('transactions.suggestions');
            Route::post('/transactions/suggestions/{id}/apply', [TransactionClassificationController::class, 'applySuggestion'])->name('transactions.apply-suggestion');
            Route::post('/transactions/suggestions/{id}/reject', [TransactionClassificationController::class, 'rejectSuggestion'])->name('transactions.reject-suggestion');
            Route::post('/transactions/{id}/manual-classify', [TransactionClassificationController::class, 'manualClassify'])->name('transactions.manual-classify');

            // Phase 6: Business Transactions
            Route::get('/business-transactions', [BusinessTransactionController::class, 'index'])->name('business-transactions.index');

            // Phase 7: Business Transaction Posting Workflow (Human Approval Path & Auto-Post Engine)
            Route::get('/business-transactions/pending-posting', [BusinessTransactionPostingController::class, 'pendingPosting'])->name('business-transactions.pending-posting');
            Route::get('/business-transactions/posting-exceptions', [BusinessTransactionPostingController::class, 'postingExceptions'])->name('business-transactions.posting-exceptions');
            Route::get('/business-transactions/transfer-queue', [BusinessTransactionPostingController::class, 'transferReviewQueue'])->name('business-transactions.transfer-queue');
            Route::get('/business-transactions/unapplied-cash-queue', [BusinessTransactionPostingController::class, 'unappliedCashQueue'])->name('business-transactions.unapplied-cash-queue');
            Route::post('/business-transactions/auto-post-batch', [BusinessTransactionPostingController::class, 'autoPostBatch'])->name('business-transactions.auto-post-batch');
            Route::post('/business-transactions/detect-transfer-pairs', [BusinessTransactionPostingController::class, 'detectTransferPairs'])->name('business-transactions.detect-transfer-pairs');
            Route::get('/business-transactions/{id}', [BusinessTransactionController::class, 'show'])->name('business-transactions.show');
            Route::post('/business-transactions/{id}/approve-and-post', [BusinessTransactionPostingController::class, 'approveAndPost'])->name('business-transactions.approve-and-post');
            Route::post('/business-transactions/{id}/reject', [BusinessTransactionPostingController::class, 'reject'])->name('business-transactions.reject');
            Route::post('/business-transactions/{id}/reverse', [BusinessTransactionPostingController::class, 'reverse'])->name('business-transactions.reverse');
            Route::post('/business-transactions/{id}/pair-transfer', [BusinessTransactionPostingController::class, 'pairTransfer'])->name('business-transactions.pair-transfer');
            Route::post('/business-transactions/{id}/unpair-transfer', [BusinessTransactionPostingController::class, 'unpairTransfer'])->name('business-transactions.unpair-transfer');
            Route::post('/business-transactions/{id}/resolve-unapplied-cash', [BusinessTransactionPostingController::class, 'resolveUnappliedCash'])->name('business-transactions.resolve-unapplied-cash');
        });
    });
});
