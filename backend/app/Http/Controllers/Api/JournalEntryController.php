<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Organization\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function __construct(
        protected JournalPostingService $postingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = JournalEntry::where('organization_id', $tenantId)
            ->with(['lines.account', 'postedBy', 'createdBy']);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('entry_type')) {
            $query->where('entry_type', $request->query('entry_type'));
        }

        if ($request->has('fiscal_period_id')) {
            $query->where('fiscal_period_id', $request->query('fiscal_period_id'));
        }

        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->paginate(20);

        return response()->json($entries);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $entry = JournalEntry::where('organization_id', $tenantId)
            ->with(['lines.account', 'fiscalPeriod', 'postedBy', 'createdBy', 'reversedEntry'])
            ->findOrFail($id);

        return response()->json(['data' => $entry]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'entry_date' => ['required', 'date'],
            'entry_type' => ['nullable', 'string', 'in:opening_balance,payment,receipt,adjustment,reversal'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
            'lines.*.bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'lines.*.counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'lines.*.fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'lines.*.receivable_id' => ['nullable', 'integer'],
        ]);

        $validated['organization_id'] = $tenantId;

        $entry = $this->postingService->createDraft($validated, $request->user());

        return response()->json([
            'message' => 'Draft jurnal berhasil dibuat.',
            'data' => $entry,
        ], 201);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $entry = JournalEntry::where('organization_id', $tenantId)->findOrFail($id);

        $posted = $this->postingService->post($entry, $request->user());

        return response()->json([
            'message' => 'Jurnal berhasil diposting ke buku besar.',
            'data' => $posted,
        ]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $entry = JournalEntry::where('organization_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'reversal_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $reversal = $this->postingService->reverse(
            originalEntry: $entry,
            user: $request->user(),
            reversalDate: $validated['reversal_date'] ?? null,
            reason: $validated['reason']
        );

        return response()->json([
            'message' => 'Jurnal berhasil dibalik (reversed).',
            'data' => $reversal,
        ]);
    }
}
