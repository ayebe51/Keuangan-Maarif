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
