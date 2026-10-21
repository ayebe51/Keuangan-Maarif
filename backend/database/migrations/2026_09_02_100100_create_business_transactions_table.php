<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('transaction_number', 100);
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('transaction_type', 50); // RECEIVABLE_PAYMENT, DIRECT_INCOME, BANK_INTEREST, BANK_FEE_TAX, etc.
            $table->string('direction', 20); // IN, OUT, TRANSFER
            $table->decimal('amount', 18, 2);
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('target_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('transaction_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->unsignedBigInteger('paired_business_transaction_id')->nullable();
            $table->string('classification_status', 50)->default('unclassified'); // unclassified, suggested, classified, reviewed, approved, rejected
            $table->string('classification_method', 50)->nullable(); // EXPLICIT_MAPPING, INVOICE_REFERENCE, LEGACY_CODE, DETERMINISTIC_RULE, etc.
            $table->decimal('classification_confidence', 4, 2)->nullable();
            $table->boolean('is_period_locked')->default(false);
            $table->boolean('auto_post_eligible')->default(false);
            $table->text('description');
            $table->text('notes')->nullable();
            $table->string('status', 50)->default('draft'); // draft, approved, posted, rejected
            $table->timestamps();

            $table->unique(['organization_id', 'transaction_number'], 'biz_tx_org_number_unique');
            $table->index(['organization_id', 'bank_transaction_id'], 'biz_tx_org_bank_tx_idx');
            $table->index(['organization_id', 'classification_status'], 'biz_tx_org_clf_status_idx');
            $table->index(['organization_id', 'status'], 'biz_tx_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_transactions');
    }
};
