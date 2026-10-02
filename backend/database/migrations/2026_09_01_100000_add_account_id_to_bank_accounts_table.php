<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('currency')
                ->constrained('accounts')
                ->nullOnDelete();
            
            $table->index(['organization_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropIndex(['organization_id', 'account_id']);
            $table->dropColumn('account_id');
        });
    }
};
