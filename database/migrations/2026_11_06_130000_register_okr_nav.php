<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registra as telas da Gestão Estratégica (OKR) no Configurador de Menus:
 * - linhas em nav_screens (key, label, active, profiles);
 * - grupo "Gestão Estratégica" nos módulos do admin/administrativo/coordenadores.
 * Idempotente: não duplica telas nem o grupo.
 */
return new class extends Migration
{
    private array $screens = [
        ['key' => '/gestao-estrategica/dashboard',      'label' => 'Dashboard Estratégico'],
        ['key' => '/gestao-estrategica/planejamento',   'label' => 'Planejamento Anual'],
        ['key' => '/gestao-estrategica/objetivos',      'label' => 'Objetivos'],
        ['key' => '/gestao-estrategica/krs',            'label' => 'Resultados-chave'],
        ['key' => '/gestao-estrategica/acompanhamento', 'label' => 'Acompanhamento Mensal'],
    ];

    private array $profiles = [
        'admin', 'administrativo', 'coordenador_projetos', 'coordenador_sustentacao',
        'consultor_horista', 'consultor_fixo', 'consultor_banco_de_horas',
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->screens as $s) {
            DB::table('nav_screens')->updateOrInsert(
                ['key' => $s['key']],
                [
                    'label' => $s['label'],
                    'route' => $s['key'],
                    'active' => true,
                    'profiles' => json_encode($this->profiles),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $group = [
            'id' => 'g_okr',
            'label' => 'Gestão Estratégica',
            'icon' => 'Target',
            'children' => [
                ['id' => 'n_okr_dash',  'screen' => '/gestao-estrategica/dashboard',      'icon' => 'LayoutDashboard'],
                ['id' => 'n_okr_plan',  'screen' => '/gestao-estrategica/planejamento',   'icon' => 'CalendarRange'],
                ['id' => 'n_okr_obj',   'screen' => '/gestao-estrategica/objetivos',      'icon' => 'Target'],
                ['id' => 'n_okr_kr',    'screen' => '/gestao-estrategica/krs',            'icon' => 'ListChecks'],
                ['id' => 'n_okr_acomp', 'screen' => '/gestao-estrategica/acompanhamento', 'icon' => 'TableProperties'],
            ],
        ];

        foreach (['administrativo', 'administrativo__administrativo', 'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao'] as $moduleKey) {
            $row = DB::table('nav_modules')->where('key', $moduleKey)->first();
            if (!$row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $exists = collect($items)->contains(fn ($n) => ($n['id'] ?? null) === 'g_okr');
            if ($exists) {
                continue;
            }
            $items[] = $group;
            DB::table('nav_modules')->where('key', $moduleKey)->update([
                'items' => json_encode($items),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('nav_screens')->whereIn('key', array_column($this->screens, 'key'))->delete();

        foreach (['administrativo', 'administrativo__administrativo', 'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao'] as $moduleKey) {
            $row = DB::table('nav_modules')->where('key', $moduleKey)->first();
            if (!$row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $items = array_values(array_filter($items, fn ($n) => ($n['id'] ?? null) !== 'g_okr'));
            DB::table('nav_modules')->where('key', $moduleKey)->update(['items' => json_encode($items)]);
        }
    }
};
