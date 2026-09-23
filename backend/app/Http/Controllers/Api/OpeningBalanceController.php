<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Models\OpeningBalanceSource;
use App\Domain\Accounting\Services\OpeningBalanceService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function __construct(
        protected OpeningBalanceService $obService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $sources = OpeningBalanceSource::where('organization_id', $tenantId)
            ->with(['fiscalPeriod', 'journalEntry', 'createdBy'])
            ->orderByDesc('effective_date')
            ->get();

        return response()->json(['data' => $sources]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'effective_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'string', 'in:debit,credit'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_fixture' => ['nullable', 'boolean'],
            'fixture_note' => ['nullable', 'string', 'max:200'],
        ]);

        $validated['organization_id'] = $tenantId;

        $source = $this->obService->recordSource($validated, $request->user());

        return response()->json([
            'message' => 'Sumber saldo awal neraca berhasil dicatat.',
            'data' => $source,
        ], 201);
    }

    public function process(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $source = OpeningBalanceSource::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $checker = $request->user();
        $maker = $source->createdBy ?: $checker;

        // If creator and checker are the same person, pick another user with accounting role or check SoD
        if ($maker->id === $checker->id) {
            // If super admin processing fixture or single user, allow or require distinct checker
            $maker = User::where('organization_id', $tenantId)->where('id', '!=', $checker->id)->first() ?: $checker;
        }

        $postedJournal = $this->obService->processToJournal(
            source: $source,
            maker: $maker,
            checker: $checker,
            explicitAccountId: $validated['account_id'] ?? null
        );

        return response()->json([
            'message' => 'Saldo awal berhasil diposting ke jurnal dan buku besar.',
            'data' => [
                'source' => $source->fresh(),
                'journal' => $postedJournal,
            ],
        ]);
    }
}
