<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove o item de menu "Chamados" (/help-desk/tickets) das árvores de navegação. A lista de
 * chamados não é mais um destino próprio no menu — o fluxo é pela Central de Operações e pela Fila.
 * A rota/página continua existindo (links internos), só sai do menu. Idempotente.
 */
return new class extends Migration
{
    private string $screen = '/help-desk/tickets';

    public function up(): void
    {
        foreach (DB::table('nav_modules')->get() as $mod) {
            $items = json_decode($mod->items ?? '[]', true);
            if (!is_array($items)) continue;
            $filtered = $this->stripScreen($items, $this->screen);
            if ($filtered !== $items) {
                DB::table('nav_modules')->where('id', $mod->id)->update([
                    'items'      => json_encode($filtered),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Reversão best-effort: recoloca "Chamados" na raiz do módulo admin (help_desk).
        $mod = DB::table('nav_modules')->where('key', 'help_desk')->first();
        if (!$mod) return;
        $items = json_decode($mod->items ?? '[]', true);
        if (!is_array($items)) $items = [];
        foreach ($items as $n) {
            if (($n['screen'] ?? null) === $this->screen) return; // já existe
        }
        $items[] = ['id' => 'n_hd_tickets', 'screen' => $this->screen, 'icon' => 'Ticket', 'label' => null];
        DB::table('nav_modules')->where('id', $mod->id)->update([
            'items'      => json_encode($items),
            'updated_at' => now(),
        ]);
    }

    /** Remove recursivamente qualquer nó cujo screen seja $screen. Retorna nova árvore. */
    private function stripScreen(array $nodes, string $screen): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) continue;
            if (!empty($n['children']) && is_array($n['children'])) {
                $n['children'] = $this->stripScreen($n['children'], $screen);
            }
            $out[] = $n;
        }
        return $out;
    }
};
