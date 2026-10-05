<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cofre de Senhas: registra as telas na rotina de menus (Configurador) e injeta
 * o item "/cofre" nas árvores dos módulos de TODOS os perfis internos
 * (admin, administrativo, coordenadores, consultores). Cliente/parceiro ficam fora
 * por completo (zero-knowledge é para a equipe interna).
 * /cofre/configuracao e /cofre/auditoria são alcançadas de dentro do módulo —
 * registradas em nav_screens/screen_actions (gating), mas só /cofre entra no menu.
 * Idempotente e reversível (molde: seed_empresas_grupo_nav).
 */
return new class extends Migration
{
    // Perfis internos (espelha o padrão de /timesheets: granulares incluídos)
    private array $internalProfiles = [
        'admin', 'administrativo',
        'coordenador_projetos', 'coordenador_sustentacao',
        'consultor', 'consultor_horista', 'consultor_banco_de_horas', 'consultor_fixo',
    ];

    /** key => [label, profiles] */
    private function screens(): array
    {
        return [
            '/cofre'              => ['Cofre de Senhas', $this->internalProfiles],
            '/cofre/configuracao' => ['Configuração do Cofre', $this->internalProfiles],
            '/cofre/auditoria'    => ['Auditoria do Cofre', ['admin']],
        ];
    }

    // Árvores que recebem o item /cofre (uma por perfil interno)
    private array $modules = [
        'administrativo',                    // admin
        'administrativo__administrativo',    // perfil administrativo
        'servicos__coordenador_projetos',
        'servicos__coordenador_sustentacao',
        'consultor__horista',
        'consultor__banco_de_horas',
        'consultor__fixo',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->screens() as $key => [$label, $profiles]) {
            if (! DB::table('nav_screens')->where('key', $key)->exists()) {
                DB::table('nav_screens')->insert([
                    'key'        => $key,
                    'label'      => $label,
                    'route'      => $key,
                    'active'     => true,
                    'profiles'   => json_encode($profiles),
                    'users'      => json_encode([]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $i = 0;
            foreach (['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'] as $ak => $al) {
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
                if (($it['screen'] ?? null) === '/cofre') {
                    continue 2; // já existe neste módulo
                }
            }
            $items[] = [
                'id'     => 'n_' . $key . '_cofre',
                'icon'   => 'KeyRound',
                'screen' => '/cofre',
            ];
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
            $items = array_values(array_filter(
                $items,
                fn ($it) => ($it['screen'] ?? null) !== '/cofre'
            ));
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        DB::table('screen_actions')->whereIn('screen_key', array_keys($this->screens()))->delete();
        DB::table('nav_screens')->whereIn('key', array_keys($this->screens()))->delete();
    }
};
