<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('classification_status', 50)->default('unclassified')->after('status');
            $table->foreignId('business_transaction_id')->nullable()->after('classification_status')
                ->constrained('business_transactions')->nullOnDelete();

            $table->index(['organization_id', 'classification_status'], 'bank_tx_org_clf_status_idx');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('business_transaction_id')->nullable()->after('fiscal_period_id')
                ->constrained('business_transactions')->nullOnDelete();

            $table->index(['organization_id', 'business_transaction_id'], 'je_org_biz_tx_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['business_transaction_id']);
            $table->dropColumn('business_transaction_id');
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['business_transaction_id']);
            $table->dropIndex('bank_tx_org_clf_status_idx');
            $table->dropColumn(['classification_status', 'business_transaction_id']);
