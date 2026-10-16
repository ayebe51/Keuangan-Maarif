<?php

namespace App\Domain\Bank\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Bank\Enums\BankImportStatus;
use App\Domain\Bank\Enums\BankTransactionStatus;
use App\Domain\Bank\Exceptions\DuplicateImportException;
use App\Domain\Bank\Exceptions\InvalidBankStatementFormatException;
use App\Domain\Bank\Models\BankAccount;
use App\Domain\Bank\Models\BankImport;
use App\Domain\Bank\Models\BankRawSource;
use App\Domain\Bank\Models\BankTransaction;
use App\Domain\Bank\Parsers\BankStatementParserFactory;
use App\Domain\Organization\Exceptions\CrossTenantViolationException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BankImportService
{
    public function __construct(
        protected BankTransactionNormalizer $normalizer,
        protected RunningBalanceValidator $balanceValidator
    ) {}

    /**
     * Ingest a bank statement file with raw preservation, idempotency, and normalization.
     *
     * @param UploadedFile|string $file
     * @param int $bankAccountId
     * @param User $user
     * @param array $options
     * @return BankImport
     *
     * @throws DuplicateImportException
     * @throws InvalidBankStatementFormatException
     * @throws CrossTenantViolationException
     */
    public function importFile(
        UploadedFile|string $file,
        int $bankAccountId,
        User $user,
        array $options = []
    ): BankImport {
        $orgId = (int) $user->organization_id;

        // Step 1: Verify BankAccount and tenant boundaries
        $bankAccount = BankAccount::withoutGlobalScopes()->findOrFail($bankAccountId);
        if ((int) $bankAccount->organization_id !== $orgId) {
            throw new CrossTenantViolationException("Unauthorized access to bank account outside your organization.");
        }

        // Step 2: Resolve real path, filename, and extension
        if ($file instanceof UploadedFile) {
            $realPath = $file->getRealPath();
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());
        } else {
            $realPath = $file;
            $originalName = basename($file);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        }

        if (!file_exists($realPath) || !is_readable($realPath)) {
            throw new InvalidBankStatementFormatException($originalName, "File could not be found or read.");
        }

        // Step 3: Compute SHA-256 file hash and verify duplicate file upload
        $fileHash = hash_file('sha256', $realPath);
        $allowDuplicate = !empty($options['allow_duplicate_file']);

        if (!$allowDuplicate) {
            $existing = BankImport::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('bank_account_id', $bankAccountId)
                ->where('file_hash', $fileHash)
                ->where('status', '!=', BankImportStatus::FAILED->value)
                ->first();

            if ($existing) {
                throw new DuplicateImportException($originalName, $fileHash, $bankAccountId);
            }
        }

        // Step 4: Persist physical file in protected storage
        $year = date('Y');
        $storageDir = "bank_imports/{$orgId}/{$year}";
        $storageFilename = "{$fileHash}.{$extension}";
        $storedPath = "{$storageDir}/{$storageFilename}";

        try {
            Storage::disk('local')->put($storedPath, file_get_contents($realPath));
        } catch (Throwable $e) {
            // Non-fatal if storage fails in test environment, but keep path
            $storedPath = null;
        }

        // Step 5: Create BankImport header in processing status
        $mappingVersion = $options['mapping_version'] ?? 'bri_v1';

        $bankImport = BankImport::create([
            'organization_id' => $orgId,
            'bank_account_id' => $bankAccountId,
            'imported_by' => $user->id,
            'filename' => $originalName,
            'file_hash' => $fileHash,
            'file_path' => $storedPath,
            'format' => $extension,
            'mapping_version' => $mappingVersion,
            'status' => BankImportStatus::PROCESSING,
        ]);

        return DB::transaction(function () use ($bankImport, $bankAccount, $realPath, $originalName, $options, $orgId, $bankAccountId, $user) {
            try {
                // Step 6: Parse the file
                $parser = BankStatementParserFactory::createForFile($realPath);
                $parseOptions = array_merge($options, [
                    'account_number' => $bankAccount->account_number,
                    'account_type' => $bankAccount->type,
                ]);

                $rawRows = $parser->parse($realPath, $parseOptions);
                $totalRows = count($rawRows);

                $importedRows = 0;
                $skippedRows = 0;
                $errorRows = 0;
                $normalizedValidRows = [];
                $minDate = null;
                $maxDate = null;

                // Track row occurrences per date to assign collision-free row_sequence
                $dateSequences = [];

                // Step 7: Ingest and process each raw row
                foreach ($rawRows as $idx => $rawRow) {
                    // Raw preservation: always persist raw row first
                    $rawSource = BankRawSource::create([
                        'organization_id' => $orgId,
                        'bank_import_id' => $bankImport->id,
                        'bank_account_id' => $bankAccountId,
                        'source_file' => $rawRow['source_file'] ?? $originalName,
                        'source_sheet' => $rawRow['source_sheet'] ?? null,
                        'source_row' => $rawRow['source_row'] ?? ($idx + 1),
                        'raw_data' => $rawRow['raw_cells'] ?? [],
                        'status' => BankRawSource::STATUS_PENDING,
                    ]);

                    try {
                        // Calculate row sequence for same date
                        $rawDateKey = (string)($rawRow['date_raw'] ?? 'unknown');
                        $dateSequences[$rawDateKey] = ($dateSequences[$rawDateKey] ?? 0) + 1;
                        $rowSequence = $dateSequences[$rawDateKey];

                        $normalized = $this->normalizer->normalize(
                            organizationId: $orgId,
                            bankAccountId: $bankAccountId,
                            rawRow: $rawRow,
                            rowSequence: $rowSequence
                        );

                        $fingerprint = $normalized['fingerprint'];
                        $rawSource->row_fingerprint = $fingerprint;

                        // Step 7a: Idempotency & Zero Financial Duplication Check
                        $exists = BankTransaction::withoutGlobalScopes()
                            ->where('organization_id', $orgId)
                            ->where('fingerprint', $fingerprint)
                            ->exists();

                        if ($exists) {
                            $rawSource->status = BankRawSource::STATUS_DUPLICATE;
                            $rawSource->error_code = 'DUPLICATE_FINGERPRINT';
                            $rawSource->error_message = "Transaction with fingerprint {$fingerprint} already exists.";
                            $rawSource->save();
                            $skippedRows++;
                            continue;
                        }

                        // Step 7b: Create normalized BankTransaction
                        $tx = BankTransaction::create([
                            'organization_id' => $orgId,
                            'bank_account_id' => $bankAccountId,
                            'bank_import_id' => $bankImport->id,
                            'raw_source_id' => $rawSource->id,
                            'row_sequence' => $rowSequence,
                            'transaction_date' => $normalized['date'],
                            'direction' => $normalized['direction'],
                            'amount' => $normalized['amount'],
                            'balance_after' => $normalized['balance_after'],
                            'description' => $normalized['description'],
                            'reference_number' => $normalized['reference_number'],
                            'raw_counterparty_name' => $normalized['raw_counterparty_name'],
                            'counterparty_id' => $normalized['counterparty_id'],
                            'fingerprint' => $fingerprint,
                            'status' => BankTransactionStatus::UNMATCHED,
                        ]);

                        $rawSource->status = BankRawSource::STATUS_NORMALIZED;
                        $rawSource->save();
                        $importedRows++;

                        // Track date range
                        $txDate = $normalized['date'];
                        if ($minDate === null || $txDate < $minDate) $minDate = $txDate;
                        if ($maxDate === null || $txDate > $maxDate) $maxDate = $txDate;

                        // Save for running balance continuity validation
                        $normalizedValidRows[] = [
                            'row_index' => $rawSource->source_row,
                            'raw_source_id' => $rawSource->id,
                            'direction' => $normalized['direction'],
                            'amount' => $normalized['amount'],
                            'balance_after' => $normalized['balance_after'],
                            'date' => $normalized['date'],
                        ];

                    } catch (Throwable $rowEx) {
                        $rawSource->status = BankRawSource::STATUS_ERROR;
                        $rawSource->error_code = 'PARSER_OR_NORMALIZATION_ERROR';
                        $rawSource->error_message = $rowEx->getMessage();
                        $rawSource->save();
                        $errorRows++;
                    }
                }

                // Step 8: Continuous Running Balance Validation (REV3-06)
                $discrepancies = $this->balanceValidator->validate($normalizedValidRows);
                $hasDiscrepancies = !empty($discrepancies);

                if ($hasDiscrepancies) {
                    $bankImport->has_discrepancies = true;
                    // Tag raw sources that have balance discrepancy
                    foreach ($discrepancies as $disc) {
                        BankRawSource::withoutGlobalScopes()
                            ->where('bank_import_id', $bankImport->id)
                            ->where('source_row', $disc['row_index'])
                            ->update([
                                'error_code' => 'BALANCE_DISCREPANCY',
                                'error_message' => $disc['message'],
                            ]);
                    }
                }

                // Step 9: Finalize import batch metadata
                $bankImport->update([
                    'total_rows' => $totalRows,
                    'imported_rows' => $importedRows,
                    'skipped_rows' => $skippedRows,
                    'error_rows' => $errorRows,
                    'period_start' => $minDate,
                    'period_end' => $maxDate,
                    'status' => BankImportStatus::DONE,
                ]);

                // Step 10: Audit Log
                AuditService::log(
                    action: 'BANK_STATEMENT_IMPORTED',
                    modelType: BankImport::class,
                    modelId: $bankImport->id,
                    newValues: [
                        'filename' => $originalName,
                        'file_hash' => $bankImport->file_hash,
                        'total_rows' => $totalRows,
                        'imported_rows' => $importedRows,
                        'skipped_rows' => $skippedRows,
                        'error_rows' => $errorRows,
                        'has_discrepancies' => $hasDiscrepancies,
                    ],
                    userId: $user->id,
                    organizationId: $orgId
                );

                return $bankImport->fresh(['rawSources', 'transactions']);

            } catch (Throwable $e) {
                $bankImport->update([
                    'status' => BankImportStatus::FAILED,
                    'error_log' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }
}
