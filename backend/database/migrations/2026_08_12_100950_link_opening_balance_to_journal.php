<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Now that journal_entries exists, add the FK from opening_balance_sources
        Schema::table('opening_balance_sources', function (Blueprint $table) {
            $table->foreign('journal_entry_id')
                ->references('id')
                ->on('journal_entries')
                ->nullOnDelete();
        });

        // Also add FK from journal_lines to receivables (will be created below)
    }

    public function down(): void
    {
        Schema::table('opening_balance_sources', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
        });
    }
};
