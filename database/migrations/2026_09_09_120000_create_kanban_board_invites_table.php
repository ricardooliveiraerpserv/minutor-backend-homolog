<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convites do Kanban do Cliente ("Meus Processos").
 * Diferente do acesso direto (kanban_board_members): o convite fica PENDENTE até o
 * usuário aceitar (pelo link do e-mail / notificação). Ao aceitar, ele vira membro
 * do quadro e passa a vê-lo. Serve também como LOG (quem convidou, quando, aceite).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kanban_board_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('kanban_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();       // convidado
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();               // aceite sem depender de ser membro
            $table->string('status', 20)->default('pending');    // pending | accepted
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['board_id', 'user_id']);             // um convite vivo por (quadro, usuário)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_board_invites');
    }
};
