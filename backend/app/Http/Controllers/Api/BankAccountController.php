<?php

namespace App\Http\Controllers\Api;

use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Services\BankAccountService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function __construct(
        protected BankAccountService $bankAccountService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = BankAccount::where('organization_id', $tenantId)->with(['account']);

        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->orderBy('bank_name')->orderBy('account_number')->get();

        return response()->json(['data' => $accounts]);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $account = BankAccount::where('organization_id', $tenantId)
            ->with(['account', 'openingBalanceSources'])
            ->findOrFail($id);

        return response()->json(['data' => $account]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_name' => ['required', 'string', 'max:150'],
            'branch' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'type' => ['nullable', 'string', 'in:giro,savings,current,cash'],
            'is_active' => ['nullable', 'boolean'],
            'opening_balance' => ['prohibited'], // INVARIANT: forbid direct opening_balance
        ], [
            'opening_balance.prohibited' => 'Opening balance tidak boleh diisi langsung pada rekening bank. Saldo awal wajib melalui Opening Balance Journal.',
        ]);

        $validated['organization_id'] = $tenantId;

        $bankAccount = $this->bankAccountService->create($validated, $request->user());

        return response()->json([
            'message' => 'Rekening bank berhasil didaftarkan.',
            'data' => $bankAccount->load('account'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $bankAccount = BankAccount::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'bank_name' => ['sometimes', 'required', 'string', 'max:100'],
            'account_number' => ['sometimes', 'required', 'string', 'max:50'],
            'account_name' => ['sometimes', 'required', 'string', 'max:150'],
            'branch' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'type' => ['nullable', 'string', 'in:giro,savings,current,cash'],
            'is_active' => ['nullable', 'boolean'],
            'opening_balance' => ['prohibited'],
        ]);

        $updated = $this->bankAccountService->update($bankAccount, $validated, $request->user());

        return response()->json([
            'message' => 'Rekening bank berhasil diperbarui.',
            'data' => $updated->load('account'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $bankAccount = BankAccount::where('organization_id', $tenantId)->findOrFail($id);

        $this->bankAccountService->delete($bankAccount, $request->user());

        return response()->json([
            'message' => 'Rekening bank berhasil dihapus.',
        ]);
    }
}
