<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->string('entry_number', 50); // auto-generated: JE-2026-00001
            $table->date('entry_date');
            $table->string('entry_type', 30); // opening_balance, payment, receipt, adjustment, reversal
            $table->string('reference', 100)->nullable();
            $table->text('description');
            // status: draft -> posted (immutable once posted)
            $table->string('status', 20)->default('draft'); // draft, posted, reversed
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            // If this is a reversal, reference the original entry
            $table->foreignId('reversed_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // INVARIANT: once posted, journal_entries MUST NOT be updated or deleted
            $table->unique(['organization_id', 'entry_number']);
            $table->index(['organization_id', 'fiscal_period_id', 'status']);
            $table->index(['organization_id', 'entry_date']);
            $table->index(['organization_id', 'entry_type']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->integer('line_number');
            $table->foreignId('account_id')->constrained('accounts');
            // INVARIANT: DECIMAL(18,2) — NEVER float
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description', 300)->nullable();

            // DIMENSIONS — conditional use (NOT dumping ground):
            // bank_account_id: ONLY when account is Cash/Bank type
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            // counterparty_id: REQUIRED for AR/AP/Revenue accounts
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            // fund_id: REQUIRED when fund is restricted allocation
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            // receivable_id: ONLY for AR recognition/settlement lines — set after receivables table created
            $table->unsignedBigInteger('receivable_id')->nullable();

            $table->timestamps();

            // DB CHECK CONSTRAINTS — enforced at database level
            // debit and credit must be non-negative
            // exactly one of debit or credit must be > 0 per line
            $table->index(['journal_entry_id', 'line_number']);
            $table->index(['organization_id', 'account_id']);
            $table->index(['organization_id', 'bank_account_id']);
            $table->index(['organization_id', 'counterparty_id']);
            $table->index(['organization_id', 'fund_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
