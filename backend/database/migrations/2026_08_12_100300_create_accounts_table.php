<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            // account_type: asset, liability, equity, revenue, expense
            $table->string('account_type', 30);
            // normal_balance: debit, credit
            $table->string('normal_balance', 10);
            // code is VARCHAR — not integer, supports "1100", "1100.1", "1100.1.1"
            $table->string('code', 20);
            $table->string('name', 200);
            $table->text('description')->nullable();
            // only postable (leaf) accounts can have journal lines
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'account_type']);
            $table->index(['organization_id', 'is_postable', 'is_active']);
            $table->index('parent_id');
        });

        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('mapping_type', 50); // transaction_type, report_category
            $table->string('mapping_key', 100);
            $table->string('mapping_value', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'mapping_type', 'mapping_key', 'account_id']);
            $table->index(['organization_id', 'mapping_type', 'mapping_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
        Schema::dropIfExists('accounts');
    }
};
