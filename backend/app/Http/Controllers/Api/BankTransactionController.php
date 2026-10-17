<?php

namespace App\Http\Controllers\Api;

use App\Domain\Bank\Models\BankTransaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransactionController extends Controller
{
    /**
     * List normalized bank transactions.
     * GET /api/v1/bank-transactions
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        $query = BankTransaction::where('organization_id', $orgId)
            ->with([
                'bankAccount:id,bank_name,account_number,account_name',
                'counterparty:id,name,role,code',
                'rawSource:id,source_file,source_sheet,source_row',
            ]);

        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->input('bank_account_id'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', strtoupper($request->input('direction')));
        }

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->input('status')));
        }

        if ($request->filled('counterparty_id')) {
            $query->where('counterparty_id', (int) $request->input('counterparty_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('transaction_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('transaction_date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', $search)
                  ->orWhere('reference_number', 'like', $search)
                  ->orWhere('raw_counterparty_name', 'like', $search);
            });
        }

        $transactions = $query->orderBy('transaction_date')
            ->orderBy('id')
            ->paginate($request->input('per_page', 25));

        return response()->json($transactions);
    }

    /**
     * Show single bank transaction with audit drill-down.
     * GET /api/v1/bank-transactions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        $tx = BankTransaction::where('organization_id', $orgId)
            ->with([
                'bankAccount',
                'counterparty',
                'rawSource',
                'bankImport:id,filename,file_hash,format,created_at',
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => $tx,
        ]);
    }
}
