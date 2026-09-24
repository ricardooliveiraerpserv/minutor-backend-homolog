<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coloca UM item "Help Desk" (a Fila) no menu dos AGENTES, dentro do módulo que eles JÁ usam
 * (consultor e coordenador não trocam de módulo). Sem Chamados nem Base de Conhecimento.
 *
 * A sidebar monta o menu a partir da árvore do módulo do próprio perfil (consultor__*, e
 * servicos__coordenador_*). Então adicionamos um nó-folha apontando para /help-desk/fila em cada
 * um desses módulos. O rótulo do nó ("Help Desk") sobrepõe o rótulo global da tela ("Fila (Kanban)")
 * — o menu do admin continua intacto. Idempotente: só adiciona se ainda não houver o nó.
 */
return new class extends Migration
{
    private string $screen = '/help-desk/fila';

    /** Módulos dos agentes (consultor em todos os vínculos + coordenador projetos/sustentação). */
    private array $targets = [
        'consultor', 'consultor__horista', 'consultor__fixo', 'consultor__banco_de_horas',
        'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao',
    ];

    public function up(): void
    {
        foreach ($this->targets as $key) {
            $mod = DB::table('nav_modules')->where('key', $key)->first();
            if (!$mod) continue;
            $items = json_decode($mod->items ?? '[]', true);
            if (!is_array($items)) $items = [];

            // Já existe um nó para a Fila? (varre a árvore) — não duplica.
            if ($this->hasScreen($items, $this->screen)) continue;

            $items[] = [
                'id'     => 'n_hd_' . $key,
                'screen' => $this->screen,
                'icon'   => 'Headphones',
                'label'  => 'Help Desk',
            ];
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($items),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $key) {
            $mod = DB::table('nav_modules')->where('key', $key)->first();
            if (!$mod) continue;
            $items = json_decode($mod->items ?? '[]', true);
            if (!is_array($items)) continue;
            $filtered = array_values(array_filter($items, fn ($n) => ($n['screen'] ?? null) !== $this->screen));
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($filtered),
                'updated_at' => now(),
            ]);
        }
    }

    private function hasScreen(array $nodes, string $screen): bool
    {
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) return true;
            if (!empty($n['children']) && is_array($n['children']) && $this->hasScreen($n['children'], $screen)) return true;
        }
        return false;
    }
};
