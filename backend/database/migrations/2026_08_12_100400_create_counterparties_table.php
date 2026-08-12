<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // role: payer, payee, school, bank, vendor, donor, government
            $table->string('role', 50);
            $table->string('code', 50)->nullable();
            $table->string('name', 200);
            $table->string('npwp', 30)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'counterparties_org_code_unique');
            $table->index(['organization_id', 'role']);
            $table->index(['organization_id', 'name']);
        });

        Schema::create('counterparty_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('counterparty_id')->constrained('counterparties')->cascadeOnDelete();
            $table->string('alias_name', 200);
            $table->string('source', 50)->nullable(); // bank_import, manual
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'alias_name']);
            $table->index('counterparty_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_aliases');
        Schema::dropIfExists('counterparties');
    }
};
