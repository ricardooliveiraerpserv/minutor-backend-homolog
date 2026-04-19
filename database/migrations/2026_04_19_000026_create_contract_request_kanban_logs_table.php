<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_request_kanban_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_column')->nullable();
            $table->string('to_column');
            $table->foreignId('moved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_request_kanban_logs');
    }
};
