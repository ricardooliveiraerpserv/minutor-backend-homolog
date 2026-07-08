<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds dos cadastros do Help Desk (idempotente): categorias padrão, serviços de exemplo
 * e justificativas de cancelamento. Tudo editável depois pela UI de cadastros.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── Categorias de ticket (padrão) ──────────────────────────────────
        $categorias = ['Dúvida', 'Falha de sistema', 'Falha operacional', 'Melhoria', 'Problema de Infraestrutura', 'Solicitação de serviço'];
        foreach ($categorias as $i => $nome) {
            $exists = DB::table('helpdesk_categories')->where('name', $nome)->exists();
            if (!$exists) {
                DB::table('helpdesk_categories')->insert([
                    'name' => $nome, 'slug' => Str::slug($nome), 'active' => true,
                    'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // ── Catálogo de Serviços (exemplos editáveis) ──────────────────────
        $servicos = ['Demandas internas ERPSERV', 'Manutenção Promax', 'Minutor', 'Outros Softwares', 'Suporte Cloud', 'Suporte ERP', 'Suporte Fluig', 'Suporte Infraestrutura'];
        foreach ($servicos as $i => $nome) {
            if (!DB::table('helpdesk_services')->where('name', $nome)->exists()) {
                DB::table('helpdesk_services')->insert([
                    'name' => $nome, 'availability' => 'public_and_internal',
                    'visible_to_agent' => true, 'visible_to_client' => true,
                    'selectable_by_agent' => true, 'selectable_by_client' => false,
                    'allow_conclusion' => true, 'active' => true,
                    'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // ── Justificativas vinculadas ao status "Cancelado" ────────────────
        $cancelado = DB::table('helpdesk_statuses')->where('key', 'cancelado')->value('id');
        if ($cancelado) {
            $justs = ['Aberto em duplicidade', 'Aberto indevidamente', 'Direcionado para o Comercial', 'Resolvido internamente pelo cliente'];
            foreach ($justs as $i => $nome) {
                if (!DB::table('helpdesk_ticket_justifications')->where('name', $nome)->where('status_id', $cancelado)->exists()) {
                    DB::table('helpdesk_ticket_justifications')->insert([
                        'status_id' => $cancelado, 'name' => $nome, 'availability' => 'public_and_internal',
                        'active' => true, 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Seeds idempotentes — não remove (dados podem ter sido editados/usados).
    }
};
