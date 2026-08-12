<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Opening Balance Source: records the intent to establish an opening balance.
         *
         * ARCHITECTURE: OpeningBalanceSource does NOT have a direct FK to journal_entry_id
         * in the migration to avoid circular dependency. The link to the journal is tracked
         * via the journal_entry_id column which is nullable and set AFTER the journal is created
         * by the OpeningBalanceService.
         *
         * Flow: OpeningBalanceSource -> OpeningBalanceService -> JournalEntry -> JournalLine -> Ledger
         */
        Schema::create('opening_balance_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->date('effective_date');
            // amount in DECIMAL(18,2) — NEVER float
            $table->decimal('amount', 18, 2);
            // direction: debit or credit (as per accounting normal balance)
            $table->string('direction', 10); // debit, credit
            $table->text('description')->nullable();
            // status: pending (not yet journaled), posted (journal created)
            $table->string('status', 20)->default('pending'); // pending, posted, error
            // journal_entry_id is set AFTER journal creation (no migration circular FK)
            // FK constraint added in separate migration after journal_entries table exists
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            // DEV/TEST FIXTURE MARKER — production opening balance via OpeningBalanceService only
            $table->boolean('is_fixture')->default(false);
            $table->string('fixture_note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'fiscal_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_sources');
    }
};
