<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_imports', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('filename');
            $table->string('file_path', 500)->nullable()->after('file_hash');
            $table->string('mapping_version', 50)->default('bri_v1')->after('format');
            $table->boolean('has_discrepancies')->default(false)->after('error_log');

            $table->index(['organization_id', 'bank_account_id', 'file_hash'], 'bank_imports_org_account_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_imports', function (Blueprint $table) {
            $table->dropIndex('bank_imports_org_account_hash_idx');
            $table->dropColumn(['file_hash', 'file_path', 'mapping_version', 'has_discrepancies']);
        });
    }
};
