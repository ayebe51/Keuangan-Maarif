<?php

namespace App\Http\Controllers\Api;

use App\Domain\Classification\Models\BusinessTransaction;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = BusinessTransaction::where('organization_id', $tenantId)
            ->with(['bankTransaction', 'counterparty', 'targetAccount', 'transactionCategory', 'fund']);

        if ($request->filled('classification_status')) {
            $query->where('classification_status', $request->query('classification_status'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->query('transaction_type'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', strtoupper($request->query('direction')));
        }

        if ($request->has('counterparty_id')) {
            $query->where('counterparty_id', $request->query('counterparty_id'));
        }

        if ($request->filled('start_date')) {
            $query->where('transaction_date', '>=', $request->query('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->where('transaction_date', '<=', $request->query('end_date'));
        }

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $transactions = $query->orderBy('transaction_date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($transactions);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
