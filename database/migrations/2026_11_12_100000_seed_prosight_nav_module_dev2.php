<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Registra o módulo "Prosight / Governança Técnica Protheus" (nav_modules) no dev2 + telas,
 * dando acesso ao Central de Fontes/acervo, Operações Protheus (Connector), Prosight e
 * Cofre de Ambientes. A sub-navegação fina é feita pelo ProsightNav dentro do shell.
 * Idempotente (updateOrCreate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $screens = [
            '/central-fontes'      => 'Central de Fontes',
            '/prosight'            => 'Prosight',
            '/operacoes-protheus'  => 'Operações Protheus',
            '/ambientes'           => 'Cofre de Ambientes',
        ];

        $now = now();
        foreach ($screens as $key => $label) {
            $s = NavScreen::firstOrNew(['key' => $key]);
            $profiles = $s->exists ? ($s->profiles ?? []) : [];
            if (!in_array('admin', $profiles, true)) $profiles[] = 'admin';
            $s->profiles = array_values($profiles);
            $s->route = $s->route ?: $key;
            if (!$s->exists) $s->active = true;
            if (empty($s->label)) $s->label = $label;
            $s->users = $s->users ?? [];
            $s->save();
            $i = 0;
            foreach (['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'] as $ak => $al) {
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $key, 'action_key' => $ak],
                    ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }

        $nid = 0; $id = function () use (&$nid) { return 'n_ps' . str_pad((string) (++$nid), 4, '0', STR_PAD_LEFT); };
        $items = [[
            'id' => $id(), 'label' => 'Governança Técnica (Protheus)', 'icon' => 'FileCode',
            'children' => array_map(fn ($k) => ['id' => $id(), 'screen' => $k], array_keys($screens)),
        ]];

        $sort = (int) NavModule::max('sort_order');
        NavModule::updateOrCreate(['key' => 'prosight'], [
            'label' => 'Prosight', 'icon' => 'FileCode', 'sort_order' => ++$sort,
            'is_system' => true, 'active' => true, 'profiles' => ['admin'], 'items' => $items,
        ]);
    }

    public function down(): void
    {
        NavModule::where('key', 'prosight')->delete();
    }
};
