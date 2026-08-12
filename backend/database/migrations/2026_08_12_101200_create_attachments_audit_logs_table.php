<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Polymorphic relation: attachable_type + attachable_id
            $table->morphs('attachable');
            $table->string('filename', 300);
            $table->string('original_name', 300);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size'); // in bytes
            $table->string('disk', 20)->default('local');
            $table->string('path', 500);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'attachable_type', 'attachable_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50); // create, update, delete, login, export, etc.
            $table->string('model_type', 100)->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            // audit_logs NEVER updated or deleted
            $table->index(['organization_id', 'action']);
            $table->index(['organization_id', 'model_type', 'model_id']);
            $table->index(['organization_id', 'user_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('attachments');
    }
};
