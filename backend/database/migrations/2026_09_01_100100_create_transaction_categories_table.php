<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->string('direction', 20); // IN, OUT, TRANSFER
            $table->foreignId('default_debit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('default_credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'tx_cat_org_code_unique');
            $table->index(['organization_id', 'direction'], 'tx_cat_org_direction_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_categories');
    }
};
