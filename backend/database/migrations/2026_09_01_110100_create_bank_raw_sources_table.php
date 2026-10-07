<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_raw_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('bank_import_id')->constrained('bank_imports')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->string('source_file', 300);
            $table->string('source_sheet', 100)->nullable();
            $table->integer('source_row');
            $table->json('raw_data');
            $table->string('row_fingerprint', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending, normalized, duplicate, error
            $table->string('error_code', 50)->nullable(); // INVALID_DATE, INVALID_AMOUNT, BALANCE_DISCREPANCY, etc.
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'bank_import_id']);
            $table->index(['organization_id', 'row_fingerprint']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_raw_sources');
    }
};
