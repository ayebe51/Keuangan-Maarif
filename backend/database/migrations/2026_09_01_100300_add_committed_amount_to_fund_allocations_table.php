<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_allocations', function (Blueprint $table) {
            $table->decimal('committed_amount', 18, 2)->default(0)->after('allocated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('fund_allocations', function (Blueprint $table) {
            $table->dropColumn('committed_amount');
        });
    }
};
