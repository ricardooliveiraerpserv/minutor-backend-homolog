<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_messages', function (Blueprint $table) {
            $table->string('visibility')->default('internal')->after('priority');
        });

        Schema::create('project_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('project_messages')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_message_attachments');
        Schema::table('project_messages', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
