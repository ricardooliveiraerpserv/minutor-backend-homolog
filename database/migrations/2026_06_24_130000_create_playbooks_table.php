<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Playbooks de Atendimento — motor de padronização operacional da PLATAFORMA.
 *
 * Um playbook é um procedimento que executa uma SEQUÊNCIA de ações numa única operação.
 * `scope` define o módulo (help_desk agora; crm/servicos depois) — cada módulo tem seu
 * EXECUTOR que interpreta `actions`. NÃO é macro de texto: é procedimento reutilizável,
 * base para automações e sugestões de IA ("este chamado parece usar o playbook X").
 *
 * actions (JSON, todos opcionais — executa só o configurado):
 *   reply, internal_comment, status_id, priority, team_id, assignee_id,
 *   checklist[], start_finalize(bool), finalize_status_id
 * (pausa/retomada de SLA seguem o status alvo — status com sla_paused pausa o relógio.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('playbooks')) {
            Schema::create('playbooks', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 30)->default('help_desk');  // help_desk | crm | servicos | ...
                $table->string('name', 120);
                $table->string('category', 80)->nullable();
                $table->string('color', 16)->nullable();
                $table->string('icon', 40)->nullable();
                $table->boolean('active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->json('actions');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['scope', 'active', 'sort_order']);
            });
        }

        // Seeds — playbooks operacionais padrão do Help Desk (usável desde o go-live).
        if (DB::table('playbooks')->where('scope', 'help_desk')->count() === 0) {
            $sid = fn ($key) => DB::table('helpdesk_statuses')->where('key', $key)->value('id');
            $aguard = $sid('aguardando_cliente');
            $andam = $sid('em_andamento');
            $resolv = $sid('resolvido');
            $rows = [
                ['name' => 'Solicitar Evidências', 'category' => 'Triagem', 'color' => '#f59e0b', 'icon' => 'image', 'sort_order' => 10, 'actions' => [
                    'reply' => 'Para prosseguirmos com seu atendimento, poderia nos enviar evidências do problema (prints, mensagens de erro, logs)? Assim conseguimos agilizar a análise.',
                    'status_id' => $aguard,
                    'checklist' => ['Confirmar o cenário relatado', 'Pedir prints/logs', 'Validar versão/ambiente'],
                ]],
                ['name' => 'Aguardando Cliente', 'category' => 'SLA', 'color' => '#f59e0b', 'icon' => 'clock', 'sort_order' => 20, 'actions' => [
                    'status_id' => $aguard,
                    'internal_comment' => 'Aguardando retorno do cliente.',
                ]],
                ['name' => 'Aguardando Terceiros', 'category' => 'SLA', 'color' => '#f59e0b', 'icon' => 'clock', 'sort_order' => 30, 'actions' => [
                    'status_id' => $aguard,
                    'internal_comment' => 'Aguardando retorno de terceiros/fornecedor.',
                ]],
                ['name' => 'Encaminhar para Desenvolvimento', 'category' => 'Roteamento', 'color' => '#6366f1', 'icon' => 'git-branch', 'sort_order' => 40, 'actions' => [
                    'priority' => 'alta',
                    'status_id' => $andam,
                    'internal_comment' => 'Encaminhado ao time de Desenvolvimento para análise técnica.',
                ]],
                ['name' => 'Resolvido', 'category' => 'Encerramento', 'color' => '#22c55e', 'icon' => 'check-circle', 'sort_order' => 50, 'actions' => [
                    'reply' => 'Seu chamado foi resolvido. Qualquer dúvida, estamos à disposição.',
                    'start_finalize' => true,
                    'finalize_status_id' => $resolv,
                ]],
            ];
            foreach ($rows as $r) {
                DB::table('playbooks')->insert([
                    'scope' => 'help_desk', 'name' => $r['name'], 'category' => $r['category'],
                    'color' => $r['color'], 'icon' => $r['icon'], 'active' => true, 'sort_order' => $r['sort_order'],
                    'actions' => json_encode($r['actions']), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('playbooks');
    }
};
