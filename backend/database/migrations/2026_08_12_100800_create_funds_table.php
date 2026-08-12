<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 200);
            // fund_type: restricted, unrestricted, temporarily_restricted
            $table->string('fund_type', 30)->default('restricted');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active'); // active, completed, cancelled
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'fund_type']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('fund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('fund_id')->constrained('funds')->cascadeOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->string('description', 300)->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('disbursed_amount', 18, 2)->default(0);
            $table->decimal('returned_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('active'); // active, completed, cancelled
            $table->timestamps();

            $table->index(['organization_id', 'fund_id']);
            $table->index(['organization_id', 'fiscal_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_allocations');
        Schema::dropIfExists('funds');
    }
};
