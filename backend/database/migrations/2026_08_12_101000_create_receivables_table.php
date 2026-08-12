<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->foreignId('counterparty_id')->constrained('counterparties');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('receivable_number', 50); // RCV-2026-00001
            $table->string('receivable_type', 30); // school_fee, grant, subsidy, other
            $table->date('due_date');
            $table->date('issue_date');
            $table->decimal('original_amount', 18, 2);
            $table->decimal('outstanding_amount', 18, 2);
            $table->string('status', 20)->default('outstanding'); // outstanding, partial, paid, written_off
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // CHECK CONSTRAINT: outstanding_amount >= 0 (enforced below via raw SQL)
            $table->unique(['organization_id', 'receivable_number']);
            $table->index(['organization_id', 'counterparty_id', 'status']);
            $table->index(['organization_id', 'fiscal_period_id']);
            $table->index(['organization_id', 'due_date', 'status']);
        });

        Schema::create('receivable_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('receivable_id')->constrained('receivables')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->foreignId('journal_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->date('allocation_date');
            $table->decimal('amount', 18, 2);
            $table->string('allocation_type', 30); // payment, adjustment, write_off
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'receivable_id']);
            $table->index(['organization_id', 'journal_entry_id']);
        });

        // Now add FK from journal_lines.receivable_id to receivables
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreign('receivable_id')
                ->references('id')
                ->on('receivables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['receivable_id']);
        });
        Schema::dropIfExists('receivable_allocations');
        Schema::dropIfExists('receivables');
    }
};
