<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add DB-level CHECK constraints that enforce accounting invariants.
     *
     * These cannot be expressed via Laravel's Schema Builder but MUST be
     * enforced at the database level to prevent bad data from any path.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // journal_lines: debit and credit >= 0
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_debit_non_negative CHECK (debit >= 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_credit_non_negative CHECK (credit >= 0)');
        // journal_lines: exactly one of debit or credit must be > 0
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_one_side_only CHECK ((debit > 0 AND credit = 0) OR (debit = 0 AND credit > 0))');

        // receivables: outstanding_amount >= 0
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT chk_receivables_outstanding_non_negative CHECK (outstanding_amount >= 0)');
        // receivables: outstanding_amount <= original_amount
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT chk_receivables_outstanding_lte_original CHECK (outstanding_amount <= original_amount)');

        // bank_transactions: amount > 0
        DB::statement('ALTER TABLE bank_transactions ADD CONSTRAINT chk_bank_transactions_amount_positive CHECK (amount > 0)');
        // bank_transactions: direction must be IN or OUT
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT chk_bank_transactions_direction CHECK (direction IN ('IN', 'OUT'))");

        // opening_balance_sources: amount > 0
        DB::statement('ALTER TABLE opening_balance_sources ADD CONSTRAINT chk_obs_amount_positive CHECK (amount > 0)');
        // opening_balance_sources: direction must be debit or credit
        DB::statement("ALTER TABLE opening_balance_sources ADD CONSTRAINT chk_obs_direction CHECK (direction IN ('debit', 'credit'))");

        // fund_allocations: amounts non-negative
        DB::statement('ALTER TABLE fund_allocations ADD CONSTRAINT chk_fund_alloc_amounts_non_neg CHECK (allocated_amount >= 0 AND disbursed_amount >= 0 AND returned_amount >= 0)');
        DB::statement('ALTER TABLE fund_allocations ADD CONSTRAINT chk_fund_alloc_disbursed_lte_allocated CHECK (disbursed_amount <= allocated_amount)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS chk_journal_lines_debit_non_negative');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS chk_journal_lines_credit_non_negative');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS chk_journal_lines_one_side_only');
        DB::statement('ALTER TABLE receivables DROP CONSTRAINT IF EXISTS chk_receivables_outstanding_non_negative');
        DB::statement('ALTER TABLE receivables DROP CONSTRAINT IF EXISTS chk_receivables_outstanding_lte_original');
        DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS chk_bank_transactions_amount_positive');
        DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS chk_bank_transactions_direction');
        DB::statement('ALTER TABLE opening_balance_sources DROP CONSTRAINT IF EXISTS chk_obs_amount_positive');
        DB::statement('ALTER TABLE opening_balance_sources DROP CONSTRAINT IF EXISTS chk_obs_direction');
        DB::statement('ALTER TABLE fund_allocations DROP CONSTRAINT IF EXISTS chk_fund_alloc_amounts_non_neg');
        DB::statement('ALTER TABLE fund_allocations DROP CONSTRAINT IF EXISTS chk_fund_alloc_disbursed_lte_allocated');
    }
};
