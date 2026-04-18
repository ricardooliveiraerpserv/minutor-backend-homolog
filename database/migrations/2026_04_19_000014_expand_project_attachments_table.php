<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete()->after('contract_attachment_id');
            $table->enum('type', ['proposta', 'contrato', 'logo', 'outro'])->nullable()->after('uploaded_by_id');
            $table->string('path')->nullable()->after('type');
            $table->string('original_name')->nullable()->after('path');
            $table->string('mime_type')->nullable()->after('original_name');
            $table->unsignedBigInteger('size')->nullable()->after('mime_type');

            // contract_attachment_id can now be null (native project uploads)
            $table->foreignId('contract_attachment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_attachments', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by_id']);
            $table->dropColumn(['uploaded_by_id', 'type', 'path', 'original_name', 'mime_type', 'size']);
        });
    }
};
