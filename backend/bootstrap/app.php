<?php

use App\Domain\Accounting\Exceptions\AlreadyPostedException;
use App\Domain\Accounting\Exceptions\AlreadyReversedException;
use App\Domain\Accounting\Exceptions\AmbiguousTransferPairingException;
use App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException;
use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use App\Domain\Accounting\Exceptions\InvalidTransferPairingException;
use App\Domain\Accounting\Exceptions\InvalidWorkflowStateException;
use App\Domain\Accounting\Exceptions\NonPostableAccountException;
use App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException;
use App\Domain\Accounting\Exceptions\SegregationOfDutiesException;
use App\Domain\Accounting\Exceptions\UnbalancedJournalException;
use App\Domain\Accounting\Exceptions\UnpairedTransferException;
use App\Domain\Bank\Exceptions\DuplicateImportException;
use App\Domain\Bank\Exceptions\InvalidBankStatementFormatException;
use App\Domain\Bank\Exceptions\UnsupportedBankFormatException;
use App\Domain\Fund\Exceptions\NegativeFundBalanceException;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Http\Middleware\AuditMiddleware;
use App\Http\Middleware\RequireTenantMiddleware;
use App\Http\Middleware\ResolveTenantMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.resolve' => ResolveTenantMiddleware::class,
            'tenant.require' => RequireTenantMiddleware::class,
            'audit.trail' => AuditMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->api(append: [
            AuditMiddleware::class,
            ResolveTenantMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (CrossTenantViolationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'CROSS_TENANT_VIOLATION',
            ], 403);
        });

        $exceptions->render(function (SegregationOfDutiesException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'SEGREGATION_OF_DUTIES_VIOLATION',
            ], 403);
        });

        $exceptions->render(function (ImmutableJournalException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'IMMUTABLE_JOURNAL_VIOLATION',
            ], 403);
        });

        $exceptions->render(function (UnbalancedJournalException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'UNBALANCED_JOURNAL',
            ], 422);
        });

        $exceptions->render(function (UnpairedTransferException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'UNPAIRED_TRANSFER',
                'business_transaction_id' => $e->businessTransactionId,
                'pairing_status' => $e->pairingStatus,
            ], 422);
        });

        $exceptions->render(function (AmbiguousTransferPairingException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'AMBIGUOUS_TRANSFER_PAIRING',
                'business_transaction_id' => $e->businessTransactionId,
                'candidate_ids' => $e->candidateIds,
            ], 422);
        });

        $exceptions->render(function (InvalidTransferPairingException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_TRANSFER_PAIRING',
                'source_transaction_id' => $e->sourceTransactionId,
                'destination_transaction_id' => $e->destinationTransactionId,
            ], 422);
        });

        $exceptions->render(function (ClosedFiscalPeriodException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'CLOSED_FISCAL_PERIOD',
            ], 422);
        });

        $exceptions->render(function (NonPostableAccountException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'NON_POSTABLE_ACCOUNT',
            ], 422);
        });

        $exceptions->render(function (AlreadyReversedException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'ALREADY_REVERSED',
            ], 422);
        });

        $exceptions->render(function (AlreadyPostedException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'ALREADY_POSTED',
            ], 422);
        });

        $exceptions->render(function (InvalidWorkflowStateException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_WORKFLOW_STATE',
            ], 422);
        });

        $exceptions->render(function (OpeningBalanceConfigurationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'OPENING_BALANCE_CONFIG_ERROR',
            ], 422);
        });

        $exceptions->render(function (NegativeFundBalanceException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'NEGATIVE_FUND_BALANCE',
                'fund_code' => $e->fundCode,
                'attempted_amount' => $e->attemptedAmount,
                'available_amount' => $e->availableAmount,
            ], 422);
        });

        $exceptions->render(function (DuplicateImportException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'DUPLICATE_IMPORT',
                'filename' => $e->filename,
                'file_hash' => $e->fileHash,
                'bank_account_id' => $e->bankAccountId,
            ], 409);
        });

        $exceptions->render(function (InvalidBankStatementFormatException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_BANK_STATEMENT_FORMAT',
                'filename' => $e->filename,
                'details' => $e->details,
            ], 422);
        });

        $exceptions->render(function (UnsupportedBankFormatException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'UNSUPPORTED_BANK_FORMAT',
                'format' => $e->format,
            ], 415);
        });
    })->create();
