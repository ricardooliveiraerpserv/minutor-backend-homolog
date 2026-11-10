<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OKR (Gestão Estratégica) visível por padrão SÓ para o admin.
 * Remove o item "Gestão Estratégica (OKR)" dos menus dos demais perfis e deixa as
 * telas /gestao-estrategica/* com profiles = ["admin"]. Para liberar a outros perfis,
 * use o Configurador de menus. Idempotente.
 */
return new class extends Migration
{
    private array $nonAdminModules = [
        'administrativo__administrativo',
        'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao',
        'consultor__horista', 'consultor__fixo', 'consultor__banco_de_horas',
    ];

    private function strip(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['id'] ?? null) === 'n_okr' || ($n['id'] ?? null) === 'g_okr'
                || (isset($n['screen']) && str_starts_with($n['screen'], '/gestao-estrategica/'))) {
                continue;
            }
            if (isset($n['children']) && is_array($n['children'])) {
                $n['children'] = $this->strip($n['children']);
            }
            $out[] = $n;
        }
        return $out;
    }

    public function up(): void
    {
        foreach ($this->nonAdminModules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (!$row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            DB::table('nav_modules')->where('key', $key)
                ->update(['items' => json_encode($this->strip($items)), 'updated_at' => now()]);
        }

        DB::table('nav_screens')->where('route', 'like', '/gestao-estrategica/%')
            ->update(['profiles' => json_encode(['admin']), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sem volta automática: a liberação para outros perfis é feita pelo Configurador.
    }
};
