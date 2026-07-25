<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cofre de Ambientes: registra a tela /ambientes na rotina de menus e injeta o item
 * nas árvores dos perfis internos. Molde de 2026_07_25_110000_add_vault_nav_screens.
 * Cliente/parceiro ficam fora (equipe interna). Idempotente e reversível.
 */
return new class extends Migration
{
    private array $internalProfiles = [
        'admin', 'administrativo',
        'coordenador_projetos', 'coordenador_sustentacao',
        'consultor', 'consultor_horista', 'consultor_banco_de_horas', 'consultor_fixo',
    ];

    private function screens(): array
    {
        return [
            '/ambientes'           => ['Cofre de Ambientes', $this->internalProfiles],
            '/ambientes/auditoria' => ['Auditoria de Ambientes', ['admin']],
        ];
    }

    private array $modules = [
        'administrativo', 'administrativo__administrativo',
        'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao',
        'consultor__horista', 'consultor__banco_de_horas', 'consultor__fixo',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->screens() as $key => [$label, $profiles]) {
            if (! DB::table('nav_screens')->where('key', $key)->exists()) {
                DB::table('nav_screens')->insert([
                    'key' => $key, 'label' => $label, 'route' => $key, 'active' => true,
                    'profiles' => json_encode($profiles), 'users' => json_encode([]),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $i = 0;
            foreach ([
                'view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir',
                'reveal' => 'Revelar senha', 'copy' => 'Copiar', 'export' => 'Exportar',
                'download_cert' => 'Baixar certificado', 'history' => 'Ver histórico',
                'admin' => 'Administrar', 'manage_permissions' => 'Gerenciar permissões',
            ] as $ak => $al) {
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $key, 'action_key' => $ak],
                    ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        foreach ($this->modules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            foreach ($items as $it) {
                if (($it['screen'] ?? null) === '/ambientes') {
                    continue 2;
                }
            }
            $items[] = ['id' => 'n_' . $key . '_ambientes', 'icon' => 'Server', 'screen' => '/ambientes'];
            DB::table('nav_modules')->where('key', $key)->update([
                'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->modules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $items = array_values(array_filter($items, fn ($it) => ($it['screen'] ?? null) !== '/ambientes'));
            DB::table('nav_modules')->where('key', $key)->update([
                'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
        DB::table('screen_actions')->whereIn('screen_key', array_keys($this->screens()))->delete();
        DB::table('nav_screens')->whereIn('key', array_keys($this->screens()))->delete();
    }
};
