<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DARK LAUNCH em PROD: o módulo Help Desk deve ser 100% ADMIN-ONLY.
 *
 * Migrations anteriores (dev2) injetaram telas /help-desk nos módulos de navegação
 * de consultor/cliente e marcaram nav_screens com perfis não-admin. Aqui NEUTRALIZAMOS
 * esse vazamento de forma idempotente:
 *   1) remove qualquer nó cujo `screen` seja uma rota /help-desk da árvore `items` de
 *      TODOS os módulos, exceto o próprio `help_desk` (admin);
 *   2) força TODAS as nav_screens de /help-desk para profiles=['admin'] e limpa `users`.
 *
 * Só toca em nós/telas /help-desk (que não existiam em prod antes deste port) — nada
 * nativo da prod é alterado. down() é no-op (não reintroduzimos o vazamento).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Remover nós /help-desk da árvore de itens dos módulos não-admin
        foreach (DB::table('nav_modules')->where('key', '!=', 'help_desk')->get() as $mod) {
            $items = json_decode($mod->items ?? '[]', true);
            if (!is_array($items)) {
                continue;
            }
            $changed = false;
            $clean = $this->stripHelpDesk($items, $changed);
            if ($changed) {
                DB::table('nav_modules')->where('id', $mod->id)->update([
                    'items'      => json_encode(array_values($clean)),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2) Todas as telas /help-desk => admin-only, sem usuários avulsos
        DB::table('nav_screens')
            ->where('route', 'like', '%/help-desk%')
            ->update([
                'profiles'   => json_encode(['admin']),
                'users'      => json_encode([]),
                'updated_at' => now(),
            ]);
    }

    /**
     * Remove recursivamente qualquer nó cujo `screen` seja rota /help-desk.
     * Poda também grupos que ficarem sem filhos após a remoção.
     */
    private function stripHelpDesk(array $nodes, bool &$changed): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                $out[] = $node;
                continue;
            }
            $screen = $node['screen'] ?? null;
            if (is_string($screen) && str_contains($screen, '/help-desk')) {
                $changed = true;
                continue; // descarta a folha help-desk
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $hadChildren = count($node['children']) > 0;
                $node['children'] = array_values($this->stripHelpDesk($node['children'], $changed));
                // poda grupo que existia só para agrupar telas help-desk
                if ($hadChildren && count($node['children']) === 0 && !isset($node['screen'])) {
                    $changed = true;
                    continue;
                }
            }
            $out[] = $node;
        }
        return $out;
    }

    public function down(): void
    {
        // no-op: não reintroduzimos o vazamento admin-only
    }
};
