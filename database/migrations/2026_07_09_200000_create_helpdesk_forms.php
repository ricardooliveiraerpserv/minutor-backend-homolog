<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Construtor de FORMULÁRIOS do Help Desk. Um formulário é vinculado a um STATUS: ao escolher
 * esse status, o formulário abre e precisa ser preenchido. Cada campo tem tipo, label, legenda
 * (hint), obrigatoriedade e mínimo de caracteres — tudo configurável pelo admin. Semeia os
 * dois já existentes: "Detalhamento da Solução" (resolvido) e "Solução com GMUD" (solucao_gmud).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_forms')) {
            Schema::create('helpdesk_forms', function (Blueprint $table) {
                $table->id();
                $table->string('name', 140);
                $table->foreignId('status_id')->nullable()->constrained('helpdesk_statuses')->nullOnDelete();
                $table->string('title', 200)->nullable();   // título grande no topo (ex.: "🛠️ Detalhamento da Solução")
                $table->text('intro')->nullable();           // texto introdutório opcional
                $table->boolean('show_logo')->default(true);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index('status_id');
            });
        }

        if (!Schema::hasTable('helpdesk_form_fields')) {
            Schema::create('helpdesk_form_fields', function (Blueprint $table) {
                $table->id();
                $table->foreignId('form_id')->constrained('helpdesk_forms')->cascadeOnDelete();
                $table->integer('order_index')->default(0);
                $table->string('key', 60);                   // chave estável do campo
                $table->string('ftype', 20);                 // section | text | richtext | checkbox | date | time
                $table->string('label', 200);
                $table->text('hint')->nullable();            // legenda / exemplo
                $table->boolean('required')->default(false);
                $table->integer('min_chars')->nullable();    // mínimo de caracteres (text/richtext), sem contar espaço
                $table->timestamps();
                $table->index(['form_id', 'order_index']);
            });
        }

        $this->seedForms();
    }

    private function seedForms(): void
    {
        $now = now();
        $statusId = fn (string $key) => DB::table('helpdesk_statuses')->where('key', $key)->value('id');

        // ── Form 1: Detalhamento da Solução (status: resolvido) ──────────────
        if (!DB::table('helpdesk_forms')->where('name', 'Detalhamento da Solução')->exists()) {
            $fid = DB::table('helpdesk_forms')->insertGetId([
                'name' => 'Detalhamento da Solução', 'status_id' => $statusId('resolvido'),
                'title' => '🛠️ Detalhamento da Solução', 'show_logo' => true, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $fields = [
                ['diagnostico', 'richtext', '🔍 Diagnóstico (Causa)', 'O que estava causando o problema? Ex.: “A regra fiscal estava desatualizada e gerava imposto errado na NF-e.”', true, 20],
                ['acao', 'richtext', '🚀 Ação Realizada (O Ajuste)', 'O que você fez para corrigir? Ex.: “Atualizei a alíquota de ICMS e reprocessei as notas do período.”', true, 20],
                ['validacao', 'richtext', '✅ Validação (Teste Efetuado)', 'Como confirmou que resolveu? Ex.: “Emiti uma NF-e de teste, o imposto saiu correto e o cliente validou.”', true, 20],
            ];
            $this->insertFields($fid, $fields, $now);
        }

        // ── Form 2: Solução com GMUD (status: solucao_gmud) ──────────────────
        if (!DB::table('helpdesk_forms')->where('name', 'Solução com GMUD')->exists()) {
            $fid = DB::table('helpdesk_forms')->insertGetId([
                'name' => 'Solução com GMUD', 'status_id' => $statusId('solucao_gmud'),
                'title' => '🔧 GMUD EM PRODUÇÃO (Gestão de Mudanças)', 'show_logo' => true, 'active' => true,
                'intro' => 'Registro obrigatório do processo de qualidade: detalhe o que foi aplicado, os testes e o plano de segurança (backup).',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $fields = [
                ['sec_itens', 'section', '📁 1. Itens Alterados', null, false, null],
                ['item_dicionario', 'checkbox', 'Dicionário (Campos, Parâmetros, Gatilhos)', null, false, null],
                ['item_codigo', 'checkbox', 'Código Fonte (RDMAKE / Patch)', null, false, null],
                ['item_config', 'checkbox', 'Configuração (TSS, AppServer, DBAccess)', null, false, null],
                ['item_outros', 'text', 'Outros', null, false, null],
                ['sec_resp', 'section', '👤 2. Responsáveis', null, false, null],
                ['analista_executor', 'text', 'Analista Executor', null, true, null],
                ['autorizado_por', 'text', 'Autorizado por', null, true, null],
                ['testado_por', 'text', 'Testado por', null, true, null],
                ['sec_exec', 'section', '📅 3. Execução', null, false, null],
                ['data_aplicacao', 'date', 'Data da aplicação', null, true, null],
                ['horario', 'time', 'Horário', null, false, null],
                ['sec_rpo', 'section', '💾 4. Controle de Versão (RPO)', null, false, null],
                ['tcloud', 'checkbox', 'Ambiente TCLOUD (não se aplica RPO manual)', null, false, null],
                ['rpo_backup', 'text', 'RPO Backup (antes)', null, false, null],
                ['rpo_atual', 'text', 'RPO Atual (após)', null, false, null],
                ['sec_proc', 'section', '📋 5. Procedimento Executado', null, false, null],
                ['procedimento', 'richtext', 'Procedimento Executado', 'Descreva objetivamente as etapas aplicadas em produção conforme planejamento aprovado.', true, 10],
                ['sec_anexos', 'section', '📎 6. Anexos Obrigatórios', null, false, null],
                ['anx_fontes', 'checkbox', 'Fontes aplicados (.PRW / .TLPP)', null, false, null],
                ['anx_patch', 'checkbox', 'Patch ou pacote aplicado', null, false, null],
                ['anx_evidencia', 'checkbox', 'Evidência de compilação', null, false, null],
                ['anx_print', 'checkbox', 'Print de validação/teste', null, false, null],
                ['anx_doc', 'checkbox', 'Documento de aprovação', null, false, null],
                ['anx_outros', 'text', 'Outros', null, false, null],
                ['sec_solucao', 'section', '🛠️ Detalhamento da Solução', null, false, null],
                ['diagnostico', 'richtext', '🔍 Diagnóstico (Causa)', 'O que estava causando o problema?', true, 20],
                ['acao', 'richtext', '🚀 Ação Realizada (O Ajuste)', 'O que foi alterado: Fonte, Parâmetro, Gatilho ou Procedimento.', true, 20],
                ['validacao', 'richtext', '✅ Validação (Teste Efetuado)', 'O teste que confirma a solução.', true, 20],
            ];
            $this->insertFields($fid, $fields, $now);
        }
    }

    private function insertFields(int $formId, array $fields, $now): void
    {
        $rows = [];
        foreach ($fields as $i => [$key, $ftype, $label, $hint, $required, $min]) {
            $rows[] = [
                'form_id' => $formId, 'order_index' => $i, 'key' => $key, 'ftype' => $ftype,
                'label' => $label, 'hint' => $hint, 'required' => $required, 'min_chars' => $min,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('helpdesk_form_fields')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_form_fields');
        Schema::dropIfExists('helpdesk_forms');
    }
};
