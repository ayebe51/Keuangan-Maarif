<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.resolve' => \App\Http\Middleware\ResolveTenantMiddleware::class,
            'tenant.require' => \App\Http\Middleware\RequireTenantMiddleware::class,
            'audit.trail' => \App\Http\Middleware\AuditMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\AuditMiddleware::class,
            \App\Http\Middleware\ResolveTenantMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\App\Domain\Organization\Exceptions\CrossTenantViolationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'CROSS_TENANT_VIOLATION',
            ], 403);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\SegregationOfDutiesException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'SEGREGATION_OF_DUTIES_VIOLATION',
            ], 403);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\ImmutableJournalException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'IMMUTABLE_JOURNAL_VIOLATION',
            ], 403);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\UnbalancedJournalException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'UNBALANCED_JOURNAL',
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\ClosedFiscalPeriodException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'CLOSED_FISCAL_PERIOD',
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\NonPostableAccountException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'NON_POSTABLE_ACCOUNT',
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\AlreadyReversedException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'ALREADY_REVERSED',
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\AlreadyPostedException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'ALREADY_POSTED',
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Accounting\Exceptions\OpeningBalanceConfigurationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'OPENING_BALANCE_CONFIG_ERROR',
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Fund\Exceptions\NegativeFundBalanceException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'NEGATIVE_FUND_BALANCE',
                'fund_code' => $e->fundCode,
                'attempted_amount' => $e->attemptedAmount,
                'available_amount' => $e->availableAmount,
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Bank\Exceptions\DuplicateImportException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'DUPLICATE_IMPORT',
                'filename' => $e->filename,
                'file_hash' => $e->fileHash,
                'bank_account_id' => $e->bankAccountId,
            ], 409);
        });

        $exceptions->render(function (\App\Domain\Bank\Exceptions\InvalidBankStatementFormatException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_BANK_STATEMENT_FORMAT',
                'filename' => $e->filename,
                'details' => $e->details,
            ], 422);
        });

        $exceptions->render(function (\App\Domain\Bank\Exceptions\UnsupportedBankFormatException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'UNSUPPORTED_BANK_FORMAT',
                'format' => $e->format,
            ], 415);
        });
    })->create();
