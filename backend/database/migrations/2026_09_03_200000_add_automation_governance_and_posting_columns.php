<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Governance & Limit columns on classification_rules
        Schema::table('classification_rules', function (Blueprint $table) {
            $table->integer('version')->default(1)->after('priority');
            $table->foreignId('created_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->date('effective_from')->nullable()->after('approved_at');
            $table->date('effective_to')->nullable()->after('effective_from');
            // INVARIANT: default 0.00 (disabled by default until explicitly configured & approved)
            $table->decimal('max_auto_post_amount', 18, 2)->default(0.00)->after('auto_post');

            $table->index(['organization_id', 'approved_by'], 'clf_rules_org_approved_by_idx');
        });

        // 2. Posting tracking, transfer status & rule snapshot on business_transactions
        Schema::table('business_transactions', function (Blueprint $table) {
            $table->string('transfer_pairing_status', 30)->default('NOT_APPLICABLE')->after('paired_business_transaction_id');
            $table->jsonb('rule_snapshot')->nullable()->after('classification_confidence');
            $table->timestamp('posted_at')->nullable()->after('status');
            $table->foreignId('posted_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->jsonb('posting_error')->nullable()->after('notes');

            $table->index(['organization_id', 'transfer_pairing_status'], 'biz_tx_org_trf_status_idx');
        });

        // 3. Automated rule authorization reference on journal_entries
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('posted_by_rule_id')->nullable()->after('posted_by')
                ->constrained('classification_rules')->restrictOnDelete();

            $table->index(['organization_id', 'posted_by_rule_id'], 'je_org_posted_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['posted_by_rule_id']);
            $table->dropIndex('je_org_posted_rule_idx');
            $table->dropColumn('posted_by_rule_id');
        });

        Schema::table('business_transactions', function (Blueprint $table) {
            $table->dropForeign(['posted_by']);
            $table->dropIndex('biz_tx_org_trf_status_idx');
            $table->dropColumn([
                'transfer_pairing_status',
                'rule_snapshot',
                'posted_at',
                'posted_by',
                'posting_error',
            ]);
        });

        Schema::table('classification_rules', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['approved_by']);
            $table->dropIndex('clf_rules_org_approved_by_idx');
            $table->dropColumn([
                'version',
                'created_by',
                'approved_by',
                'approved_at',
                'effective_from',
                'effective_to',
                'max_auto_post_amount',
            ]);
        });
    }
};
