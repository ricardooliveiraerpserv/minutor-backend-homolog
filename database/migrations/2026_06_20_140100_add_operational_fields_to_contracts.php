<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4.1 — status operacionais novos + auditoria de liberação/bloqueio + hierarquia de contratos.
 *
 * Status operacional (projeção coarse, separado do jurídico Document.status_assinatura):
 *   rascunho → emitido → aguardando_assinatura → aguardando_liberacao →
 *   liberado_execucao → projeto_gerado → ativo  (legados aprovado/inicio_autorizado mantidos).
 *
 * NOTA (preparação futura, sem implementar): a transição aguardando_liberacao → liberado_execucao
 * poderá depender de um GATE FINANCEIRO formal — espaço reservado, sem tabela/coluna agora.
 */
return new class extends Migration {
    private array $statuses = [
        'rascunho', 'aprovado', 'inicio_autorizado', 'ativo', // legados
        'emitido', 'aguardando_assinatura', 'aguardando_liberacao', 'liberado_execucao', 'projeto_gerado',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_status_check');
            $list = collect($this->statuses)->map(fn ($s) => "'" . $s . "'")->implode(', ');
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_status_check CHECK (status::text = ANY (ARRAY[$list]::text[]))");
        }

        Schema::table('contracts', function (Blueprint $table) {
            // Liberação operacional (auditoria)
            if (!Schema::hasColumn('contracts', 'liberado_por')) {
                $table->foreignId('liberado_por')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('contracts', 'liberado_em')) {
                $table->timestamp('liberado_em')->nullable()->after('liberado_por');
            }
            if (!Schema::hasColumn('contracts', 'liberacao_observacao')) {
                $table->text('liberacao_observacao')->nullable()->after('liberado_em');
            }
            // Bloqueio (hold reversível — interromper execução)
            if (!Schema::hasColumn('contracts', 'bloqueado_por')) {
                $table->foreignId('bloqueado_por')->nullable()->after('liberacao_observacao')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('contracts', 'bloqueado_em')) {
                $table->timestamp('bloqueado_em')->nullable()->after('bloqueado_por');
            }
            if (!Schema::hasColumn('contracts', 'motivo_bloqueio')) {
                $table->text('motivo_bloqueio')->nullable()->after('bloqueado_em');
            }
            // Hierarquia: Contrato Principal → Aditivos / Renovação (preserva histórico)
            if (!Schema::hasColumn('contracts', 'parent_contract_id')) {
                $table->foreignId('parent_contract_id')->nullable()->after('motivo_bloqueio')->constrained('contracts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['liberado_por', 'bloqueado_por', 'parent_contract_id'] as $fk) {
                if (Schema::hasColumn('contracts', $fk)) $table->dropConstrainedForeignId($fk);
            }
            foreach (['liberado_em', 'liberacao_observacao', 'bloqueado_em', 'motivo_bloqueio'] as $col) {
                if (Schema::hasColumn('contracts', $col)) $table->dropColumn($col);
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_status_check');
            DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_status_check CHECK (status::text = ANY (ARRAY['rascunho','aprovado','inicio_autorizado','ativo']::text[]))");
        }
    }
};
