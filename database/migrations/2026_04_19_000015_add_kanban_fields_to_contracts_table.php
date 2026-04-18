<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adicionar campo kanban_status como string (mais flexível que enum para evolução)
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('kanban_status')->default('novo')->after('status');
            $table->unsignedBigInteger('kanban_coordinator_id')->nullable()->after('kanban_status');
            $table->integer('kanban_order')->default(0)->after('kanban_coordinator_id');
            $table->foreign('kanban_coordinator_id')->references('id')->on('users')->nullOnDelete();
        });

        // Criar tabela de log de movimentações do kanban
        Schema::create('contract_kanban_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('from_column')->nullable();
            $table->string('to_column');
            $table->foreignId('moved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_kanban_logs');
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['kanban_coordinator_id']);
            $table->dropColumn(['kanban_status', 'kanban_coordinator_id', 'kanban_order']);
        });
    }
};
