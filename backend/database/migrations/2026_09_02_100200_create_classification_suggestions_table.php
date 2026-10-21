<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->constrained('bank_transactions')->cascadeOnDelete();
            $table->foreignId('business_transaction_id')->nullable()->constrained('business_transactions')->nullOnDelete();
            $table->string('suggested_type', 50); // RECEIVABLE_PAYMENT, DIRECT_INCOME, BANK_INTEREST, BANK_FEE_TAX, etc.
            $table->foreignId('suggested_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('suggested_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->foreignId('suggested_counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->decimal('confidence', 4, 2);
            $table->text('evidence');
            $table->text('explanation');
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('pending'); // pending, accepted, rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'bank_transaction_id'], 'clf_sug_org_bank_tx_idx');
            $table->index(['organization_id', 'status'], 'clf_sug_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_suggestions');
    }
};
