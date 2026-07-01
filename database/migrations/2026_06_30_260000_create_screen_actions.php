<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo CENTRAL de ações por tela (rotina). Cada tela declara suas ações disponíveis aqui,
 * substituindo o hardcode do frontend. As PERMISSÕES por ação continuam em nav_screens.abilities
 * (perfis/usuários/deny por action_key); este catálogo define QUAIS ações existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_actions', function (Blueprint $t) {
            $t->id();
            $t->string('screen_key', 160);   // = nav_screens.key (href)
            $t->string('action_key', 40);    // view | create | edit | delete | approve | reopen | ...
            $t->string('label', 80);
            $t->string('description', 200)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['screen_key', 'action_key']);
            $t->index('screen_key');
        });

        // Dicionário de ações (label + descrição)
        $L = [
            'view'             => ['Visualizar', 'Ver a tela e seus dados'],
            'create'           => ['Criar', 'Incluir novos registros'],
            'edit'             => ['Editar', 'Alterar registros existentes'],
            'delete'           => ['Excluir', 'Remover registros'],
            'approve'          => ['Aprovar', 'Aprovar / validar registros'],
            'reopen'           => ['Reabrir', 'Reabrir / estornar registros'],
            'export'           => ['Exportar', 'Exportar / baixar dados'],
            'close'            => ['Fechar', 'Fechar competência / período'],
            'cancel'           => ['Cancelar', 'Cancelar registros'],
            'generate_invoice' => ['Gerar Nota', 'Gerar nota / faturamento'],
        ];

        // Conjuntos específicos por tela (href). Demais caem na heurística.
        $specific = [
            '/timesheets'        => ['view', 'create', 'edit', 'delete', 'approve', 'reopen'],
            '/expenses'          => ['view', 'create', 'edit', 'delete', 'approve'],
            '/contratos/kanban'  => ['view', 'create', 'edit', 'cancel', 'generate_invoice'],
            '/gestao-projetos'   => ['view', 'create', 'edit', 'cancel', 'generate_invoice'],
            '/contratos/pipeline'=> ['view', 'create', 'edit', 'cancel'],
            '/approvals'         => ['view', 'approve', 'reopen'],
        ];

        $heuristic = function (string $k): array {
            if (preg_match('#relatorio|/dashboards|portal-cliente|rentabilidade|partner-dashboard|meu-painel|fechado|bank-hours|on-demand#', $k)) return ['view', 'export'];
            if (str_contains($k, 'approval')) return ['view', 'approve', 'reopen'];
            if (str_starts_with($k, '/fechamento')) return ['view', 'edit', 'close', 'export'];
            if (str_starts_with($k, '/cadastros') || str_starts_with($k, '/clientes') || str_starts_with($k, '/partners')
                || str_starts_with($k, '/users') || str_contains($k, '?tab=') || str_starts_with($k, '/configuracoes')
                || str_starts_with($k, '/central-comunicacao') || str_starts_with($k, '/crm') || str_starts_with($k, '/help-desk')) return ['view', 'create', 'edit', 'delete'];
            return ['view', 'edit'];
        };

        $now = now();
        $keys = DB::table('nav_screens')->pluck('key');
        foreach ($keys as $key) {
            $actions = $specific[$key] ?? $heuristic($key);
            $i = 0;
            foreach ($actions as $a) {
                if (!isset($L[$a])) continue;
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $key, 'action_key' => $a],
                    ['label' => $L[$a][0], 'description' => $L[$a][1], 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_actions');
    }
};
