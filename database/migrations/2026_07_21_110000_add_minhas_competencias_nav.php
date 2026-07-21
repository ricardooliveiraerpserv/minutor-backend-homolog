<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona o item "Minhas Competências" (/competencias/responder) no menu dos
 * perfis internos que respondem à pesquisa (admin/administrativo/consultor/
 * coordenador). Idempotente. Assim o colaborador convidado tem por onde abrir a
 * pesquisa interna (além do link do e-mail de convite).
 */
return new class extends Migration
{
    private const KEYS = [
        'administrativo', 'administrativo__administrativo',
        'consultor__banco_de_horas', 'consultor__fixo', 'consultor__horista',
        'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao',
    ];

    private const SCREEN = '/competencias/responder';

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            if ($this->hasScreen($items, self::SCREEN)) {
                continue; // já existe
            }
            $items[] = [
                'id' => 'n_' . $key . '_minhas_competencias',
                'label' => 'Minhas Competências',
                'icon' => 'Star',
                'screen' => self::SCREEN,
            ];
            DB::table('nav_modules')->where('key', $key)->update([
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $items = array_values($this->stripScreen($items, self::SCREEN));
            DB::table('nav_modules')->where('key', $key)->update([
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    private function hasScreen(array $nodes, string $screen): bool
    {
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) {
                return true;
            }
            if (! empty($n['children']) && $this->hasScreen($n['children'], $screen)) {
                return true;
            }
        }

        return false;
    }

    private function stripScreen(array $nodes, string $screen): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) {
                continue;
            }
            if (! empty($n['children'])) {
                $n['children'] = array_values($this->stripScreen($n['children'], $screen));
            }
            $out[] = $n;
        }

        return $out;
    }
};
