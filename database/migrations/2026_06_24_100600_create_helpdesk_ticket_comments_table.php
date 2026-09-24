<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Interações do chamado (respostas/notas).
 *  - visibility 'internal' → nota interna (não aparece no Portal do Cliente).
 *  - visibility 'customer' → resposta visível ao cliente.
 *  - is_system → linha gerada pelo sistema (mudança de status, atribuição, etc.).
 * Autor pode ser usuário interno (author_user_id) OU contato do cliente (author_contact_id).
 * Anexos da interação vão pela Attachment Engine global (não há coluna de arquivo aqui).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_ticket_comments')) {
            return;
        }
        Schema::create('helpdesk_ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            $table->text('body');
            $table->string('visibility', 12)->default('internal'); // internal | customer
            $table->boolean('is_system')->default(false);
            $table->string('channel', 20)->nullable();             // portal | email | telefone | interno
            $table->timestamps();
            $table->softDeletes();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ticket_comments');
    }
};
