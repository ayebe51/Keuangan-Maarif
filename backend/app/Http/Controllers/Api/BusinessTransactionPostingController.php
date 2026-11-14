<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Services\AutoPostExecutionCoordinator;
use App\Domain\Accounting\Services\BankTransferPostingCoordinator;
use App\Domain\Accounting\Services\BusinessTransactionPostingCoordinator;
use App\Domain\Accounting\Services\UnappliedCashHandler;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessTransactionPostingController extends Controller
{
    public function __construct(
        protected BusinessTransactionPostingCoordinator $coordinator
    ) {}

    /**
     * Lists business transactions pending approval and posting.
     */
    public function pendingPosting(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.view')) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin melihat antrean jurnal (journal.view).',
            ], 403);
        }

        $tenantId = (int) TenantContext::getTenantId();
        $perPage = (int) $request->query('per_page', 20);

        $results = $this->coordinator->getPendingPostingQuery($tenantId)->paginate($perPage);

        return response()->json($results);
    }

    /**
     * Lists business transactions with posting exceptions or rejections.
     */
    public function postingExceptions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.view')) {
            return response()->json([
                'message' => 'Akses ditolak: Anda tidak memiliki izin melihat exception jurnal (journal.view).',
            ], 403);
        }

        $tenantId = (int) TenantContext::getTenantId();
        $perPage = (int) $request->query('per_page', 20);

        $results = $this->coordinator->getPostingExceptionsQuery($tenantId)->paginate($perPage);

        return response()->json($results);
    }

    /**
     * Approves and posts a business transaction draft to the ledger (Maker != Checker).
     * Returns idempotent HTTP 200 payload if transaction has already been posted.
     */
    public function approveAndPost(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.approve') || ! $this->hasPermission($user, 'journal.post')) {
            return response()->json([
                'message' => 'Akses ditolak: Anda memerlukan izin journal.approve dan journal.post untuk menyetujui dan memposting transaksi.',
            ], 403);
        }

        $result = $this->coordinator->approveAndPost($id, $user);

        return response()->json($result, 200);
    }

    /**
     * Rejects a business transaction draft and sends it back to the review queue.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.review')) {
            return response()->json([
                'message' => 'Akses ditolak: Anda memerlukan izin journal.review untuk menolak transaksi.',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->coordinator->reject($id, $user, $validated['reason']);

        return response()->json($result, 200);
    }

    /**
     * Reverses an already posted business transaction in the general ledger.
     */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.reverse')) {
            return response()->json([
                'message' => 'Akses ditolak: Anda memerlukan izin journal.reverse untuk membalik transaksi.',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'reversal_date' => ['nullable', 'date'],
        ]);

        $result = $this->coordinator->reverse(
            $id,
            $user,
            $validated['reason'],
            $validated['reversal_date'] ?? null
        );

        return response()->json($result, 200);
    }

    /**
     * Executes batch automated posting for eligible classified transactions.
     * Requires journal.post permission and ACCOUNTING_ADMIN role.
     */
    public function autoPostBatch(Request $request, AutoPostExecutionCoordinator $autoPostCoordinator): JsonResponse
    {
        $user = $request->user();

        $hasPost = $this->hasPermission($user, 'journal.post');
        $isAdmin = $user->isSuperAdmin() || (method_exists($user, 'hasRole') && $user->hasRole('ACCOUNTING_ADMIN'));

        if (! $hasPost || (! $isAdmin && ! $user->isSuperAdmin())) {
            return response()->json([
                'message' => 'Akses ditolak: Anda memerlukan izin journal.post dan role ACCOUNTING_ADMIN untuk mengeksekusi batch auto-post.',
            ], 403);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $tenantId = (int) TenantContext::getTenantId();
        $limit = (int) ($validated['limit'] ?? 50);

        $telemetry = $autoPostCoordinator->executeBatch($tenantId, $limit);

        return response()->json([
            'message' => 'Eksekusi batch auto-post selesai.',
            'data' => $telemetry,
        ], 200);
    }

    /**
     * Explicitly pairs two bank transfer transactions (2:1 transfer).
     */
    public function pairTransfer(
        Request $request,
        int $id,
        BankTransferPostingCoordinator $transferCoordinator
    ): JsonResponse {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.review') && ! $this->hasPermission($user, 'journal.approve')) {
            return response()->json(['message' => 'Akses ditolak: Anda memerlukan izin journal.review atau journal.approve.'], 403);
        }

        $validated = $request->validate([
            'paired_transaction_id' => ['required', 'integer'],
        ]);

        $result = $transferCoordinator->pair($id, (int) $validated['paired_transaction_id'], $user);

        return response()->json([
            'message' => $result->message,
            'data' => $result->toArray(),
        ], 200);
    }

    /**
     * Unpairs a paired bank transfer transaction.
     */
    public function unpairTransfer(
        Request $request,
        int $id,
        BankTransferPostingCoordinator $transferCoordinator
    ): JsonResponse {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.review') && ! $this->hasPermission($user, 'journal.approve')) {
            return response()->json(['message' => 'Akses ditolak: Anda memerlukan izin journal.review atau journal.approve.'], 403);
        }

        $transferCoordinator->unpair($id, $user);

        return response()->json([
            'message' => 'Pasangan transfer berhasil dibatalkan (unpaired).',
        ], 200);
    }

    /**
     * Batch detects and deterministically pairs pending transfer transactions in the active tenant.
     */
    public function detectTransferPairs(
        Request $request,
        BankTransferPostingCoordinator $transferCoordinator
    ): JsonResponse {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.review') && ! $this->hasPermission($user, 'journal.approve')) {
            return response()->json(['message' => 'Akses ditolak: Anda memerlukan izin journal.review atau journal.approve.'], 403);
        }

        $tenantId = (int) TenantContext::getTenantId();
        $stats = $transferCoordinator->batchDetectAndPair($tenantId, $user);

        return response()->json([
            'message' => 'Proses deteksi dan pemasangan transfer selesai.',
            'data' => $stats,
        ], 200);
    }

    /**
     * Returns paginated transactions in the Transfer Review Queue.
     */
    public function transferReviewQueue(
        Request $request,
        BankTransferPostingCoordinator $transferCoordinator
    ): JsonResponse {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.view')) {
            return response()->json(['message' => 'Akses ditolak: Anda memerlukan izin journal.view.'], 403);
        }

        $tenantId = (int) TenantContext::getTenantId();
        $perPage = min((int) ($request->query('per_page', 15)), 100);

        $paginated = $transferCoordinator->getTransferReviewQueueQuery($tenantId)
            ->with(['bankTransaction.bankAccount', 'pairedTransaction.bankTransaction.bankAccount'])
            ->paginate($perPage);

        return response()->json($paginated, 200);
    }

    /**
     * Returns paginated transactions in the Unapplied Cash Queue.
     */
    public function unappliedCashQueue(
        Request $request,
        UnappliedCashHandler $unappliedCashHandler
    ): JsonResponse {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.view')) {
            return response()->json(['message' => 'Akses ditolak: Anda memerlukan izin journal.view.'], 403);
        }

        $tenantId = (int) TenantContext::getTenantId();
        $perPage = min((int) ($request->query('per_page', 15)), 100);

        $paginated = $unappliedCashHandler->getUnappliedCashQueueQuery($tenantId)
            ->with(['bankTransaction.bankAccount', 'counterparty'])
            ->paginate($perPage);

        return response()->json($paginated, 200);
    }

    /**
     * Resolves an unapplied cash transaction by a human reviewer.
     */
    public function resolveUnappliedCash(
        Request $request,
        int $id,
        UnappliedCashHandler $unappliedCashHandler
    ): JsonResponse {
        $user = $request->user();
        if (! $this->hasPermission($user, 'journal.review') && ! $this->hasPermission($user, 'journal.approve')) {
            return response()->json(['message' => 'Akses ditolak: Anda memerlukan izin journal.review atau journal.approve.'], 403);
        }

        $validated = $request->validate([
            'target_account_id' => ['nullable', 'integer'],
            'counterparty_id' => ['nullable', 'integer'],
            'transaction_type' => ['nullable', 'string', 'in:DIRECT_INCOME,RECEIVABLE_PAYMENT,UNAPPLIED_CASH,OPERATING_EXPENSE,ALLOCATION_DISBURSEMENT,BANK_INTEREST,BANK_FEE_TAX'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $resolvedTx = $unappliedCashHandler->resolve($id, $validated, $user);

        return response()->json([
            'message' => 'Transaksi titipan kas (unapplied cash) berhasil diselesaikan.',
            'data' => $resolvedTx,
        ], 200);
    }

    /**
     * Checks if a user has a specific permission safely.
     */
    protected function hasPermission(User $user, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission) || $user->can($permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
