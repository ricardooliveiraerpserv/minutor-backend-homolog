<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomeia o item de menu da Fila (/help-desk/fila) para "Chamados" e troca o ícone, no menu
 * onde ele aparece com o rótulo global (admin — módulo help_desk). Os nós dos agentes, que
 * sobrepõem o rótulo com "Help Desk", ficam intactos. Idempotente.
 */
return new class extends Migration
{
    private string $screen = '/help-desk/fila';

    public function up(): void
    {
        $this->apply(fn ($n) => (($n['label'] ?? null) === null || ($n['label'] ?? null) === 'Fila (Kanban)')
            ? array_merge($n, ['label' => 'Chamados', 'icon' => 'Ticket']) : $n);
    }

    public function down(): void
    {
        $this->apply(fn ($n) => (($n['label'] ?? null) === 'Chamados')
            ? array_merge($n, ['label' => null, 'icon' => 'SquareKanban']) : $n);
    }

    private function apply(callable $transform): void
    {
        foreach (DB::table('nav_modules')->get() as $mod) {
            $items = json_decode($mod->items ?? '[]', true);
            if (!is_array($items)) continue;
            $new = $this->walk($items, $transform);
            if ($new !== $items) {
                DB::table('nav_modules')->where('id', $mod->id)->update([
                    'items'      => json_encode($new),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Aplica $transform a todo nó cujo screen seja o alvo, recursivamente. */
    private function walk(array $nodes, callable $transform): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $this->screen) {
                $n = $transform($n);
            }
            if (!empty($n['children']) && is_array($n['children'])) {
                $n['children'] = $this->walk($n['children'], $transform);
            }
            $out[] = $n;
        }
        return $out;
    }
};
