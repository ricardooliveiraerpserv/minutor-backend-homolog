<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liga TODOS os clientes ativos ao seu repositório em erpserv-clientes (13/11/2026).
 * Regra: todo cliente tem repositório Git conectado; a chave "Contrato" (customers.has_contract,
 * guarda de fontes) NÃO é alterada — permanece inativa até ser ligada no cadastro.
 * Idempotente: não duplica vínculo existente (mesmo cliente+repo) e ignora cliente inexistente.
 */
return new class extends Migration
{
    private const DESC = 'Vinculado em lote (carga de fontes do Movidesk, 13/11/2026).';

    public function up(): void
    {
        if (! Schema::hasTable('client_source_repos')) {
            return;
        }
        $map = [
            [174, 'zeg-biogas', 'protheus'],
            [175, 'venkuri', 'protheus'],
            [176, 'vedamotors', 'protheus'],
            [177, 'valvugas', 'protheus'],
            [178, 'unimetal', 'protheus'],
            [180, 'tbc-agro', 'protheus'],
            [182, 'superlogica', 'protheus'],
            [183, 'schmidt', 'protheus'],
            [184, 'selores', 'protheus'],
            [185, 'rust', 'protheus'],
            [187, 'pro-mais', 'protheus'],
            [188, 'poli-control', 'protheus'],
            [189, 'pit', 'protheus'],
            [191, 'nutriz', 'protheus'],
            [192, 'nlmk', 'protheus'],
            [196, 'metal-limpo', 'protheus'],
            [197, 'agropecuaria-locks', 'protheus'],
            [198, 'konecta', 'protheus'],
            [199, 'inventa', 'protheus'],
            [200, 'invel', 'protheus'],
            [201, 'induspecas', 'protheus'],
            [203, 'hydraforce-hydraulic', 'protheus'],
            [204, 'hub-accounting', 'protheus'],
            [205, 'htm', 'protheus'],
            [206, 'gtf', 'protheus'],
            [207, 'fertgrow-sa', 'protheus'],
            [209, 'espumauto', 'protheus'],
            [211, 'dicast', 'protheus'],
            [212, 'daiso-brasil', 'protheus'],
            [213, 'daus', 'protheus'],
            [214, 'serra-formosa', 'protheus'],
            [215, 'concreserv', 'protheus'],
            [216, 'ccab-agro', 'protheus'],
            [217, 'consbem', 'protheus'],
            [218, 'capsum', 'protheus'],
            [219, 'bombay', 'protheus'],
            [220, 'auster-fluig', 'fluig'],
            [220, 'auster-producao', 'protheus'],
            [221, 'ansell', 'protheus'],
            [222, 'america-tampas', 'protheus'],
            [223, 'eurofins', 'protheus'],
            [225, 'perfil-refrigeracao', 'protheus'],
            [226, 'agro-amazonia', 'protheus'],
            [227, 'wilson-couros', 'protheus'],
            [229, 'avenues', 'protheus'],
            [230, 'metalosa', 'protheus'],
            [232, 'monte-rodovias', 'protheus'],
            [235, 'norcoast', 'protheus'],
            [236, 'solventex', 'protheus'],
            [237, 'germania', 'protheus'],
            [238, 'gnx-group', 'protheus'],
            [239, 'blascor', 'protheus'],
            [240, 'comlub', 'protheus'],
            [241, 'instituto-atlantico', 'protheus'],
            [242, 'neobpo', 'protheus'],
            [243, 'panimundi', 'protheus'],
            [244, 'pierserv', 'protheus'],
            [248, 'erpserv', 'protheus'],
            [250, 'curtume', 'protheus'],
            [253, 'nscafe', 'protheus'],
            [259, 'oab', 'protheus'],
            [261, 'ibemapar', 'protheus'],
            [262, 'ibar', 'protheus'],
            [264, 'upcon', 'protheus'],
            [265, 'eureka', 'protheus'],
            [267, 'minas-bojo', 'protheus'],
            [269, 'promax', 'protheus'],
            [270, 'qair-brasil', 'protheus'],
            [271, 'lubrisint', 'protheus'],
            [272, 'dunorte', 'protheus'],
            [273, 'bhs-axter', 'protheus'],
            [274, 'brasal', 'protheus'],
            [275, 'interlab', 'protheus'],
            [276, 'piso-clean', 'protheus'],
            [277, 'norsul', 'protheus'],
            [278, 'bizify', 'protheus'],
            [279, 'ducoprint', 'protheus'],
            [280, 'west-brasil', 'protheus'],
            [281, 'acipar-lubrificantes', 'protheus'],
            [282, 'fabritech-asx', 'protheus'],
            [283, 'plastirrico', 'protheus'],
            [309, 'amino-quimica', 'protheus'],
            [310, 'castrillon', 'protheus'],
            [311, 'fortgreen', 'protheus'],
            [312, 'cimsal', 'protheus'],
            [313, 'americo', 'protheus'],
            [314, 'fm-rodrigues', 'protheus'],
            [315, 'bolognesi-energia', 'protheus'],
        ];
        $now = now();
        foreach ($map as [$customerId, $repo, $tipo]) {
            if (! DB::table('customers')->where('id', $customerId)->exists()) {
                continue;
            }
            $exists = DB::table('client_source_repos')
                ->where('customer_id', $customerId)
                ->where('owner', 'erpserv-clientes')
                ->where('repository', $repo)
                ->whereNull('deleted_at')
                ->first();
            if ($exists) {
                if (! $exists->active) {
                    DB::table('client_source_repos')->where('id', $exists->id)->update(['active' => true, 'updated_at' => $now]);
                }
                continue;
            }
            DB::table('client_source_repos')->insert([
                'customer_id'  => $customerId,
                'owner'        => 'erpserv-clientes',
                'repository'   => $repo,
                'branch'       => 'main',
                'base_path'    => '',
                'tipo'         => $tipo,
                'descricao'    => self::DESC,
                'active'       => true,
                'needs_review' => false,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('client_source_repos')->where('descricao', self::DESC)->delete();
    }
};
