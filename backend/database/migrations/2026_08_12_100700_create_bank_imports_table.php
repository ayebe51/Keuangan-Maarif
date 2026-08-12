<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('imported_by')->constrained('users');
            $table->string('filename', 300);
            $table->string('format', 20); // xlsx, csv, pdf, bni, bri, mandiri
            $table->integer('total_rows')->default(0);
            $table->integer('imported_rows')->default(0);
            $table->integer('skipped_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->string('status', 20)->default('pending'); // pending, processing, done, failed
            $table->text('error_log')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'bank_account_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('bank_import_id')->nullable()->constrained('bank_imports')->nullOnDelete();
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            // BANK SEMANTICS INVARIANT:
            // direction = IN  => Bank CREDIT  => cash received (money coming in)
            // direction = OUT => Bank DEBIT   => cash paid out (money going out)
            // BANK CREDIT ≠ ACCOUNTING CREDIT
            $table->string('direction', 10); // IN, OUT
            $table->decimal('amount', 18, 2); // NEVER float
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('raw_counterparty_name', 300)->nullable();
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            // fingerprint: SHA-256 hash of (bank_account_id + date + direction + amount + description)
            $table->string('fingerprint', 64);
            // status: unmatched, matched, reconciled, excluded
            $table->string('status', 20)->default('unmatched');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Prevent duplicate imports
            $table->unique(['organization_id', 'fingerprint']);
            $table->index(['organization_id', 'bank_account_id', 'transaction_date']);
            $table->index(['organization_id', 'direction', 'status']);
            $table->index(['organization_id', 'counterparty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_imports');
    }
};
