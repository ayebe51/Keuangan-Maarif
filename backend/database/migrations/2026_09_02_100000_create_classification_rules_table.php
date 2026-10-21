<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 200);
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('rule_type', 50)->default('KEYWORD'); // KEYWORD, REGEX, EXACT_REFERENCE, COUNTERPARTY
            $table->string('direction', 20)->nullable(); // IN, OUT, TRANSFER, or null for all
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('pattern', 500);
            $table->string('pattern_type', 50)->default('contains'); // contains, exact, regex, starts_with, ends_with
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->string('transaction_type', 50); // RECEIVABLE_PAYMENT, DIRECT_INCOME, BANK_INTEREST, BANK_FEE_TAX, etc.
            $table->foreignId('transaction_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->foreignId('target_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->boolean('auto_post')->default(true);
            $table->decimal('confidence', 4, 2)->default(1.00);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'priority'], 'clf_rules_org_active_prio_idx');
            $table->index(['organization_id', 'direction'], 'clf_rules_org_dir_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_rules');
    }
};
