<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_name', 200);
            $table->string('branch', 100)->nullable();
            $table->string('currency', 3)->default('IDR');
            // INVARIANT: NO opening_balance here.
            // Opening balance MUST flow through OpeningBalanceSource -> JournalEntry -> JournalLine
            $table->string('type', 30)->default('current'); // current, savings, giro
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'account_number']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
