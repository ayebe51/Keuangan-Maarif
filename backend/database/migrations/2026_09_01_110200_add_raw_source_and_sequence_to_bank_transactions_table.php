<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('raw_source_id')->nullable()->after('bank_import_id')->constrained('bank_raw_sources')->nullOnDelete();
            $table->integer('row_sequence')->default(1)->after('raw_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['raw_source_id']);
            $table->dropColumn(['raw_source_id', 'row_sequence']);
        });
    }
};
