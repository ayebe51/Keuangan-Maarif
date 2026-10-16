<?php

namespace App\Http\Controllers\Api;

use App\Domain\Bank\Models\BankImport;
use App\Domain\Bank\Models\BankRawSource;
use App\Domain\Bank\Services\BankImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankImportController extends Controller
{
    public function __construct(
        protected BankImportService $importService
    ) {}

    /**
     * Upload and ingest a bank statement file.
     * POST /api/v1/bank-imports
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'sheet_name' => ['nullable', 'string', 'max:100'],
            'mapping_version' => ['nullable', 'string', 'max:50'],
            'allow_duplicate_file' => ['nullable', 'boolean'],
        ]);

        $uploadedFile = $request->file('file');
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            return response()->json([
                'message' => "Unsupported file extension '{$extension}'. Only .csv, .txt, and .xlsx files are supported.",
                'error' => 'UNSUPPORTED_BANK_FORMAT',
            ], 415);
        }

        $import = $this->importService->importFile(
            file: $uploadedFile,
            bankAccountId: (int) $request->input('bank_account_id'),
            user: $request->user(),
            options: [
                'sheet_name' => $request->input('sheet_name'),
                'mapping_version' => $request->input('mapping_version'),
                'allow_duplicate_file' => $request->boolean('allow_duplicate_file'),
            ]
        );

        return response()->json([
            'message' => 'Bank statement imported successfully.',
            'data' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'file_hash' => $import->file_hash,
                'format' => $import->format,
                'mapping_version' => $import->mapping_version,
                'total_rows' => $import->total_rows,
                'imported_rows' => $import->imported_rows,
                'skipped_rows' => $import->skipped_rows,
                'error_rows' => $import->error_rows,
                'has_discrepancies' => $import->has_discrepancies,
                'period_start' => $import->period_start?->format('Y-m-d'),
                'period_end' => $import->period_end?->format('Y-m-d'),
                'status' => $import->status->value,
                'created_at' => $import->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * List bank import batches.
     * GET /api/v1/bank-imports
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        $query = BankImport::where('organization_id', $orgId)
            ->with(['bankAccount:id,bank_name,account_number,account_name', 'importedBy:id,name']);

        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->input('bank_account_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $imports = $query->orderByDesc('id')->paginate($request->input('per_page', 15));

        return response()->json($imports);
    }

    /**
     * Show single bank import batch detail.
     * GET /api/v1/bank-imports/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        $import = BankImport::where('organization_id', $orgId)
            ->with(['bankAccount', 'importedBy:id,name'])
            ->findOrFail($id);

        return response()->json([
            'data' => $import,
        ]);
    }

    /**
     * Get raw sources preserved for an import batch.
     * GET /api/v1/bank-imports/{id}/raw-sources
     */
    public function rawSources(Request $request, int $id): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        $import = BankImport::where('organization_id', $orgId)->findOrFail($id);

        $query = BankRawSource::where('organization_id', $orgId)
            ->where('bank_import_id', $import->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $rawSources = $query->orderBy('source_row')->paginate($request->input('per_page', 50));

        return response()->json($rawSources);
    }

    /**
     * Get exception queue (anomalies, duplicates, errors, balance discrepancies).
     * GET /api/v1/bank-imports/{id}/exceptions
     */
    public function exceptions(Request $request, int $id): JsonResponse
    {
        $orgId = (int) $request->user()->organization_id;

        $import = BankImport::where('organization_id', $orgId)->findOrFail($id);

        $exceptions = BankRawSource::where('organization_id', $orgId)
            ->where('bank_import_id', $import->id)
            ->where(function ($q) {
                $q->whereIn('status', [BankRawSource::STATUS_ERROR, BankRawSource::STATUS_DUPLICATE])
                  ->orWhereNotNull('error_code');
            })
            ->orderBy('source_row')
            ->paginate($request->input('per_page', 50));

        return response()->json($exceptions);
    }
}
