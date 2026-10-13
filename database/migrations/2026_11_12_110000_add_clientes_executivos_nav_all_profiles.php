<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\NavModule;

/**
 * A tela /clientes/executivos só havia sido inserida no módulo "administrativo" (admin).
 * O menu tem uma CÓPIA de módulo por perfil, então administrativo e os coordenadores
 * (servicos__coordenador_*) não viam a tela. Aqui inserimos no grupo "Cadastros" das
 * cópias desses perfis, logo após /clientes (âncora) — idempotente.
 */
return new class extends Migration
{
    private array $moduleKeys = [
        'administrativo',                      // admin (já inserido antes; skip idempotente)
        'administrativo__administrativo',      // perfil administrativo
        'servicos__coordenador_projetos',      // coordenador de projetos
        'servicos__coordenador_sustentacao',   // coordenador de sustentação
    ];

    public function up(): void
    {
        $key = '/clientes/executivos';

        foreach ($this->moduleKeys as $mk) {
            $mod = NavModule::where('key', $mk)->first();
            if (!$mod) {
                continue;
            }
            $items = $mod->items ?? [];

            // Já presente em qualquer grupo? pula.
            $already = false;
            foreach ($items as $g) {
                foreach (($g['children'] ?? []) as $c) {
                    if (($c['screen'] ?? null) === $key) {
                        $already = true;
                        break 2;
                    }
                }
            }
            if ($already) {
                continue;
            }

            $changed = false;
            foreach ($items as $gi => $g) {
                if (($g['label'] ?? null) !== 'Cadastros') {
                    continue;
                }
                $children = $g['children'] ?? [];
                $pos = count($children);
                foreach ($children as $ci => $c) {
                    if (($c['screen'] ?? null) === '/clientes') {
                        $pos = $ci + 1;
                        break;
                    }
                }
                $newChild = ['id' => 'n_clientexec_' . substr(md5($mk . $key), 0, 6), 'screen' => $key];
                array_splice($children, $pos, 0, [$newChild]);
                $items[$gi]['children'] = $children;
                $changed = true;
                break;
            }

            if ($changed) {
                $mod->items = $items;
                $mod->save();
            }
        }
    }

    public function down(): void
    {
        $key = '/clientes/executivos';
        foreach ($this->moduleKeys as $mk) {
            if ($mk === 'administrativo') {
                continue; // removido pela migration original
            }
            $mod = NavModule::where('key', $mk)->first();
            if (!$mod) {
                continue;
            }
            $items = $mod->items ?? [];
            foreach ($items as $gi => $g) {
                if (!isset($g['children'])) {
                    continue;
                }
                $items[$gi]['children'] = array_values(array_filter(
                    $g['children'],
                    fn ($c) => ($c['screen'] ?? null) !== $key
                ));
            }
            $mod->items = $items;
            $mod->save();
        }
    }
};
