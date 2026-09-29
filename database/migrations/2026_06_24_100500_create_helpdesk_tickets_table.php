<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk — Chamado (ticket). Núcleo do módulo.
 *
 * PRINCÍPIO ARQUITETURAL (base local): todos os vínculos apontam para entidades
 * LOCAIS do Minutor (customers, customer_contacts, contracts, projects, users).
 * Nada é consultado em tempo real em sistema externo.
 *
 * Ganchos de integração futura (Movidesk/Protheus/Fluig): source_system + external_ref
 * guardam a origem/ID externo. A sincronização ALIMENTA esta tabela; a tela nunca
 * consulta o sistema externo direto.
 *
 * SLA: sla_policy_id é resolvido na criação/edição; *_due_at são prazos calculados;
 * *_at são marcos reais; *_breached são flags de violação.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_tickets')) {
            return;
        }
        Schema::create('helpdesk_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 30)->nullable()->unique();   // ref. humana (ex.: HD-000123), gerada no app
            $table->string('subject', 200);
            $table->text('description')->nullable();

            // ── Vínculos locais ───────────────────────────────────────────────
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete(); // quem abriu (interno/portal)
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            // ── Classificação / roteamento ────────────────────────────────────
            $table->foreignId('category_id')->nullable()->constrained('helpdesk_categories')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('helpdesk_statuses')->nullOnDelete();
            $table->string('priority', 12)->default('normal');  // baixa | normal | alta | urgente
            $table->string('channel', 20)->default('portal');   // portal | email | telefone | interno | movidesk
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete(); // atendente
            $table->foreignId('team_id')->nullable()->constrained('helpdesk_teams')->nullOnDelete(); // fila

            // ── SLA ───────────────────────────────────────────────────────────
            $table->foreignId('sla_policy_id')->nullable()->constrained('helpdesk_sla_policies')->nullOnDelete();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->boolean('first_response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->integer('reopen_count')->default(0);

            // ── Operacional ───────────────────────────────────────────────────
            $table->timestamp('last_activity_at')->nullable();
            $table->string('source_system', 40)->nullable();   // gancho de integração futura
            $table->string('external_ref', 120)->nullable();   // ID no sistema externo (Movidesk/Protheus)
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status_id', 'priority']);
            $table->index('customer_id');
            $table->index('assignee_id');
            $table->index('team_id');
            $table->index('category_id');
            $table->index(['source_system', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_tickets');
    }
};
