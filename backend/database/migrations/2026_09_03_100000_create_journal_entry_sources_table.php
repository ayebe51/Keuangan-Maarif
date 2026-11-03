<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->string('source_type', 50)->default('business_transaction');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->string('leg_role', 30)->default('primary');
            $table->timestamps();

            // Unique canonical source constraint: One source entity in a tenant maps to exactly one journal entry
            $table->unique(['organization_id', 'source_type', 'source_id'], 'je_sources_org_type_id_unique');
            $table->index(['organization_id', 'journal_entry_id'], 'je_sources_org_je_idx');
            $table->index(['organization_id', 'source_type', 'source_id'], 'je_sources_org_source_idx');
            $table->index(['organization_id', 'business_transaction_id'], 'je_sources_org_bt_idx');
        });

        Schema::table('business_transactions', function (Blueprint $table) {
            $table->foreignId('posted_journal_entry_id')->nullable()->after('status')
                ->constrained('journal_entries')->nullOnDelete();
            $table->index(['organization_id', 'posted_journal_entry_id'], 'biz_tx_org_posted_je_idx');
        });

        // PostgreSQL specific check constraints
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE journal_entry_sources ADD CONSTRAINT chk_je_sources_leg_role CHECK (leg_role IN ('primary', 'secondary', 'transfer_source', 'transfer_destination', 'reversal_source'))");
            DB::statement("ALTER TABLE journal_entry_sources ADD CONSTRAINT chk_je_sources_type CHECK (source_type IN ('business_transaction', 'reversal'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE journal_entry_sources DROP CONSTRAINT IF EXISTS chk_je_sources_leg_role');
            DB::statement('ALTER TABLE journal_entry_sources DROP CONSTRAINT IF EXISTS chk_je_sources_type');
        }

        Schema::table('business_transactions', function (Blueprint $table) {
            $table->dropForeign(['posted_journal_entry_id']);
            $table->dropIndex('biz_tx_org_posted_je_idx');
            $table->dropColumn('posted_journal_entry_id');
        });

        Schema::dropIfExists('journal_entry_sources');
    }
};
