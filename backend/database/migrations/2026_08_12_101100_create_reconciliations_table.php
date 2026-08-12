<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->string('reconciliation_number', 50); // REC-2026-00001
            $table->date('reconciliation_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('bank_balance', 18, 2);        // from bank statement
            $table->decimal('book_balance', 18, 2);        // from ledger
            $table->decimal('difference', 18, 2);          // bank_balance - book_balance
            $table->string('status', 20)->default('draft'); // draft, in_progress, completed
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'reconciliation_number']);
            $table->index(['organization_id', 'bank_account_id', 'status']);
            $table->index(['organization_id', 'fiscal_period_id']);
        });

        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->constrained('reconciliations')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            /**
             * Reconciliation Levels (as per TRANSACTION_RULEBOOK):
             * L1 = Exact Match      (same date, amount, fingerprint)
             * L2 = Date Window Match (same amount, different date within N days)
             * L3 = Aggregate Match  (multiple bank txns = 1 journal or vice versa)
             * L4 = Manual Force Match (human-confirmed match with evidence)
             */
            $table->string('match_level', 5); // L1, L2, L3, L4
            $table->string('match_status', 20)->default('matched'); // matched, disputed, excluded
            $table->decimal('amount', 18, 2);
            $table->text('evidence')->nullable(); // for L4 manual matches
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'reconciliation_id']);
            $table->index(['organization_id', 'bank_transaction_id']);
            $table->index(['organization_id', 'match_level', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliations');
    }
};
